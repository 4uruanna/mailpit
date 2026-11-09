<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\TestCase;
use Relay\Tests\Support\FakeState;
use Relay\Tests\Support\PhpServer;
use RuntimeException;

/**
 * bin/relay.php lancé comme dans le conteneur : il démarre le récepteur et le ping de santé
 * (bin/heartbeat.php), les surveille, et les arrête avec lui.
 */
final class RelayProcessTest extends TestCase
{
    private const string TOKEN = 'jeton-du-relais-0123456789abcdef0123456789abcdef';

    private FakeState $state;
    private PhpServer $mailpit;
    private PhpServer $api;
    private string $log = '';

    /** @var resource|null */
    private $relay = null;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill') || !is_dir('/proc/self')) {
            self::markTestSkipped('extensions pcntl et posix, et /proc (Linux) nécessaires');
        }

        $this->state = FakeState::create([
            'mailpit'  => ['auth' => null, 'messages' => []],
            'api'      => ['token' => self::TOKEN, 'health_failures' => 0, 'pings' => [], 'messages' => [], 'parts' => []],
            'requests' => [],
        ]);
        $env = ['FAKE_STATE' => $this->state->file];
        $this->mailpit = new PhpServer(__DIR__ . '/Fixtures/mailpit-router.php', $env);
        $this->api = new PhpServer(__DIR__ . '/Fixtures/api-router.php', $env);
        $this->log = (string) tempnam(sys_get_temp_dir(), 'relay-log-');
    }

    protected function tearDown(): void
    {
        if ($this->relay !== null) {
            proc_terminate($this->relay, 9);
            proc_close($this->relay);
        }
        if (isset($this->state)) {
            $this->mailpit->stop();
            $this->api->stop();
            $this->state->delete();
            @unlink($this->log);
        }
    }

    public function testHeartbeatRunsBesideTheWorkerAndStopsTheRelayIfItDies(): void
    {
        $listen = $this->start(['RELAY_HEARTBEAT_INTERVAL' => '1']);

        $this->waitFor(fn (): bool => count($this->state->read()['api']['pings']) >= 2, 'deux pings de santé');
        $ping = $this->state->read()['api']['pings'][1];
        self::assertSame([0, 0, 'test-relay'], [$ping['pending'], $ping['failed'], $ping['_label']]);
        self::assertIsInt($ping['latency_ms']);

        // Le ping de santé s'arrête (plantage) : le relais quitte en erreur, avec le récepteur.
        $heartbeat = $this->child('bin/heartbeat.php');
        self::assertNotNull($heartbeat, 'processus du ping de santé');
        posix_kill($heartbeat, 9);

        self::assertSame(1, $this->waitForExit());
        self::assertStringContainsString('ERROR le ping de santé s\'est arrêté', (string) file_get_contents($this->log));
        self::assertFalse(@fsockopen('127.0.0.1', $listen, $errorCode, $errorMessage, 0.5), 'récepteur arrêté');
    }

    public function testSigtermStopsTheHeartbeatToo(): void
    {
        $this->start(['RELAY_HEARTBEAT_INTERVAL' => '1']);
        $this->waitFor(fn (): bool => $this->state->read()['api']['pings'] !== [], 'un ping de santé');
        $heartbeat = $this->child('bin/heartbeat.php');
        self::assertNotNull($heartbeat);

        proc_terminate($this->relay);

        self::assertSame(0, $this->waitForExit());
        self::assertStringContainsString('INFO  arrêt demandé', (string) file_get_contents($this->log));
        $this->waitFor(static fn (): bool => !self::alive($heartbeat), 'arrêt du ping de santé');
    }

    public function testHeartbeatCanBeDisabled(): void
    {
        $this->start(['RELAY_HEARTBEAT_INTERVAL' => '0']);
        $this->waitFor(fn (): bool => str_contains((string) file_get_contents($this->log), 'démarrage'), 'démarrage du relais');
        // Le Worker a bien tourné (recherche dans Mailpit), sans ping de santé.
        $this->waitFor(fn (): bool => $this->state->read()['requests'] !== [], 'une recherche du Worker');

        self::assertStringContainsString('ping de santé désactivé', (string) file_get_contents($this->log));
        self::assertNull($this->child('bin/heartbeat.php'));
        self::assertSame([], $this->state->read()['api']['pings']);
    }

    /**
     * Lance bin/relay.php avec les faux serveurs. Renvoie le port du récepteur.
     *
     * @param array<string, string> $env
     */
    private function start(array $env): int
    {
        $listen = PhpServer::freePort();
        $relay = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/relay.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->log, 'w']],
            $pipes,
            null,
            [
                ...array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'RELAY_'), ARRAY_FILTER_USE_KEY),
                'RELAY_API_URL'       => $this->api->url(),
                'RELAY_API_TOKEN'     => self::TOKEN,
                'RELAY_MAILPIT_URL'   => $this->mailpit->url(),
                'RELAY_LISTEN'        => '127.0.0.1:' . $listen,
                'RELAY_LABEL'         => 'test-relay',
                'RELAY_POLL_INTERVAL' => '1',
                ...$env,
            ],
        );
        if ($relay === false) {
            throw new RuntimeException('impossible de lancer bin/relay.php');
        }
        $this->relay = $relay;

        return $listen;
    }

    /** Attend la fin de bin/relay.php (10 secondes au plus) et renvoie son code de sortie. */
    private function waitForExit(): int
    {
        $deadline = microtime(true) + 10;
        do {
            $status = proc_get_status($this->relay);
            if (!$status['running']) {
                proc_close($this->relay);
                $this->relay = null;

                return $status['exitcode'];
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail("bin/relay.php ne s'est pas arrêté :\n" . file_get_contents($this->log));
    }

    private function waitFor(callable $condition, string $what): void
    {
        $deadline = microtime(true) + 10;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail(sprintf("attente trop longue : %s\n%s", $what, file_get_contents($this->log)));
            }
            usleep(50_000);
        }
    }

    /** PID d'un processus enfant de bin/relay.php dont la ligne de commande contient $script, ou null. */
    private function child(string $script): ?int
    {
        $parent = proc_get_status($this->relay)['pid'];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
            $stat = (string) @file_get_contents($file);
            // pid (nom) état ppid … : le nom peut contenir des espaces, on lit après la dernière « ) ».
            $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            if ((int) ($fields[1] ?? 0) !== $parent) {
                continue;
            }
            $pid = (int) basename(dirname($file));
            if (str_contains(str_replace("\0", ' ', (string) @file_get_contents('/proc/' . $pid . '/cmdline')), $script)) {
                return $pid;
            }
        }

        return null;
    }

    /** Processus en vie (ni terminé, ni zombie en attente de son parent). */
    private static function alive(int $pid): bool
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        return $stat !== false && substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z';
    }
}
