<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Configuration validée, construite à partir du tableau retourné par config.php.
 */
final readonly class Settings
{
    /**
     * @param list<string> $allowedIps
     * @param list<string> $trustedProxies
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     */
    public function __construct(
        public bool $debug,
        public string $basePath,
        public ?string $logFile,
        public array $db,
        public array $allowedIps,
        public ?string $token,
        public int $maxBodySize,
        public string $rawDir,
        public array $trustedProxies,
        public string $proxyHeader,
    ) {
    }

    public static function fromArray(array $config): self
    {
        $db = $config['db'] ?? null;
        if (!is_array($db)) {
            throw new InvalidArgumentException('la section « db » est manquante');
        }
        foreach (['host', 'name', 'user'] as $key) {
            if (!is_string($db[$key] ?? null) || $db[$key] === '') {
                throw new InvalidArgumentException(sprintf('« db.%s » est manquant', $key));
            }
        }

        $webhook = $config['webhook'] ?? [];
        $proxy = $config['proxy'] ?? [];

        $basePath = rtrim((string) ($config['base_path'] ?? ''), '/');
        if ($basePath !== '' && !str_starts_with($basePath, '/')) {
            $basePath = '/' . $basePath;
        }

        $token = $webhook['token'] ?? null;
        if ($token !== null && $token !== '') {
            if (!is_string($token) || strlen($token) < 16) {
                throw new InvalidArgumentException('« webhook.token » doit faire au moins 16 caractères');
            }
        } else {
            $token = null;
        }

        return new self(
            debug: (bool) ($config['debug'] ?? false),
            basePath: $basePath,
            logFile: isset($config['log_file']) ? (string) $config['log_file'] : null,
            db: [
                'host'     => $db['host'],
                'port'     => (int) ($db['port'] ?? 3306),
                'name'     => $db['name'],
                'user'     => $db['user'],
                'password' => (string) ($db['password'] ?? ''),
            ],
            allowedIps: self::stringList($webhook['allowed_ips'] ?? [], 'webhook.allowed_ips'),
            token: $token,
            maxBodySize: max(1, (int) ($webhook['max_body_size'] ?? 16 * 1024 * 1024)),
            rawDir: rtrim((string) ($webhook['raw_dir'] ?? dirname(__DIR__) . '/var/messages'), '/'),
            trustedProxies: self::stringList($proxy['trusted_proxies'] ?? [], 'proxy.trusted_proxies'),
            proxyHeader: (string) ($proxy['header'] ?? 'X-Forwarded-For'),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('« %s » doit être une liste', $name));
        }

        return array_values(array_map(static fn (mixed $v): string => trim((string) $v), $value));
    }
}
