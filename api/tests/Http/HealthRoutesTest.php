<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Persistence\Maintenance;
use App\Persistence\TokenRepository;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v1/health : ping de santé du relais (page « Statut » du Dashboard).
 */
final class HealthRoutesTest extends AppTestCase
{
    /** @param array<string, string> $headers */
    private function ping(?string $body = '{"latency_ms":85,"pending":2,"failed":1}', array $headers = [], string $ip = self::RELAY_IP): ResponseInterface
    {
        return $this->request('POST', '/v1/health', $headers + [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
        ], $body, $ip);
    }

    private function pings(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM mailpit_health_pings');
    }

    public function testPingIsRecorded(): void
    {
        $before = microtime(true);
        $response = $this->ping(headers: ['Mailpit-Label' => 'mailpit-prod']);

        self::assertSame(200, $response->getStatusCode());
        $data = self::json($response);
        self::assertSame('ok', $data['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $data['time']);
        self::assertEqualsWithDelta($before, strtotime($data['time']), 2);

        $row = $this->pdo->query('SELECT * FROM mailpit_health_pings')->fetch();
        self::assertSame(85, (int) $row['latency_ms']);
        self::assertSame(2, (int) $row['pending']);
        self::assertSame(1, (int) $row['failed']);
        self::assertSame(self::RELAY_IP, $row['source_ip']);
        self::assertSame('mailpit-prod', $row['label']);
        self::assertSame(substr(str_replace('T', ' ', $data['time']), 0, 23), substr($row['received_at'], 0, 23), 'même heure que la réponse');
    }

    public function testNullAndMissingFields(): void
    {
        self::assertSame(200, $this->ping('{"latency_ms":null,"pending":null,"failed":null}')->getStatusCode());
        self::assertSame(200, $this->ping('{}', ['Content-Type' => 'application/json; charset=utf-8'])->getStatusCode());
        self::assertSame(200, $this->ping('{"pending":0,"version":"3"}')->getStatusCode(), 'champ inconnu ignoré');

        $rows = $this->pdo->query('SELECT latency_ms, pending, failed, label FROM mailpit_health_pings ORDER BY id')->fetchAll();
        self::assertSame([
            ['latency_ms' => null, 'pending' => null, 'failed' => null, 'label' => null],
            ['latency_ms' => null, 'pending' => null, 'failed' => null, 'label' => null],
            ['latency_ms' => null, 'pending' => 0, 'failed' => null, 'label' => null],
        ], $rows);
    }

    public function testOnlyTheRelay(): void
    {
        // Filtre IP d'abord (403), même avec le bon jeton.
        $response = $this->ping(ip: self::OTHER_IP);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'Forbidden'], self::json($response));

        // Puis le jeton (401), qui n'est lu que dans l'en-tête.
        self::assertSame(401, $this->ping(headers: ['Authorization' => 'Bearer mauvais-jeton'])->getStatusCode());
        $response = $this->request('POST', '/v1/health?token=' . self::RELAY_TOKEN, ['Content-Type' => 'application/json'], '{}');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));

        // Un jeton développeur ne suffit pas, et GET n'existe pas.
        $developer = new TokenRepository($this->database)->create('Projet A')['token'];
        self::assertSame(401, $this->ping(headers: ['Authorization' => 'Bearer ' . $developer])->getStatusCode());
        self::assertSame(405, $this->request('GET', '/v1/health', ['Authorization' => 'Bearer ' . self::RELAY_TOKEN])->getStatusCode());
        self::assertSame(0, $this->pings());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidPings(): iterable
    {
        yield 'JSON invalide'          => ['{"latency_ms":', 400, 'JSON invalide'];
        yield 'tableau'                => ['[1, 2]', 422, 'objet JSON attendu'];
        yield 'texte'                  => ['"ok"', 422, 'objet JSON attendu'];
        yield 'latence négative'       => ['{"latency_ms":-1}', 422, '« latency_ms » doit être un entier de 0 à 3600000, ou null'];
        yield 'latence décimale'       => ['{"latency_ms":12.5}', 422, '« latency_ms » doit être un entier'];
        yield 'latence trop grande'    => ['{"latency_ms":3600001}', 422, '« latency_ms » doit être un entier'];
        yield 'en attente en texte'    => ['{"pending":"3"}', 422, '« pending » doit être un entier de 0 à 1000000000, ou null'];
        yield 'refusés booléen'        => ['{"failed":true}', 422, '« failed » doit être un entier'];
        yield 'trop profond'           => ['{"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":{}}}}}}}}}', 400, 'JSON invalide'];
    }

    #[DataProvider('invalidPings')]
    public function testInvalidPing(string $body, int $status, string $error): void
    {
        $response = $this->ping($body);

        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString($error, self::json($response)['error']);
        self::assertSame(0, $this->pings());
    }

    public function testWrongContentTypeAndTooLarge(): void
    {
        $response = $this->ping(headers: ['Content-Type' => 'text/plain']);
        self::assertSame(415, $response->getStatusCode());

        $response = $this->ping('{"latency_ms":1,"x":"' . str_repeat('a', 4096) . '"}');
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(['error' => 'corps de requête trop volumineux'], self::json($response));

        $response = $this->ping(headers: ['Content-Length' => '5000']);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $this->pings());
    }

    public function testPingsOlderThan48HoursAreDeleted(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO mailpit_health_pings (received_at, latency_ms, source_ip) VALUES (UTC_TIMESTAMP(6) - INTERVAL ? MINUTE, 50, '203.0.113.10')");
        $insert->execute([49 * 60]);
        $insert->execute([47 * 60]);

        self::assertSame(200, $this->ping()->getStatusCode());

        $ages = $this->pdo->query('SELECT TIMESTAMPDIFF(HOUR, received_at, UTC_TIMESTAMP(6)) FROM mailpit_health_pings ORDER BY received_at')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([47, 0], array_map('intval', $ages));
    }

    public function testPingRunsTheMaintenanceWithoutEverFailing(): void
    {
        $config = $this->config();
        $config['webhook']['max_age_days'] = 7;
        $this->useConfig($config);

        // Un mail de 8 jours, enregistré juste après un nettoyage : le ping suivant (15 min plus tard) le supprime.
        file_put_contents($this->storageDir . '/.maintenance', (string) (time() - 60));
        $this->postMessage(self::payload('vieux', created: gmdate('Y-m-d\TH:i:s\Z', time() - 8 * 86400)));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));
        file_put_contents($this->storageDir . '/.maintenance', (string) (time() - Maintenance::INTERVAL - 1));

        self::assertSame(200, $this->ping()->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));

        // Nettoyage impossible : le ping est quand même accepté.
        unlink($this->storageDir . '/.maintenance');
        mkdir($this->storageDir . '/.maintenance');
        $log = TempDir::logFile();
        $before = is_file($log) ? (int) filesize($log) : 0;

        self::assertSame(200, $this->ping()->getStatusCode());
        self::assertSame(2, $this->pings());
        self::assertStringContainsString('[maintenance] nettoyage impossible', (string) file_get_contents($log, offset: $before));
    }
}
