<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\TestCase;
use Relay\Http;
use Relay\Tests\Support\PhpServer;

final class HttpTest extends TestCase
{
    private static PhpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new PhpServer(__DIR__ . '/Fixtures/http-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /** @return array{method: string, headers: array<string, string>, body: string} */
    private static function echo(array $response): array
    {
        self::assertSame(200, $response['status']);
        self::assertNull($response['error']);

        return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testGet(): void
    {
        $echo = self::echo(Http::request('GET', self::$server->url() . '/echo'));

        self::assertSame('GET', $echo['method']);
        self::assertSame('mailpit-relay/2.0', $echo['headers']['user-agent']);
        self::assertSame('', $echo['body']);
    }

    public function testPostWithHeadersAndBody(): void
    {
        $body = random_bytes(300_000);
        $echo = self::echo(Http::request('POST', self::$server->url() . '/echo', ['Content-Type: application/octet-stream', 'X-Test: 1'], base64_encode($body)));

        self::assertSame('POST', $echo['method']);
        self::assertSame('application/octet-stream', $echo['headers']['content-type']);
        self::assertSame('1', $echo['headers']['x-test']);
        // Pas d'« Expect: 100-continue » : une requête de plus pour rien.
        self::assertArrayNotHasKey('expect', $echo['headers']);
        self::assertSame(base64_encode($body), $echo['body']);
    }

    public function testPut(): void
    {
        self::assertSame('PUT', self::echo(Http::request('PUT', self::$server->url() . '/echo', [], '{}'))['method']);
    }

    public function testBasicAuth(): void
    {
        $echo = self::echo(Http::request('GET', self::$server->url() . '/echo', auth: 'admin:mot:de:passe'));

        self::assertSame('Basic ' . base64_encode('admin:mot:de:passe'), $echo['headers']['authorization']);
    }

    public function testErrorStatusIsReturned(): void
    {
        $response = Http::request('GET', self::$server->url() . '/status/503');

        self::assertSame(['status' => 503, 'body' => 'réponse 503', 'error' => null], $response);
        self::assertSame('test : HTTP 503 réponse 503', Http::describe($response, 'test'));
    }

    public function testRedirectIsNotFollowed(): void
    {
        self::assertSame(302, Http::request('GET', self::$server->url() . '/redirect')['status']);
    }

    public function testMaxSize(): void
    {
        self::assertSame(1000, strlen(Http::request('GET', self::$server->url() . '/bytes/1000', maxSize: 1000)['body']));

        $response = Http::request('GET', self::$server->url() . '/bytes/1001', maxSize: 1000);
        self::assertSame(['status' => 0, 'body' => '', 'error' => 'réponse de plus de 1000 octets'], $response);
        self::assertSame('test : réponse de plus de 1000 octets', Http::describe($response, 'test'));
    }

    public function testTimeout(): void
    {
        $response = Http::request('GET', self::$server->url() . '/sleep/3', timeout: 1);

        self::assertSame(0, $response['status']);
        self::assertNotEmpty($response['error']);
    }

    public function testConnectionRefused(): void
    {
        $response = Http::request('GET', 'http://127.0.0.1:' . PhpServer::freePort() . '/');

        self::assertSame(0, $response['status']);
        self::assertSame('', $response['body']);
        self::assertNotEmpty($response['error']);
    }

    public function testOnlyHttpAndHttpsAreAllowed(): void
    {
        $response = Http::request('GET', 'file:///etc/passwd');

        self::assertSame(0, $response['status']);
        self::assertSame('', $response['body']);
    }

    public function testDescribeCutsLongBodies(): void
    {
        $description = Http::describe(['status' => 500, 'body' => "  \n" . str_repeat('é', 300), 'error' => null], 'API');

        self::assertSame('API : HTTP 500 ' . str_repeat('é', 200), $description);
    }
}
