<?php

declare(strict_types=1);

namespace App\Tests\Unit\Log;

use App\Log\LogRotation;
use App\Log\LogRotator;
use App\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class LogRotatorTest extends TestCase
{
    private const int MAX_SIZE = 1000;

    private string $dir = '';
    private string $log = '';
    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->dir = TempDir::create('mailpit-api-log-');
        $this->log = $this->dir . '/api.log';
        // start() redirige error_log() : la destination des tests est rétablie après chaque test.
        $this->errorLog = (string) ini_get('error_log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        TempDir::remove($this->dir);
    }

    public function testFileUnderTheLimitIsLeftAlone(): void
    {
        file_put_contents($this->log, str_repeat('a', self::MAX_SIZE - 1));

        self::assertSame(LogRotation::NotNeeded, $this->rotator()->rotate());
        self::assertSame(self::MAX_SIZE - 1, filesize($this->log));
        self::assertSame(['api.log'], TempDir::files($this->dir), 'ni archive ni verrou');
    }

    public function testFileAtTheLimitIsArchived(): void
    {
        $content = str_repeat("ligne\n", 200);
        file_put_contents($this->log, $content);
        self::assertGreaterThanOrEqual(self::MAX_SIZE, strlen($content));

        self::assertSame(LogRotation::Rotated, $this->rotator()->rotate());
        self::assertFileDoesNotExist($this->log, 'le log repart de zéro, à la prochaine écriture');
        self::assertSame($content, file_get_contents($this->log . '.1'));

        // Juste après : rien à faire, l'archive est gardée telle quelle.
        self::assertSame(LogRotation::NotNeeded, $this->rotator()->rotate());
        self::assertSame($content, file_get_contents($this->log . '.1'));
    }

    public function testPreviousArchiveIsReplaced(): void
    {
        file_put_contents($this->log . '.1', 'ancienne archive');
        file_put_contents($this->log, str_repeat('b', self::MAX_SIZE));

        self::assertSame(LogRotation::Rotated, $this->rotator()->rotate());
        self::assertSame(str_repeat('b', self::MAX_SIZE), file_get_contents($this->log . '.1'));
        self::assertSame(['api.log.1', 'api.log.lock'], TempDir::files($this->dir), 'une seule archive');
    }

    public function testMissingFileIsNotCreated(): void
    {
        self::assertSame(LogRotation::NotNeeded, $this->rotator()->rotate());
        self::assertSame([], TempDir::files($this->dir));

        // Dossier absent : pas d'erreur non plus.
        self::assertSame(LogRotation::NotNeeded, new LogRotator($this->dir . '/absent/api.log', self::MAX_SIZE)->rotate());
    }

    public function testWithoutLogFileNothingHappens(): void
    {
        $rotator = new LogRotator(null, self::MAX_SIZE);

        self::assertSame(LogRotation::NotNeeded, $rotator->rotate());
        self::assertSame(LogRotation::NotNeeded, $rotator->start());
        self::assertNull($rotator->archive());
        self::assertSame($this->errorLog, ini_get('error_log'), 'log par défaut de l\'hébergement inchangé');
    }

    public function testZeroDisablesRotation(): void
    {
        file_put_contents($this->log, str_repeat('c', 5000));

        self::assertSame(LogRotation::NotNeeded, new LogRotator($this->log, 0)->rotate());
        self::assertSame(['api.log'], TempDir::files($this->dir));
    }

    public function testDefaultLimitIsTenMegabytes(): void
    {
        self::assertSame(10 * 1024 * 1024, LogRotator::DEFAULT_MAX_SIZE);

        // Un log de 1 Mo est gardé avec la limite par défaut.
        file_put_contents($this->log, str_repeat('d', 1024 * 1024));
        self::assertSame(LogRotation::NotNeeded, new LogRotator($this->log)->rotate());
    }

    public function testLockedByAnotherRequestIsSkipped(): void
    {
        file_put_contents($this->log, str_repeat('e', self::MAX_SIZE));
        $lock = fopen($this->log . '.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            self::assertSame(LogRotation::Busy, $this->rotator()->rotate(), 'verrou non bloquant : la requête n\'attend pas');
            self::assertFileDoesNotExist($this->log . '.1');
            self::assertSame(self::MAX_SIZE, filesize($this->log));
        } finally {
            fclose($lock);
        }

        self::assertSame(LogRotation::Rotated, $this->rotator()->rotate(), 'verrou libéré');
    }

    public function testRenameFailureIsReportedWithoutException(): void
    {
        // Un dossier non vide à la place de l'archive : rename() échoue (comme sans droits d'écriture).
        mkdir($this->log . '.1');
        file_put_contents($this->log . '.1/x', 'x');
        file_put_contents($this->log, str_repeat('f', self::MAX_SIZE));

        self::assertSame(LogRotation::Failed, $this->rotator()->rotate());
        self::assertSame(self::MAX_SIZE, filesize($this->log), 'le log continue dans le même fichier');
    }

    public function testLockFailureIsReportedWithoutException(): void
    {
        mkdir($this->log . '.lock');
        file_put_contents($this->log, str_repeat('g', self::MAX_SIZE));

        self::assertSame(LogRotation::Failed, $this->rotator()->rotate());
        self::assertFileDoesNotExist($this->log . '.1');
    }

    public function testStartArchivesThenLogsToTheNewFile(): void
    {
        file_put_contents($this->log, str_repeat('h', self::MAX_SIZE));

        self::assertSame(LogRotation::Rotated, $this->rotator()->start());
        self::assertSame($this->log, ini_get('error_log'));

        error_log('[test] première ligne du nouveau fichier');
        self::assertStringContainsString('[test] première ligne du nouveau fichier', (string) file_get_contents($this->log));
        self::assertLessThan(200, filesize($this->log));
        self::assertSame(str_repeat('h', self::MAX_SIZE), file_get_contents($this->log . '.1'));
    }

    public function testStartLogsAFailedRotation(): void
    {
        mkdir($this->log . '.1');
        file_put_contents($this->log . '.1/x', 'x');
        file_put_contents($this->log, str_repeat('i', self::MAX_SIZE));

        self::assertSame(LogRotation::Failed, $this->rotator()->start());
        self::assertStringContainsString(
            sprintf('[log] archivage de « %s » en « %s.1 » impossible : le dossier doit être accessible en écriture par PHP', $this->log, $this->log),
            (string) file_get_contents($this->log),
        );
    }

    /**
     * Plusieurs requêtes simultanées sur un log plein : une seule l'archive, les autres voient le
     * nouveau fichier (taille vérifiée de nouveau sous le verrou) ou le verrou pris. L'archive n'est
     * jamais remplacée par le nouveau fichier, et aucune ligne n'est perdue.
     */
    public function testConcurrentRequestsArchiveOnlyOnce(): void
    {
        $content = str_repeat('j', self::MAX_SIZE);
        file_put_contents($this->log, $content);

        $count = 8;
        $startAt = microtime(true) + 0.5;
        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/rotate-log.php', $this->log, (string) self::MAX_SIZE, (string) $startAt],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $errors);
        }

        $counts = array_count_values($results);
        self::assertSame(1, $counts['Rotated'] ?? 0, implode(', ', $results));
        self::assertArrayNotHasKey('Failed', $counts, implode(', ', $results));
        // Une requête qui écrit juste avant le renommage écrit à la fin de l'archive : c'est normal.
        $archive = (string) file_get_contents($this->log . '.1');
        self::assertStringStartsWith($content, $archive, 'archive = l\'ancien log, jamais le nouveau fichier');
        $lines = substr_count(substr($archive, strlen($content)), "\n") + substr_count((string) file_get_contents($this->log), "\n");
        self::assertSame($count, $lines, 'aucune ligne perdue');
    }

    private function rotator(): LogRotator
    {
        return new LogRotator($this->log, self::MAX_SIZE);
    }
}
