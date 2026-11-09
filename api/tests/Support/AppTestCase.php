<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\WaitClock;
use App\Kernel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Test HTTP : l'application Slim complète (Kernel::create), appelée avec des requêtes fabriquées,
 * sur la base de test et un dossier de stockage temporaire.
 */
abstract class AppTestCase extends DatabaseTestCase
{
    /** IP du serveur Mailpit, seule autorisée à envoyer des mails. */
    protected const string RELAY_IP = '203.0.113.10';
    protected const string OTHER_IP = '198.51.100.7';
    protected const string RELAY_TOKEN = 'jeton-du-relais-0123456789abcdef0123456789abcdef';

    protected const int MAX_BODY_SIZE = 64 * 1024;
    protected const int MAX_PART_SIZE = 1024;

    /** Dossier temporaire des pièces jointes (webhook.storage_dir). */
    protected string $storageDir = '';
    /** Horloge de GET /v1/messages?wait=… ; null = horloge réelle. Lue à la création de l'application (app(), useConfig()). */
    protected ?WaitClock $waitClock = null;
    private ?App $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = TempDir::create();
    }

    protected function tearDown(): void
    {
        if ($this->storageDir !== '') {
            TempDir::remove($this->storageDir);
        }
        parent::tearDown();
    }

    /**
     * Configuration de l'application (même forme que config.php).
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return [
            'debug'        => false,
            'log_file'     => TempDir::logFile(),
            // Pas de rotation : des tests lisent la fin de ce log, qui grossit d'un lancement à l'autre.
            'log_max_size' => 0,
            'db'           => TestDatabase::config(),
            'webhook'      => [
                'allowed_ips'         => [self::RELAY_IP],
                'token'               => self::RELAY_TOKEN,
                'max_body_size'       => self::MAX_BODY_SIZE,
                'max_attachment_size' => self::MAX_PART_SIZE,
                'max_messages'        => 0,
                // Pas de suppression par âge : les dates des mails de test sont fixes.
                'max_age_days'        => 0,
                'storage_dir'         => $this->storageDir,
            ],
            'app'          => ['timezone' => 'Europe/Paris'],
        ];
    }

    protected function app(): App
    {
        return $this->app ??= Kernel::create($this->config(), $this->waitClock);
    }

    /** Recrée l'application avec une autre configuration (ex. config() modifiée). */
    protected function useConfig(array $config): void
    {
        $this->app = Kernel::create($config, $this->waitClock);
    }

    /**
     * Requête HTTPS par défaut, comme en production (force_https) : HTTPS=on et port 443,
     * ce que fixe Apache. $https = false : requête en clair, http:// sur le port 80.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $form champs d'un formulaire (corps déjà analysé)
     */
    protected function request(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        string $ip = self::RELAY_IP,
        ?array $form = null,
        bool $https = true,
    ): ResponseInterface {
        $server = $https
            ? ['REMOTE_ADDR' => $ip, 'HTTPS' => 'on', 'SERVER_PORT' => '443']
            : ['REMOTE_ADDR' => $ip, 'SERVER_PORT' => '80'];
        $request = new ServerRequestFactory()->createServerRequest($method, ($https ? 'https' : 'http') . '://api.test' . $path, $server);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody(new StreamFactory()->createStream($body));
        }
        if ($form !== null) {
            $request = $request->withParsedBody($form)->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        return $this->handle($request);
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app()->handle($request);
    }

    /**
     * POST /v1/messages par le relais.
     *
     * @param array<string, mixed> $payload
     */
    protected function postMessage(array $payload, string $token = self::RELAY_TOKEN): ResponseInterface
    {
        return $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** POST /v1/messages/{id}/part/{partId} par le relais. */
    protected function postPart(string $id, string $partId, string $content, ?string $sha256 = null): ResponseInterface
    {
        return $this->request('POST', sprintf('/v1/messages/%s/part/%s', $id, $partId), [
            'Authorization'    => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'     => 'application/octet-stream',
            'X-Content-SHA256' => $sha256 ?? hash('sha256', $content),
        ], $content);
    }

    /** @return array<string, mixed> */
    protected static function json(ResponseInterface $response): array
    {
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
