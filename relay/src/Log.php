<?php

declare(strict_types=1);

namespace Relay;

/**
 * Logs sur la sortie d'erreur : Podman les transmet à journald
 * (journalctl -u mailpit-relay).
 */
final class Log
{
    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        file_put_contents('php://stderr', sprintf("[relay] %-5s %s\n", $level, $message));
    }
}
