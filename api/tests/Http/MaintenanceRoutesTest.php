<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Persistence\Maintenance;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;

/**
 * Nettoyage automatique (Maintenance) déclenché par les requêtes du relais, sans tâche planifiée.
 */
final class MaintenanceRoutesTest extends AppTestCase
{
    private const int DAY = 24 * 60 * 60;

    protected function config(): array
    {
        $config = parent::config();
        $config['webhook']['max_age_days'] = 7;

        return $config;
    }

    private function marker(): string
    {
        return $this->storageDir . '/.maintenance';
    }

    /** Dernier nettoyage il y a $secondsAgo secondes. */
    private function lastRun(int $secondsAgo): void
    {
        file_put_contents($this->marker(), (string) (time() - $secondsAgo));
    }

    private static function created(int $secondsAgo): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);
    }

    /** @return list<string> */
    private function ids(): array
    {
        return $this->pdo->query('SELECT mailpit_id FROM mailpit_messages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testRelayRequestsRunTheMaintenanceAtMostEvery15Minutes(): void
    {
        // Nettoyage récent : un mail reçu par Mailpit il y a 8 jours (relais en retard) est enregistré.
        $this->lastRun(60);
        self::assertSame(201, $this->postMessage(self::payload('vieux', parts: [['2', 5]], created: self::created(8 * self::DAY)))->getStatusCode());
        self::assertSame(201, $this->postPart('vieux', '2', 'hello')->getStatusCode());
        self::assertSame(['vieux'], $this->ids());

        // Restes d'un envoi interrompu et d'un effacement raté, vieux de plus d'une heure.
        $old = time() - Maintenance::ORPHAN_AGE - 60;
        file_put_contents($this->storageDir . '/.upload-0123456789abcdef', 'x');
        touch($this->storageDir . '/.upload-0123456789abcdef', $old);
        mkdir($this->storageDir . '/fantome');
        file_put_contents($this->storageDir . '/fantome/part-2', 'x');
        touch($this->storageDir . '/fantome/part-2', $old);
        touch($this->storageDir . '/fantome', $old);

        // Dernier nettoyage il y a plus de 15 minutes : le mail suivant du relais le déclenche.
        $this->lastRun(Maintenance::INTERVAL + 1);
        $response = $this->postMessage(self::payload('recent', created: self::created(60)));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('created', self::json($response)['status'], 'réponse du relais inchangée');
        self::assertSame(['recent'], $this->ids());
        self::assertSame([], TempDir::files($this->storageDir), 'pièces du vieux mail, fichier temporaire et dossier orphelin effacés');
        self::assertEqualsWithDelta(time(), (int) file_get_contents($this->marker()), 5);

        // Moins de 15 minutes après : pas de nouveau nettoyage.
        self::assertSame(201, $this->postMessage(self::payload('vieux2', created: self::created(8 * self::DAY)))->getStatusCode());
        self::assertSame(['recent', 'vieux2'], $this->ids());

        // L'envoi d'une pièce le déclenche aussi.
        $this->postMessage(self::payload('m3', parts: [['2', 5]], created: self::created(60)));
        $this->lastRun(Maintenance::INTERVAL + 1);
        self::assertSame(201, $this->postPart('m3', '2', 'hello')->getStatusCode());
        self::assertSame(['recent', 'm3'], $this->ids());
    }

    public function testRejectedOrOtherRequestsDoNotRunTheMaintenance(): void
    {
        $payload = json_encode(self::payload('m1'), JSON_THROW_ON_ERROR);

        self::assertSame(401, $this->postMessage(self::payload('m1'), token: 'mauvais-jeton-0123456789abcdef0123456789')->getStatusCode());
        self::assertSame(403, $this->request('POST', '/v1/messages', ['Authorization' => 'Bearer ' . self::RELAY_TOKEN, 'Content-Type' => 'application/json'], $payload, ip: self::OTHER_IP)->getStatusCode());
        self::assertSame(401, $this->request('GET', '/v1/messages')->getStatusCode());
        self::assertFileDoesNotExist($this->marker());

        self::assertSame(201, $this->postMessage(self::payload('m1'))->getStatusCode());
        self::assertFileExists($this->marker());
    }

    public function testMaintenanceErrorDoesNotFailTheRelayRequest(): void
    {
        // Fichier de date impossible à ouvrir : le nettoyage échoue.
        mkdir($this->marker());
        $log = TempDir::logFile();
        $before = is_file($log) ? (int) filesize($log) : 0;

        $response = $this->postMessage(self::payload('m1'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['m1'], $this->ids());
        self::assertStringContainsString('[maintenance] nettoyage impossible', (string) file_get_contents($log, offset: $before));
    }

    public function testMaxAgeZeroKeepsOldMessages(): void
    {
        $config = $this->config();
        $config['webhook']['max_age_days'] = 0;
        $this->useConfig($config);

        $this->postMessage(self::payload('vieux', created: self::created(400 * self::DAY)));
        $this->lastRun(Maintenance::INTERVAL + 1);
        $this->postMessage(self::payload('recent', created: self::created(60)));

        self::assertSame(['vieux', 'recent'], $this->ids());
        self::assertGreaterThan(time() - 5, (int) file_get_contents($this->marker()), 'le nettoyage a bien eu lieu');
    }
}
