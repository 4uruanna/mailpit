<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\Database;
use App\Persistence\FileStore;
use App\Persistence\Maintenance;
use App\Persistence\MaintenanceReport;
use App\Persistence\MessageRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\TempDir;

final class MaintenanceTest extends DatabaseTestCase
{
    private const int DAY = 24 * 60 * 60;

    private MessageRepository $repository;
    private FileStore $files;
    private string $storageDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MessageRepository($this->database);
        $this->storageDir = TempDir::create();
        $this->files = new FileStore($this->storageDir);
    }

    protected function tearDown(): void
    {
        if ($this->storageDir !== '') {
            TempDir::remove($this->storageDir);
        }
        parent::tearDown();
    }

    private function maintenance(int $maxAgeDays = 7, int $maxMessages = 0, int $batchSize = 200, int $maxBatches = 5): Maintenance
    {
        return new Maintenance($this->repository, $this->files, $maxAgeDays, $maxMessages, $batchSize, $maxBatches);
    }

    /**
     * Mail reçu par Mailpit il y a $ageDays jours, avec une pièce enregistrée sur le disque.
     */
    private function saveMessage(string $id, float $ageDays, bool $withFile = true): void
    {
        $created = gmdate('Y-m-d\TH:i:s\Z', (int) (time() - $ageDays * self::DAY));
        $this->repository->save(
            self::message(self::payload($id, parts: $withFile ? [['2', 5]] : [], created: $created)),
            null,
            '203.0.113.10',
            '{}',
            1000,
        );
        if ($withFile) {
            $pk = (int) $this->scalar('SELECT id FROM mailpit_messages WHERE mailpit_id = ?', [$id]);
            $this->writeFile(FileStore::partPath($id, '2'));
            $this->repository->markPartStored($pk, '2', 5, hash('sha256', 'hello'), FileStore::partPath($id, '2'));
        }
    }

    /** Écrit un fichier du dossier de stockage, daté d'il y a $ageSeconds secondes. */
    private function writeFile(string $relativePath, int $ageSeconds = 0): void
    {
        $path = $this->storageDir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, 'hello');
        touch($path, time() - $ageSeconds);
    }

    /** Date un dossier du dossier de stockage, il y a $ageSeconds secondes. */
    private function age(string $relativeDir, int $ageSeconds): void
    {
        touch($this->storageDir . '/' . $relativeDir, time() - $ageSeconds);
    }

    /** @return list<string> */
    private function ids(): array
    {
        return $this->pdo->query('SELECT mailpit_id FROM mailpit_messages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testDeletesMessagesOlderThanMaxAge(): void
    {
        $this->saveMessage('vieux', 8);
        $this->saveMessage('recent', 6);
        $this->saveMessage('vieux-sans-piece', 30, withFile: false);

        $report = $this->maintenance(maxAgeDays: 7)->run();

        self::assertSame(2, $report->expired);
        self::assertTrue($report->finished);
        self::assertSame(['recent'], $this->ids());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_parts'));
        // Base ET pièces jointes : le dossier du mail supprimé est effacé.
        self::assertSame(['recent/part-2'], TempDir::files($this->storageDir));
        self::assertDirectoryDoesNotExist($this->storageDir . '/vieux');
    }

    public function testMaxAgeZeroKeepsOldMessages(): void
    {
        $this->saveMessage('vieux', 400);

        $report = $this->maintenance(maxAgeDays: 0)->run();

        self::assertSame(0, $report->expired);
        self::assertSame(['vieux'], $this->ids());
        self::assertSame(['vieux/part-2'], TempDir::files($this->storageDir));
    }

    public function testPrunesBeyondMaxMessages(): void
    {
        foreach (['m1', 'm2', 'm3', 'm4'] as $id) {
            $this->saveMessage($id, 1);
        }

        $report = $this->maintenance(maxAgeDays: 7, maxMessages: 2)->run();

        self::assertSame(0, $report->expired);
        self::assertSame(2, $report->pruned);
        self::assertSame(['m3', 'm4'], $this->ids());
        self::assertSame(['m3/part-2', 'm4/part-2'], TempDir::files($this->storageDir));
    }

    public function testDeletesStaleUploadsOnly(): void
    {
        $this->writeFile('.upload-0123456789abcdef', Maintenance::ORPHAN_AGE + 60);
        $this->writeFile('.upload-fedcba9876543210', 60);
        $this->writeFile('.gitkeep', 10 * self::DAY);
        $this->writeFile('.htaccess', 10 * self::DAY);

        $report = $this->maintenance()->run();

        self::assertSame(1, $report->uploads);
        // Un envoi en cours garde son fichier ; les autres fichiers cachés ne sont jamais touchés.
        self::assertSame(['.gitkeep', '.htaccess', '.upload-fedcba9876543210'], TempDir::files($this->storageDir));
    }

    public function testDeletesOrphanDirectoriesAndFiles(): void
    {
        $old = Maintenance::ORPHAN_AGE + 60;
        $this->saveMessage('connu', 1);

        // Dossier d'un mail supprimé dont l'effacement a échoué : retiré.
        $this->writeFile('fantome/part-2', $old);
        $this->writeFile('fantome/part-3', $old);
        $this->age('fantome', $old);
        // Dossier vide d'un mail inconnu : retiré.
        mkdir($this->storageDir . '/vide');
        $this->age('vide', $old);
        // Dossier d'un mail inconnu, modifié il y a peu (envoi en cours ?) : gardé.
        $this->writeFile('recent/part-2', 60);
        // Fichier du mail connu rattaché à aucune pièce : retiré s'il est ancien, gardé sinon.
        $this->writeFile('connu/part-9', $old);
        $this->writeFile('connu/part-8', 60);
        $this->age('connu/part-2', $old);
        $this->age('connu', $old);
        // Ce qui n'a pas un nom de dossier de mail n'est jamais touché.
        $this->writeFile('pas.un.mail/fichier', $old);
        $this->age('pas.un.mail', $old);

        $report = $this->maintenance()->run();

        self::assertSame(2, $report->directories);
        self::assertSame(3, $report->files, 'deux fichiers du dossier orphelin, un du mail connu');
        self::assertSame(0, $report->errors);
        self::assertSame(
            ['connu/part-2', 'connu/part-8', 'pas.un.mail/fichier', 'recent/part-2'],
            TempDir::files($this->storageDir),
        );
        self::assertDirectoryDoesNotExist($this->storageDir . '/fantome');
        self::assertDirectoryDoesNotExist($this->storageDir . '/vide');
        self::assertSame(['connu'], $this->ids());
    }

    public function testKnownMessageMatchesWhateverTheCase(): void
    {
        // MySQL compare mailpit_id sans la casse : le dossier « CONNU » appartient au mail « connu ».
        $this->saveMessage('connu', 1, withFile: false);
        mkdir($this->storageDir . '/CONNU');
        $this->age('CONNU', Maintenance::ORPHAN_AGE + 60);

        self::assertSame(0, $this->maintenance()->run()->directories);
        self::assertDirectoryExists($this->storageDir . '/CONNU');
    }

    public function testRunIfDueRunsAtMostOncePerInterval(): void
    {
        $maintenance = $this->maintenance();
        $marker = $this->storageDir . '/.maintenance';

        $this->saveMessage('vieux1', 8);
        $report = $maintenance->runIfDue();
        self::assertInstanceOf(MaintenanceReport::class, $report);
        self::assertSame(1, $report->expired);
        self::assertFileExists($marker);
        self::assertEqualsWithDelta(time(), (int) file_get_contents($marker), 5);

        // Moins de 15 minutes après : rien.
        $this->saveMessage('vieux2', 8);
        self::assertNull($maintenance->runIfDue());
        self::assertSame(['vieux2'], $this->ids());

        // Dernier passage il y a plus de 15 minutes : nouveau nettoyage.
        file_put_contents($marker, (string) (time() - Maintenance::INTERVAL - 1));
        self::assertSame(1, $maintenance->runIfDue()?->expired);
        self::assertSame([], $this->ids());

        // Date dans le futur (horloge changée) ou illisible : le nettoyage n'est pas bloqué.
        file_put_contents($marker, (string) (time() + 3600));
        self::assertNotNull($maintenance->runIfDue());
        file_put_contents($marker, 'abîmé');
        self::assertNotNull($maintenance->runIfDue());

        // run() n'attend pas l'intervalle.
        self::assertNotNull($maintenance->run());
    }

    public function testRunIfDueSkipsWhileAnotherRunHoldsTheLock(): void
    {
        $this->saveMessage('vieux', 8);
        $handle = fopen($this->files->maintenanceFile(), 'c+');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX));

        try {
            // Une autre requête nettoie déjà : celle-ci n'attend pas, et ne fait rien.
            self::assertNull($this->maintenance()->runIfDue());
            self::assertSame(['vieux'], $this->ids());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::assertSame(1, $this->maintenance()->runIfDue()?->expired);
    }

    public function testUnfinishedRunIsDueAgainAtOnce(): void
    {
        foreach (['v1', 'v2', 'v3'] as $id) {
            $this->saveMessage($id, 10);
        }
        // 1 mail par paquet, 2 paquets au plus par passage.
        $maintenance = $this->maintenance(batchSize: 1, maxBatches: 2);

        $report = $maintenance->runIfDue();
        self::assertSame(2, $report?->expired);
        self::assertFalse($report?->finished);
        self::assertSame(['v3'], $this->ids());

        // Le reste est fait dès l'appel suivant, sans attendre 15 minutes.
        $report = $maintenance->runIfDue();
        self::assertSame(1, $report?->expired);
        self::assertTrue($report?->finished);
        self::assertNull($maintenance->runIfDue());

        // run() va jusqu'au bout, quelle que soit la limite.
        foreach (['w1', 'w2', 'w3'] as $id) {
            $this->saveMessage($id, 10);
        }
        self::assertSame(3, $maintenance->run()->expired);
    }

    public function testRunIfDueNeverThrows(): void
    {
        $log = TempDir::logFile();
        $before = is_file($log) ? (int) filesize($log) : 0;

        // Base injoignable.
        $unreachable = new MessageRepository(new Database(['host' => '127.0.0.1', 'port' => 1, 'name' => 'aucune', 'user' => 'u', 'password' => '']));
        self::assertNull(new Maintenance($unreachable, $this->files, 7, 1000)->runIfDue());
        self::assertStringContainsString('[maintenance] nettoyage impossible', (string) file_get_contents($log, offset: $before));

        // Fichier de date impossible à ouvrir (un dossier à sa place).
        $broken = TempDir::create();
        mkdir($broken . '/.maintenance');
        try {
            self::assertNull(new Maintenance($this->repository, new FileStore($broken), 7, 1000)->runIfDue());
        } finally {
            TempDir::remove($broken);
        }
    }

    public function testSummary(): void
    {
        $report = new MaintenanceReport(expired: 3, pruned: 0, uploads: 1, directories: 2, files: 4, errors: 1, finished: false);

        self::assertFalse($report->isEmpty());
        self::assertSame(
            'mails supprimés : 3 trop anciens, 0 au-delà de max_messages ; effacés : 1 fichiers temporaires, 2 dossiers et 4 fichiers orphelins ; 1 effacements impossibles ; la suite au prochain passage',
            $report->summary(),
        );
        self::assertTrue(new MaintenanceReport(0, 0, 0, 0, 0, 0, true)->isEmpty());
    }
}
