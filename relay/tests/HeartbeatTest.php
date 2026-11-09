<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\TestCase;
use Relay\Tests\Support\FakeState;
use Relay\Tests\Support\PhpServer;
use RuntimeException;

/**
 * Ping de santé (Relay\Heartbeat) contre un faux Mailpit et une fausse API, lancé dans un processus
 * séparé (tests/Fixtures/run-heartbeat.php) dont les logs sont vérifiés.
 */
final class HeartbeatTest extends TestCase
{
    private const string TOKEN = 'jeton-du-relais-0123456789abcdef0123456789abcdef';
    private const string MAILPIT_AUTH = 'admin:MotDePasseSolide';

    private FakeState $state;
    private PhpServer $mailpit;
    private PhpServer $api;

    protected function setUp(): void
    {
        $this->state = FakeState::create([
            'mailpit'  => ['auth' => self::MAILPIT_AUTH, 'messages' => [
                'm1' => ['ID' => 'm1', 'Tags' => []],
                'm2' => ['ID' => 'm2', 'Tags' => ['relayed']],
                'm3' => ['ID' => 'm3', 'Tags' => ['important', 'relay-failed']],
                'm4' => ['ID' => 'm4', 'Tags' => ['important']],
            ]],
            'api'      => ['token' => self::TOKEN, 'health_failures' => 0, 'pings' => []],
            'requests' => [],
        ]);

        $env = ['FAKE_STATE' => $this->state->file];
        $this->mailpit = new PhpServer(__DIR__ . '/Fixtures/mailpit-router.php', $env);
        $this->api = new PhpServer(__DIR__ . '/Fixtures/api-router.php', $env);
    }

    protected function tearDown(): void
    {
        $this->mailpit->stop();
        $this->api->stop();
        $this->state->delete();
    }

    public function testPingCarriesThePreviousLatencyAndTheCounters(): void
    {
        $run = $this->runHeartbeat(2);

        self::assertSame([true, true], $run['results']);
        self::assertSame('', $run['log'], 'rien dans le journal quand tout va bien');

        // 1er ping : pas encore de latence ; 2e : la durée du 1er. 2 mails en attente (m1, m4), 1 refusé (m3).
        $pings = $this->state->read()['api']['pings'];
        self::assertCount(2, $pings);
        self::assertSame(['latency_ms' => null, 'pending' => 2, 'failed' => 1, '_label' => 'test-relay', '_type' => 'application/json'], $pings[0]);
        self::assertIsInt($pings[1]['latency_ms']);
        self::assertGreaterThanOrEqual(0, $pings[1]['latency_ms']);
        self::assertLessThan(10_000, $pings[1]['latency_ms']);
        self::assertSame([2, 1], [$pings[1]['pending'], $pings[1]['failed']]);

        // Mêmes recherches que bin/status.php, un seul résultat lu (seul le total compte).
        $searches = array_values(array_filter($this->state->read()['requests'], static fn (array $request): bool => $request['server'] === 'mailpit'));
        self::assertSame(['!tag:relayed !tag:relay-failed', 'tag:relay-failed'], [$searches[0]['query']['query'], $searches[1]['query']['query']]);
        self::assertSame(['1', '1'], [$searches[0]['query']['limit'], $searches[1]['query']['limit']]);
        $health = array_values(array_filter($this->state->read()['requests'], static fn (array $request): bool => $request['server'] === 'api'));
        self::assertSame('POST /v1/health', $health[0]['method'] . ' ' . $health[0]['path']);
    }

    public function testMailpitUnreachableStillPings(): void
    {
        $run = $this->runHeartbeat(3, ['RELAY_MAILPIT_URL' => 'http://127.0.0.1:' . PhpServer::freePort()]);

        self::assertSame([true, true, true], $run['results']);
        foreach ($this->state->read()['api']['pings'] as $ping) {
            self::assertNull($ping['pending']);
            self::assertNull($ping['failed']);
        }
        self::assertSame(1, substr_count($run['log'], 'ping de santé envoyé sans les compteurs : Mailpit /api/v1/search'), $run['log']);
    }

    public function testFailuresAreLoggedOnceThenEvery10MinutesThenOnRecovery(): void
    {
        // 4 pings refusés (503), un toutes les 4 minutes, puis l'API répond de nouveau.
        $this->state->update(static function (array &$state): void {
            $state['api']['health_failures'] = 4;
        });

        $run = $this->runHeartbeat(6, ['HEARTBEAT_CLOCK_STEP' => '240']);

        self::assertSame([false, false, false, false, true, true], $run['results']);
        $lines = array_values(array_filter(explode("\n", $run['log'])));
        self::assertCount(3, $lines, $run['log']);
        // 0 min : premier échec.
        self::assertStringContainsString('WARN  ping de santé non enregistré : API : HTTP 503 {"error":"erreur simulée"} (rappel toutes les 10 minutes', $lines[0]);
        // 12 min : rappel (4 min et 8 min : rien).
        self::assertStringContainsString('WARN  ping de santé non enregistré : API : HTTP 503', $lines[1]);
        self::assertStringContainsString('(en échec depuis 12 min, 4 essais)', $lines[1]);
        // 16 min : rétabli.
        self::assertStringContainsString('INFO  ping de santé de nouveau accepté par l\'API, après 16 min d\'échec (4 essai(s) en échec)', $lines[2]);

        // Le premier ping accepté n'a pas de latence : le précédent a échoué.
        $pings = $this->state->read()['api']['pings'];
        self::assertCount(2, $pings);
        self::assertNull($pings[0]['latency_ms']);
        self::assertIsInt($pings[1]['latency_ms']);
    }

    public function testWrongTokenIsLogged(): void
    {
        $run = $this->runHeartbeat(1, ['RELAY_API_TOKEN' => str_repeat('x', 40)]);

        self::assertSame([false], $run['results']);
        self::assertStringContainsString('ping de santé non enregistré : API : HTTP 401', $run['log']);
        self::assertStringNotContainsString(str_repeat('x', 40), $run['log'], 'jamais le jeton');
    }

    public function testApiUnreachable(): void
    {
        $run = $this->runHeartbeat(1, ['RELAY_API_URL' => 'http://127.0.0.1:' . PhpServer::freePort()]);

        self::assertSame([false], $run['results']);
        self::assertStringContainsString('ping de santé non enregistré : API injoignable : ', $run['log']);
    }

    /**
     * @param array<string, string> $env
     * @return array{results: list<bool>, log: string}
     */
    private function runHeartbeat(int $beats, array $env = []): array
    {
        $stdout = (string) tempnam(sys_get_temp_dir(), 'relay-out-');
        $stderr = (string) tempnam(sys_get_temp_dir(), 'relay-err-');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/run-heartbeat.php', (string) $beats],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            null,
            [
                // Sans les RELAY_… de l'environnement (shell du développeur) : seuls ceux du test comptent.
                ...array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'RELAY_'), ARRAY_FILTER_USE_KEY),
                'RELAY_API_URL'      => $this->api->url(),
                'RELAY_API_TOKEN'    => self::TOKEN,
                'RELAY_MAILPIT_URL'  => $this->mailpit->url(),
                'RELAY_MAILPIT_AUTH' => self::MAILPIT_AUTH,
                'RELAY_LABEL'        => 'test-relay',
                ...$env,
            ],
        );
        if ($process === false) {
            throw new RuntimeException('impossible de lancer run-heartbeat.php');
        }
        $exitCode = proc_close($process);

        $output = (string) file_get_contents($stdout);
        $log = (string) file_get_contents($stderr);
        @unlink($stdout);
        @unlink($stderr);

        self::assertSame(0, $exitCode, "run-heartbeat.php a échoué :\n" . $output . $log);
        $results = json_decode($output, true);
        self::assertIsArray($results, "sortie inattendue de run-heartbeat.php :\n" . $output . $log);

        return ['results' => $results, 'log' => $log];
    }
}
