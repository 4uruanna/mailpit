<?php

declare(strict_types=1);

namespace Relay\Tests\Support;

use RuntimeException;

/**
 * Serveur intégré de PHP (« php -S 127.0.0.1:<port libre> router.php ») lancé pour un test.
 */
final class PhpServer
{
    /** @var resource|null */
    private $process;

    public readonly int $port;

    /**
     * @param string $router script appelé pour chaque requête
     * @param array<string, string> $env variables d'environnement du serveur
     */
    public function __construct(string $router, array $env = [])
    {
        $this->port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $this->port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname($router),
            [...getenv(), ...$env],
        );
        if ($process === false) {
            throw new RuntimeException('impossible de lancer php -S');
        }
        $this->process = $process;

        // Attend que le serveur accepte les connexions.
        $deadline = microtime(true) + 10;
        while (true) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                $this->stop();
                throw new RuntimeException(sprintf('php -S %s ne répond pas sur le port %d', basename($router), $this->port));
            }
            usleep(20_000);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    /** Port TCP libre sur 127.0.0.1 (choisi par le système). */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('aucun port libre : ' . $errorMessage);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
