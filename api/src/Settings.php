<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Configuration validée, construite à partir du tableau retourné par config.php.
 */
final readonly class Settings
{
    /** Longueur minimale du jeton partagé avec le relais (openssl rand -hex 32 = 64 caractères). */
    public const int MIN_TOKEN_LENGTH = 32;

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
        public string $token,
        public int $maxBodySize,
        public int $maxPartSize,
        public int $maxRawSize,
        public string $storageDir,
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

        // Le jeton est obligatoire : il est configuré des deux côtés (API et relais).
        $token = $webhook['token'] ?? null;
        if (!is_string($token) || strlen(trim($token)) < self::MIN_TOKEN_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                '« webhook.token » est obligatoire et doit faire au moins %d caractères (openssl rand -hex 32)',
                self::MIN_TOKEN_LENGTH,
            ));
        }

        // « raw_dir » : ancien nom de « storage_dir », encore accepté.
        $storageDir = $webhook['storage_dir'] ?? $webhook['raw_dir'] ?? dirname(__DIR__) . '/var/messages';

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
            token: trim($token),
            maxBodySize: self::size($webhook, 'max_body_size', 16 * 1024 * 1024),
            maxPartSize: self::size($webhook, 'max_attachment_size', 10 * 1024 * 1024),
            maxRawSize: self::size($webhook, 'max_raw_size', 25 * 1024 * 1024),
            storageDir: rtrim((string) $storageDir, '/'),
            trustedProxies: self::stringList($proxy['trusted_proxies'] ?? [], 'proxy.trusted_proxies'),
            proxyHeader: (string) ($proxy['header'] ?? 'X-Forwarded-For'),
        );
    }

    private static function size(array $section, string $key, int $default): int
    {
        $value = $section[$key] ?? $default;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('« webhook.%s » doit être un nombre d\'octets positif', $key));
        }

        return $value;
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
