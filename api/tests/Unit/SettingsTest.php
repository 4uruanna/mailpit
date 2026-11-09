<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Settings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    /** Configuration minimale acceptée. */
    private static function minimal(): array
    {
        return [
            'db'      => ['host' => 'localhost', 'name' => 'mailpit', 'user' => 'mailpit'],
            'webhook' => ['token' => self::TOKEN],
        ];
    }

    public function testDefaults(): void
    {
        $settings = Settings::fromArray(self::minimal());

        self::assertFalse($settings->debug);
        self::assertSame('', $settings->basePath);
        self::assertNull($settings->logFile);
        self::assertSame(['host' => 'localhost', 'port' => 3306, 'name' => 'mailpit', 'user' => 'mailpit', 'password' => ''], $settings->db);
        self::assertSame([], $settings->allowedIps);
        self::assertSame(self::TOKEN, $settings->token);
        self::assertSame(16 * 1024 * 1024, $settings->maxBodySize);
        self::assertSame(5 * 1024 * 1024, $settings->maxPartSize);
        self::assertSame(1000, $settings->maxMessages);
        self::assertSame(7, $settings->maxAgeDays, 'mails supprimés au bout de 7 jours par défaut');
        self::assertSame(dirname(__DIR__, 2) . '/var/messages', $settings->storageDir);
        self::assertSame([], $settings->trustedProxies);
        self::assertSame('X-Forwarded-For', $settings->proxyHeader);
        self::assertSame(7200, $settings->sessionLifetime);
        self::assertSame('Europe/Paris', $settings->timezone);
        self::assertTrue($settings->forceHttps, 'HTTPS obligatoire par défaut');
    }

    public function testAllValues(): void
    {
        $settings = Settings::fromArray([
            'debug'       => true,
            'base_path'   => '/api/',
            'force_https' => false,
            'log_file'    => '/tmp/api.log',
            'db'          => ['host' => 'db', 'port' => '3307', 'name' => 'n', 'user' => 'u', 'password' => 'p'],
            'webhook'     => [
                'allowed_ips'         => [' 203.0.113.10 ', '2001:db8::/64'],
                'token'               => '  ' . self::TOKEN . "\n",
                'max_body_size'       => 1000,
                'max_attachment_size' => 500,
                'max_messages'        => 0,
                'max_age_days'        => 0,
                'storage_dir'         => '/data/messages/',
            ],
            'app'   => ['session_lifetime' => 60, 'timezone' => 'UTC'],
            'proxy' => ['trusted_proxies' => ['10.0.0.0/8'], 'header' => 'X-Real-IP'],
        ]);

        self::assertTrue($settings->debug);
        self::assertSame('/api', $settings->basePath);
        self::assertSame('/tmp/api.log', $settings->logFile);
        self::assertSame(['host' => 'db', 'port' => 3307, 'name' => 'n', 'user' => 'u', 'password' => 'p'], $settings->db);
        self::assertSame(['203.0.113.10', '2001:db8::/64'], $settings->allowedIps);
        self::assertSame(self::TOKEN, $settings->token);
        self::assertSame(1000, $settings->maxBodySize);
        self::assertSame(500, $settings->maxPartSize);
        self::assertSame(0, $settings->maxMessages);
        self::assertSame(0, $settings->maxAgeDays);
        self::assertSame('/data/messages', $settings->storageDir);
        self::assertSame(['10.0.0.0/8'], $settings->trustedProxies);
        self::assertSame('X-Real-IP', $settings->proxyHeader);
        self::assertSame(60, $settings->sessionLifetime);
        self::assertSame('UTC', $settings->timezone);
        self::assertFalse($settings->forceHttps);
    }

    public function testBasePathGetsLeadingSlash(): void
    {
        self::assertSame('/api', Settings::fromArray(['base_path' => 'api'] + self::minimal())->basePath);
        self::assertSame('', Settings::fromArray(['base_path' => '/'] + self::minimal())->basePath);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        $config = self::minimal();
        $webhook = $config['webhook'];

        yield 'db absente'              => [['db' => null] + $config, 'la section « db » est manquante'];
        yield 'db.host absent'          => [['db' => ['name' => 'n', 'user' => 'u']] + $config, '« db.host » est manquant'];
        yield 'db.name vide'            => [['db' => ['host' => 'h', 'name' => '', 'user' => 'u']] + $config, '« db.name » est manquant'];
        yield 'db.user pas un texte'    => [['db' => ['host' => 'h', 'name' => 'n', 'user' => 1]] + $config, '« db.user » est manquant'];
        yield 'jeton absent'            => [['webhook' => []] + $config, '« webhook.token » est obligatoire'];
        yield 'jeton trop court'        => [['webhook' => ['token' => str_repeat('a', 31)]] + $config, 'au moins 32 caractères'];
        yield 'jeton court avec espaces' => [['webhook' => ['token' => str_repeat('a', 20) . str_repeat(' ', 20)]] + $config, 'au moins 32 caractères'];
        yield 'jeton pas un texte'      => [['webhook' => ['token' => 123]] + $config, '« webhook.token » est obligatoire'];
        yield 'max_body_size nul'       => [['webhook' => ['max_body_size' => 0] + $webhook] + $config, '« webhook.max_body_size » doit être un nombre d\'octets positif'];
        yield 'max_attachment_size texte' => [['webhook' => ['max_attachment_size' => '5M'] + $webhook] + $config, '« webhook.max_attachment_size »'];
        yield 'max_messages négatif'    => [['webhook' => ['max_messages' => -1] + $webhook] + $config, '« webhook.max_messages » doit être un entier positif'];
        yield 'max_messages texte'      => [['webhook' => ['max_messages' => '1000'] + $webhook] + $config, '« webhook.max_messages »'];
        yield 'max_age_days négatif'    => [['webhook' => ['max_age_days' => -1] + $webhook] + $config, '« webhook.max_age_days » doit être un nombre entier de jours, de 0 (jamais) à 36500'];
        yield 'max_age_days texte'      => [['webhook' => ['max_age_days' => '7d'] + $webhook] + $config, '« webhook.max_age_days »'];
        yield 'max_age_days trop grand' => [['webhook' => ['max_age_days' => 36501] + $webhook] + $config, '« webhook.max_age_days »'];
        yield 'allowed_ips pas une liste' => [['webhook' => ['allowed_ips' => '203.0.113.10'] + $webhook] + $config, '« webhook.allowed_ips » doit être une liste'];
        yield 'trusted_proxies pas une liste' => [['proxy' => ['trusted_proxies' => '10.0.0.1']] + $config, '« proxy.trusted_proxies » doit être une liste'];
        yield 'session trop courte'     => [['app' => ['session_lifetime' => 59]] + $config, '« app.session_lifetime »'];
        yield 'session en texte'        => [['app' => ['session_lifetime' => '7200']] + $config, '« app.session_lifetime »'];
        yield 'force_https en texte'    => [['force_https' => 'false'] + $config, '« force_https » doit valoir true ou false'];
        yield 'force_https à 0'         => [['force_https' => 0] + $config, '« force_https » doit valoir true ou false'];
        yield 'fuseau inconnu'          => [['app' => ['timezone' => 'Mars/Olympus']] + $config, '« app.timezone » : fuseau horaire inconnu « Mars/Olympus »'];
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfig(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Settings::fromArray($config);
    }

    public function testExampleConfigIsValid(): void
    {
        $config = require dirname(__DIR__, 2) . '/config.example.php';
        // Le modèle se charge sans erreur : il reste à jour avec les clés attendues.
        $settings = Settings::fromArray($config);

        self::assertSame(['203.0.113.10'], $settings->allowedIps);
        self::assertSame(1000, $settings->maxMessages);
        self::assertSame(7, $settings->maxAgeDays);
        self::assertTrue($settings->forceHttps);
    }
}
