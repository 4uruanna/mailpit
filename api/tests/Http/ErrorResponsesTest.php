<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\AppTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Erreurs : réponses JSON pour l'API, page HTML seulement sous /v1/app (base_path compris).
 * Les pages d'erreur du Dashboard lui-même (session, menu, base injoignable) sont dans DashboardTest.
 */
final class ErrorResponsesTest extends AppTestCase
{
    /**
     * Réponse JSON, sans les en-têtes des pages du Dashboard.
     *
     * @return array<string, mixed>
     */
    private static function assertJsonError(int $status, ResponseInterface $response, string $path): array
    {
        self::assertSame($status, $response->getStatusCode(), $path);
        self::assertSame('', $response->getHeaderLine('Content-Security-Policy'), $path);

        return self::json($response);
    }

    public function testApiErrorsStayJson(): void
    {
        self::assertSame(201, $this->postMessage(self::payload('m1'))->getStatusCode());

        // Mail inconnu : réponse de l'action, inchangée.
        $response = $this->request('GET', '/v1/messages/inconnu', ['Authorization' => 'Bearer ' . self::RELAY_TOKEN]);
        self::assertSame(['error' => 'mail inconnu'], self::assertJsonError(404, $response, '/v1/messages/inconnu'));

        // URL inconnues, y compris celles qui ressemblent au Dashboard : JSON de Slim.
        foreach (['/v1/inconnue', '/v1/messages/inconnu/autre', '/v1/application', '/v1/apps/messages', '/'] as $path) {
            $data = self::assertJsonError(404, $this->request('GET', $path, ip: self::OTHER_IP), $path);
            self::assertArrayHasKey('message', $data, $path);
        }

        $response = $this->request('PUT', '/v1/messages', ip: self::OTHER_IP);
        self::assertJsonError(405, $response, 'PUT /v1/messages');
        self::assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    public function testApiServerErrorStaysJson(): void
    {
        // Base injoignable : 500 JSON pour un développeur, sans détail.
        $config = $this->config();
        $config['db']['port'] = 1;
        $this->useConfig($config);

        $response = $this->request('GET', '/v1/messages', ['Authorization' => 'Bearer mpt_inconnu'], ip: self::OTHER_IP);

        $data = self::assertJsonError(500, $response, 'GET /v1/messages');
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getBody());
        self::assertArrayHasKey('message', $data);
    }

    public function testDashboardErrorPageFollowsBasePath(): void
    {
        $this->useConfig(['base_path' => '/api'] + $this->config());

        $response = $this->request('GET', '/api/v1/app/inconnue', ip: self::OTHER_IP);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString('href="/api/v1/app/messages">Retour aux mails</a>', (string) $response->getBody());

        // Hors du préfixe base_path, ce n'est pas le Dashboard : JSON.
        self::assertJsonError(404, $this->request('GET', '/v1/app/inconnue', ip: self::OTHER_IP), '/v1/app/inconnue');
    }
}
