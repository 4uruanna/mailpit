<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;

/**
 * HTTPS obligatoire (force_https) : l'application complète, avec des requêtes en clair.
 * Les autres tests HTTP envoient des requêtes HTTPS (HTTPS=on, port 443), comme Apache.
 */
final class ForceHttpsTest extends AppTestCase
{
    private const string PROXY = '10.0.0.1';

    public function testPagesAreRedirected(): void
    {
        foreach (['/v1/app/signin', '/v1/app', '/v1/messages?address=alice%40test.fr&limit=1', '/v1/inconnu'] as $path) {
            $response = $this->request('GET', $path, ip: self::OTHER_IP, https: false);

            self::assertSame(301, $response->getStatusCode(), $path);
            self::assertSame('https://api.test' . $path, $response->getHeaderLine('Location'), $path);
            self::assertFalse($response->hasHeader('Strict-Transport-Security'), $path);
        }

        self::assertSame(301, $this->request('HEAD', '/v1/app/signin', ip: self::OTHER_IP, https: false)->getStatusCode());
    }

    public function testRelayInClearIsRefusedAndNothingIsStored(): void
    {
        clearstatcache();
        $before = is_file(TempDir::logFile()) ? (int) filesize(TempDir::logFile()) : 0;

        // Bon jeton, bonne IP, mais en clair : refusé avant le filtre IP et le jeton.
        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
        ], json_encode(self::payload('m1'), JSON_THROW_ON_ERROR), https: false);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'HTTPS obligatoire : renvoyez la requête en https://'], self::json($response));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));

        $log = (string) file_get_contents(TempDir::logFile(), offset: $before);
        self::assertStringContainsString('[https] requête en clair refusée depuis « ' . self::RELAY_IP . ' » : POST /v1/messages ; en-tête Authorization envoyé en clair : changez ce jeton', $log);
        self::assertStringNotContainsString(self::RELAY_TOKEN, $log);

        // Pièce jointe, suppression, formulaire du Dashboard : même refus.
        $this->postMessage(self::payload('m1', parts: [['2', 1]]));
        $response = $this->request('POST', '/v1/messages/m1/part/2', [
            'Authorization'    => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'     => 'application/octet-stream',
            'X-Content-SHA256' => hash('sha256', 'x'),
        ], 'x', https: false);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('pending', $this->scalar('SELECT status FROM mailpit_parts'));
        self::assertSame(403, $this->request('DELETE', '/v1/messages/m1', ip: self::OTHER_IP, https: false)->getStatusCode());
        self::assertSame(403, $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: ['username' => 'admin'], https: false)->getStatusCode());
    }

    public function testHttpsResponsesHaveHsts(): void
    {
        foreach ([$this->postMessage(self::payload('m1')), $this->request('GET', '/v1/messages', ip: self::OTHER_IP), $this->request('GET', '/v1/inconnu')] as $response) {
            self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        }
    }

    public function testTrustedProxyForwardedProto(): void
    {
        $config = $this->config();
        $config['proxy'] = ['trusted_proxies' => [self::PROXY]];
        $this->useConfig($config);

        // Le proxy parle en http:// à l'hébergement, le client était en HTTPS : accepté.
        $response = $this->request('POST', '/v1/messages', [
            'Authorization'     => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'      => 'application/json',
            'X-Forwarded-For'   => self::RELAY_IP,
            'X-Forwarded-Proto' => 'https',
        ], json_encode(self::payload('m1'), JSON_THROW_ON_ERROR), self::PROXY, https: false);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));

        // Le client était en clair : redirigé, même si le proxy joint l'hébergement en HTTPS.
        $response = $this->request('GET', '/v1/app/signin', ['X-Forwarded-Proto' => 'http'], ip: self::PROXY);
        self::assertSame(301, $response->getStatusCode());

        // Un client qui envoie lui-même X-Forwarded-Proto n'est pas cru.
        $response = $this->request('GET', '/v1/app/signin', ['X-Forwarded-Proto' => 'https'], ip: self::OTHER_IP, https: false);
        self::assertSame(301, $response->getStatusCode());
    }

    public function testBasePathIsKeptInTheRedirect(): void
    {
        $this->useConfig(['base_path' => '/api'] + $this->config());

        $response = $this->request('GET', '/api/v1/app/signin?x=1', ip: self::OTHER_IP, https: false);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://api.test/api/v1/app/signin?x=1', $response->getHeaderLine('Location'));
    }

    public function testCheckCanBeDisabledForLocalDevelopment(): void
    {
        $this->useConfig(['force_https' => false] + $this->config());

        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
        ], json_encode(self::payload('m1'), JSON_THROW_ON_ERROR), https: false);
        self::assertSame(201, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));

        self::assertSame(401, $this->request('GET', '/v1/messages', ip: self::OTHER_IP, https: false)->getStatusCode());
        self::assertFalse($this->request('GET', '/v1/messages', ip: self::OTHER_IP)->hasHeader('Strict-Transport-Security'));
    }
}
