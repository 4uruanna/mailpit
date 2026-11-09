<?php

declare(strict_types=1);

namespace Relay\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relay\Config;

final class ConfigTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    /** Toutes les variables lues par Config::fromEnv(). */
    private const array VARIABLES = [
        'RELAY_API_URL', 'RELAY_API_TOKEN', 'RELAY_MAILPIT_URL', 'RELAY_MAILPIT_AUTH', 'RELAY_TIMEOUT',
        'RELAY_KEEP_UNREAD', 'RELAY_POLL_INTERVAL', 'RELAY_BATCH_SIZE', 'RELAY_TAG_DONE', 'RELAY_TAG_FAILED',
        'RELAY_QUERY', 'RELAY_LISTEN', 'RELAY_LABEL',
    ];

    protected function setUp(): void
    {
        self::clear();
    }

    protected function tearDown(): void
    {
        self::clear();
    }

    private static function clear(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }

    /** @param array<string, string> $variables */
    private static function env(array $variables): void
    {
        foreach ($variables + ['RELAY_API_URL' => 'https://api.mondomaine.fr', 'RELAY_API_TOKEN' => self::TOKEN] as $name => $value) {
            putenv($name . '=' . $value);
        }
    }

    public function testDefaults(): void
    {
        self::env([]);
        $config = Config::fromEnv();

        self::assertSame('http://mailpit:8025', $config->mailpitUrl);
        self::assertNull($config->mailpitAuth);
        self::assertSame('https://api.mondomaine.fr', $config->apiUrl);
        self::assertSame(self::TOKEN, $config->apiToken);
        self::assertSame(60, $config->timeout);
        self::assertTrue($config->keepUnread);
        self::assertSame(30, $config->pollInterval);
        self::assertSame(50, $config->batchSize);
        self::assertSame('relayed', $config->tagDone);
        self::assertSame('relay-failed', $config->tagFailed);
        self::assertSame('', $config->query);
        self::assertSame('0.0.0.0:8080', $config->listen);
        self::assertNull($config->label);
        self::assertSame('!tag:relayed !tag:relay-failed', $config->pendingQuery());
    }

    public function testAllValues(): void
    {
        self::env([
            'RELAY_API_URL'       => ' https://api.mondomaine.fr/ ',
            'RELAY_MAILPIT_URL'   => 'http://127.0.0.1:8025/',
            'RELAY_MAILPIT_AUTH'  => 'admin:mot:de:passe',
            'RELAY_TIMEOUT'       => '5',
            'RELAY_KEEP_UNREAD'   => 'no',
            'RELAY_POLL_INTERVAL' => '10',
            'RELAY_BATCH_SIZE'    => '1000',
            'RELAY_TAG_DONE'      => 'envoye',
            'RELAY_TAG_FAILED'    => 'refuse.api',
            'RELAY_QUERY'         => 'after:2026-11-08',
            'RELAY_LISTEN'        => '127.0.0.1:9000',
            'RELAY_LABEL'         => 'mailpit-prod',
        ]);
        $config = Config::fromEnv();

        self::assertSame('https://api.mondomaine.fr', $config->apiUrl);
        self::assertSame('http://127.0.0.1:8025', $config->mailpitUrl);
        self::assertSame('admin:mot:de:passe', $config->mailpitAuth);
        self::assertSame(5, $config->timeout);
        self::assertFalse($config->keepUnread);
        self::assertSame(10, $config->pollInterval);
        self::assertSame(500, $config->batchSize, 'au plus 500 mails par recherche');
        self::assertSame('envoye', $config->tagDone);
        self::assertSame('refuse.api', $config->tagFailed);
        self::assertSame('!tag:envoye !tag:refuse.api after:2026-11-08', $config->pendingQuery());
        self::assertSame('127.0.0.1:9000', $config->listen);
        self::assertSame('mailpit-prod', $config->label);
    }

    /** @return iterable<string, array{string, string}> */
    public static function apiUrls(): iterable
    {
        yield 'https'                 => ['https://api.mondomaine.fr', 'https://api.mondomaine.fr'];
        yield 'HTTPS en majuscules'   => ['HTTPS://api.mondomaine.fr/', 'HTTPS://api.mondomaine.fr'];
        yield 'http vers localhost'   => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'http vers LOCALHOST'   => ['http://LOCALHOST:8080/', 'http://LOCALHOST:8080'];
        yield 'http vers 127.0.0.1'   => ['http://127.0.0.1:8080', 'http://127.0.0.1:8080'];
        yield 'http vers ::1'         => ['http://[::1]:8080', 'http://[::1]:8080'];
    }

    #[DataProvider('apiUrls')]
    public function testApiUrl(string $url, string $expected): void
    {
        self::env(['RELAY_API_URL' => $url]);

        self::assertSame($expected, Config::fromEnv()->apiUrl);
    }

    public function testBlankValuesUseDefaults(): void
    {
        self::env(['RELAY_TIMEOUT' => '  ', 'RELAY_LABEL' => '', 'RELAY_MAILPIT_AUTH' => ' ']);
        $config = Config::fromEnv();

        self::assertSame(60, $config->timeout);
        self::assertNull($config->label);
        self::assertNull($config->mailpitAuth);
    }

    public function testDescribeHidesSecrets(): void
    {
        self::env(['RELAY_MAILPIT_AUTH' => 'admin:secret-mailpit']);
        $description = Config::fromEnv()->describe();

        self::assertStringContainsString('auth oui', $description);
        self::assertStringContainsString('jeton 0123…', $description);
        self::assertStringNotContainsString(self::TOKEN, $description);
        self::assertStringNotContainsString('secret-mailpit', $description);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidEnvironments(): iterable
    {
        yield 'URL de l\'API absente'       => [['RELAY_API_URL' => ''], 'RELAY_API_URL manquante ou invalide'];
        yield 'URL de l\'API sans schéma'   => [['RELAY_API_URL' => 'api.mondomaine.fr'], 'RELAY_API_URL manquante ou invalide'];
        yield 'URL de l\'API en ftp'        => [['RELAY_API_URL' => 'ftp://api.mondomaine.fr'], 'RELAY_API_URL manquante ou invalide'];
        yield 'jeton dans l\'URL'           => [['RELAY_API_URL' => 'https://api.mondomaine.fr/v1/messages?token=abc'], 'RELAY_API_URL ne doit pas contenir de « ?… »'];
        yield 'fragment dans l\'URL'        => [['RELAY_API_URL' => 'https://api.mondomaine.fr/#x'], 'RELAY_API_URL ne doit pas contenir de « ?… »'];
        yield 'API en http://'              => [['RELAY_API_URL' => 'http://api.mondomaine.fr'], 'RELAY_API_URL doit commencer par https://'];
        yield 'API en http:// sur une IP'   => [['RELAY_API_URL' => 'http://203.0.113.10:8080'], 'RELAY_API_URL doit commencer par https://'];
        yield 'faux localhost'              => [['RELAY_API_URL' => 'http://localhost.mondomaine.fr'], 'RELAY_API_URL doit commencer par https://'];
        yield 'autre adresse de boucle'     => [['RELAY_API_URL' => 'http://127.0.0.2:8080'], 'RELAY_API_URL doit commencer par https://'];
        yield 'jeton absent'                => [['RELAY_API_TOKEN' => ''], 'RELAY_API_TOKEN manquant ou trop court'];
        yield 'jeton trop court'            => [['RELAY_API_TOKEN' => str_repeat('a', 31)], 'RELAY_API_TOKEN manquant ou trop court'];
        yield 'jeton avec espace'           => [['RELAY_API_TOKEN' => str_repeat('a', 20) . ' ' . str_repeat('b', 20)], '(32 caractères minimum, sans espace)'];
        yield 'URL de Mailpit invalide'     => [['RELAY_MAILPIT_URL' => 'mailpit:8025'], 'RELAY_MAILPIT_URL invalide'];
        yield 'identifiants sans « : »'     => [['RELAY_MAILPIT_AUTH' => 'admin'], 'RELAY_MAILPIT_AUTH doit être au format utilisateur:motdepasse'];
        yield 'tag avec espace'             => [['RELAY_TAG_DONE' => 'mon tag'], 'RELAY_TAG_DONE : lettres, chiffres'];
        yield 'tag trop long'               => [['RELAY_TAG_FAILED' => str_repeat('a', 51)], 'RELAY_TAG_FAILED : lettres, chiffres'];
        yield 'tags identiques'             => [['RELAY_TAG_DONE' => 'Relayed', 'RELAY_TAG_FAILED' => 'relayed'], 'RELAY_TAG_DONE et RELAY_TAG_FAILED doivent être différents'];
        yield 'délai à zéro'                => [['RELAY_TIMEOUT' => '0'], 'RELAY_TIMEOUT doit être un entier positif'];
        yield 'délai négatif'               => [['RELAY_POLL_INTERVAL' => '-5'], 'RELAY_POLL_INTERVAL doit être un entier positif'];
        yield 'lot en texte'                => [['RELAY_BATCH_SIZE' => 'beaucoup'], 'RELAY_BATCH_SIZE doit être un entier positif'];
        yield 'booléen illisible'           => [['RELAY_KEEP_UNREAD' => 'peut-être'], 'RELAY_KEEP_UNREAD doit valoir true ou false'];
    }

    /** @param array<string, string> $variables */
    #[DataProvider('invalidEnvironments')]
    public function testInvalidEnvironment(array $variables, string $message): void
    {
        self::env($variables);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Config::fromEnv();
    }

    public function testExampleFileIsValid(): void
    {
        // relay.env.example : « NOM=valeur », lignes commentées ignorées (comme Podman).
        $lines = file(dirname(__DIR__) . '/relay.env.example', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '#') && str_contains($line, '=')) {
                putenv($line);
            }
        }
        $config = Config::fromEnv();

        self::assertSame('https://api.mondomaine.fr', $config->apiUrl);
        self::assertSame('http://mailpit:8025', $config->mailpitUrl);
    }
}
