<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\TestCase;
use Relay\Http;
use Relay\Receiver;
use Relay\Tests\Support\PhpServer;
use RuntimeException;

/**
 * Récepteur de webhook (public/index.php), lancé comme dans le conteneur : php -S … public/index.php.
 */
final class ReceiverTest extends TestCase
{
    private const string ROUTER = __DIR__ . '/../public/index.php';

    public function testRoutes(): void
    {
        $server = new PhpServer(self::ROUTER);

        self::assertSame(['status' => 200, 'body' => '{"status":"ok"}', 'error' => null], Http::request('GET', $server->url() . '/health'));
        self::assertSame(['status' => 202, 'body' => '{"status":"accepted"}', 'error' => null], Http::request('POST', $server->url() . '/hook', [], '{"ID":"x"}'));
        self::assertSame(405, Http::request('GET', $server->url() . '/hook')['status']);
        self::assertSame(405, Http::request('POST', $server->url() . '/health')['status']);
        self::assertSame(['status' => 404, 'body' => '{"error":"Not Found"}', 'error' => null], Http::request('GET', $server->url() . '/'));

        $server->stop();
    }

    public function testHookWakesTheWorkerWithSigusr1(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('extension posix nécessaire');
        }
        // Constante de l'extension pcntl ; 10 sous Linux (même repli que Receiver).
        $sigusr1 = defined('SIGUSR1') ? SIGUSR1 : 10;

        // Faux worker : un processus PHP sans gestionnaire de signal, que SIGUSR1 arrête.
        $worker = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
        if ($worker === false) {
            throw new RuntimeException('impossible de lancer le faux worker');
        }
        $server = new PhpServer(self::ROUTER, [Receiver::WORKER_PID_ENV => (string) proc_get_status($worker)['pid']]);

        self::assertSame(202, Http::request('POST', $server->url() . '/hook')['status']);

        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($worker);
            usleep(20_000);
        } while ($status['running'] && microtime(true) < $deadline);

        $server->stop();
        if ($status['running']) {
            proc_terminate($worker, 9);
        }
        proc_close($worker);

        self::assertFalse($status['running'], 'le faux worker n\'a pas reçu de signal');
        self::assertTrue($status['signaled']);
        self::assertSame($sigusr1, $status['termsig']);
    }
}
