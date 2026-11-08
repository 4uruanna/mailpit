<?php

declare(strict_types=1);

namespace Relay;

/**
 * Récepteur HTTP (serveur intégré de PHP) :
 *   POST /hook    webhook de Mailpit : réveille le worker, qui cherche aussitôt les nouveaux mails
 *   GET  /health  état du récepteur
 *
 * Le contenu du webhook n'est ni lu ni enregistré : c'est un simple signal. Le worker relit
 * tout dans Mailpit. Un faux appel ne peut donc rien injecter, il déclenche juste une recherche.
 * Sans webhook (relais arrêté, appel perdu…), le worker cherche de toute façon toutes les
 * RELAY_POLL_INTERVAL secondes.
 */
final class Receiver
{
    /** Variable d'environnement qui donne le PID du worker (définie par bin/relay.php). */
    public const string WORKER_PID_ENV = 'RELAY_WORKER_PID';

    public static function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        match (true) {
            $path === '/hook' && $method === 'POST' => self::hook(),
            $path === '/health' && $method === 'GET' => self::json(200, ['status' => 'ok']),
            $path === '/hook', $path === '/health' => self::json(405, ['error' => 'Method Not Allowed']),
            default => self::json(404, ['error' => 'Not Found']),
        };
    }

    private static function hook(): void
    {
        $pid = (int) getenv(self::WORKER_PID_ENV);
        if ($pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, defined('SIGUSR1') ? SIGUSR1 : 10);
        }

        self::json(202, ['status' => 'accepted']);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
