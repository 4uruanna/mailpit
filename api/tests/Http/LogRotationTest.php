<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Settings;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;

/**
 * Rotation du log (log_max_size) au démarrage de chaque requête (Kernel::create).
 */
final class LogRotationTest extends AppTestCase
{
    private string $logDir = '';
    private string $errorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = TempDir::create('mailpit-api-log-');
        // Kernel::create redirige error_log() : la destination des tests est rétablie après chaque test.
        $this->errorLog = (string) ini_get('error_log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        TempDir::remove($this->logDir);
        parent::tearDown();
    }

    protected function config(): array
    {
        $config = parent::config();
        $config['log_file'] = $this->logDir . '/api.log';
        $config['log_max_size'] = Settings::MIN_LOG_MAX_SIZE;

        return $config;
    }

    public function testFullLogIsArchivedAndTheRequestLogsToANewFile(): void
    {
        $log = $this->logDir . '/api.log';
        $old = str_repeat("[ip-filter] ancienne ligne\n", intdiv(Settings::MIN_LOG_MAX_SIZE, 27) + 1);
        file_put_contents($log, $old);
        file_put_contents($log . '.1', 'archive précédente');

        // Jeton faux : la requête est refusée, et le refus est écrit dans le nouveau log.
        self::assertSame(401, $this->postMessage(self::payload('m1'), 'faux-jeton-0123456789abcdef0123456789')->getStatusCode());

        self::assertSame($old, file_get_contents($log . '.1'), 'l\'archive précédente est remplacée');
        $new = (string) file_get_contents($log);
        self::assertStringContainsString('[auth] jeton invalide', $new);
        self::assertStringNotContainsString('ancienne ligne', $new);
        self::assertLessThan(1024, strlen($new));
    }

    public function testLogUnderTheLimitIsKept(): void
    {
        $log = $this->logDir . '/api.log';
        file_put_contents($log, "[ip-filter] ancienne ligne\n");

        self::assertSame(401, $this->postMessage(self::payload('m1'), 'faux-jeton-0123456789abcdef0123456789')->getStatusCode());

        self::assertFileDoesNotExist($log . '.1');
        self::assertStringStartsWith("[ip-filter] ancienne ligne\n", (string) file_get_contents($log));
        self::assertStringContainsString('[auth] jeton invalide', (string) file_get_contents($log));
    }

    public function testRotationFailureDoesNotBreakTheRequest(): void
    {
        // Archive impossible (un dossier non vide à sa place, comme un dossier sans droits d'écriture).
        $log = $this->logDir . '/api.log';
        mkdir($log . '.1');
        file_put_contents($log . '.1/x', 'x');
        file_put_contents($log, str_repeat('x', Settings::MIN_LOG_MAX_SIZE));

        self::assertSame(201, $this->postMessage(self::payload('m1'))->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));
        self::assertStringContainsString('[log] archivage de « ' . $log . ' » en « ' . $log . '.1 » impossible', (string) file_get_contents($log));
    }
}
