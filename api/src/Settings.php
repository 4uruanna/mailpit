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
        /** Nombre de mails gardés (les plus anciens sont supprimés), 0 = sans limite. */
        public int $maxMessages,
        public string $storageDir,
        public array $trustedProxies,
        public string $proxyHeader,
        /** Dashboard : déconnexion après ce nombre de secondes d'inactivité. */
        public int $sessionLifetime = 7200,
        /** Dashboard : fuseau horaire d'affichage des dates. */
        public string $timezone = 'Europe/Paris',
        /** HTTPS obligatoire (redirection, 403, HSTS) : false seulement en développement local. */
        public bool $forceHttps = true,
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

        $storageDir = $webhook['storage_dir'] ?? dirname(__DIR__) . '/var/messages';

        $dashboard = $config['app'] ?? [];
        $sessionLifetime = $dashboard['session_lifetime'] ?? 7200;
        if (!is_int($sessionLifetime) || $sessionLifetime < 60) {
            throw new InvalidArgumentException('« app.session_lifetime » doit être un nombre de secondes (60 minimum)');
        }
        $timezone = (string) ($dashboard['timezone'] ?? 'Europe/Paris');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(sprintf('« app.timezone » : fuseau horaire inconnu « %s »', $timezone));
        }

        $forceHttps = $config['force_https'] ?? true;
        if (!is_bool($forceHttps)) {
            throw new InvalidArgumentException('« force_https » doit valoir true ou false');
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
            token: trim($token),
            maxBodySize: self::size($webhook, 'max_body_size', 16 * 1024 * 1024),
            maxPartSize: self::size($webhook, 'max_attachment_size', 5 * 1024 * 1024),
            maxMessages: self::maxMessages($webhook),
            storageDir: rtrim((string) $storageDir, '/'),
            trustedProxies: self::stringList($proxy['trusted_proxies'] ?? [], 'proxy.trusted_proxies'),
            proxyHeader: (string) ($proxy['header'] ?? 'X-Forwarded-For'),
            sessionLifetime: $sessionLifetime,
            timezone: $timezone,
            forceHttps: $forceHttps,
        );
    }

    private static function maxMessages(array $section): int
    {
        $value = $section['max_messages'] ?? 1000;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('« webhook.max_messages » doit être un entier positif (0 = sans limite)');
        }

        return $value;
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
