<?php

declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Http\ClientIpResolver;
use App\Http\HttpsDetector;
use App\Http\IpMatcher;
use App\Middleware\ForceHttpsMiddleware;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ForceHttpsMiddlewareTest extends TestCase
{
    private const string PROXY = '10.0.0.1';

    /** Requêtes reçues par la suite de l'application (le gestionnaire). */
    private int $handled = 0;

    /** @return iterable<string, array{string, string, string}> */
    public static function redirects(): iterable
    {
        // [méthode, URL en clair, redirection attendue]
        yield 'GET'                     => ['GET', 'http://api.test/v1/app/signin', 'https://api.test/v1/app/signin'];
        yield 'HEAD'                    => ['HEAD', 'http://api.test/v1/app', 'https://api.test/v1/app'];
        yield 'paramètres gardés'       => ['GET', 'http://api.test/v1/messages?address=a%40b.fr&limit=1', 'https://api.test/v1/messages?address=a%40b.fr&limit=1'];
        yield 'port non standard retiré' => ['GET', 'http://api.test:8080/v1/messages', 'https://api.test/v1/messages'];
        yield 'racine'                  => ['GET', 'http://api.test/', 'https://api.test/'];
    }

    #[DataProvider('redirects')]
    public function testPlainGetIsRedirected(string $method, string $url, string $location): void
    {
        $response = $this->process($method, $url, ['SERVER_PORT' => '80']);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame($location, $response->getHeaderLine('Location'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'), 'HSTS uniquement en HTTPS');
        self::assertSame(0, $this->handled);
    }

    /** @return iterable<string, array{string}> */
    public static function otherMethods(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('otherMethods')]
    public function testPlainRequestWithBodyIsRefused(string $method): void
    {
        $log = $this->logOf(function () use ($method, &$response): void {
            $response = $this->process($method, 'http://api.test/v1/messages', ['SERVER_PORT' => '80']);
        });

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"error":"HTTPS obligatoire : renvoyez la requête en https://"}', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Location'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
        self::assertSame(0, $this->handled, 'le corps reçu en clair n\'est jamais traité');
        self::assertStringContainsString(sprintf('[https] requête en clair refusée depuis « 198.51.100.7 » : %s /v1/messages', $method), $log);
        self::assertStringNotContainsString('changez ce jeton', $log);
    }

    public function testTokenSentInClearIsLoggedWithoutItsValue(): void
    {
        $headers = ['Authorization' => 'Bearer jeton-secret-0123456789'];

        $log = $this->logOf(fn () => $this->process('POST', 'http://api.test/v1/messages', ['SERVER_PORT' => '80'], $headers));
        self::assertStringContainsString('POST /v1/messages ; en-tête Authorization envoyé en clair : changez ce jeton', $log);
        self::assertStringNotContainsString('jeton-secret', $log);

        // Une page redirigée n'est pas journalisée… sauf si un jeton est passé en clair.
        self::assertSame('', $this->logOf(fn () => $this->process('GET', 'http://api.test/v1/app', ['SERVER_PORT' => '80'])));
        $log = $this->logOf(fn () => $this->process('GET', 'http://api.test/v1/messages', ['SERVER_PORT' => '80'], $headers));
        self::assertStringContainsString('[https] GET /v1/messages en http:// depuis « 198.51.100.7 », redirigé ; en-tête Authorization envoyé en clair', $log);
        self::assertStringNotContainsString('jeton-secret', $log);
    }

    public function testGetWithoutHostIsRefused(): void
    {
        // Sans nom d'hôte, pas d'URL https:// à proposer.
        $response = $this->process('GET', '/v1/messages', ['SERVER_PORT' => '80']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->handled);
    }

    /** @return iterable<string, array{string, array<string, string>, array<string, string>}> */
    public static function secureRequests(): iterable
    {
        yield 'HTTPS=on'                  => ['https://api.test/v1/messages', ['HTTPS' => 'on', 'SERVER_PORT' => '443'], []];
        yield 'port 443 (OVH)'            => ['http://api.test/v1/messages', ['SERVER_PORT' => '443'], []];
        yield 'proxy de confiance'        => ['http://api.test/v1/messages', ['REMOTE_ADDR' => self::PROXY, 'SERVER_PORT' => '80'], ['X-Forwarded-Proto' => 'https']];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    #[DataProvider('secureRequests')]
    public function testHttpsRequestPassesWithHsts(string $url, array $server, array $headers): void
    {
        $response = $this->process('POST', $url, $server, $headers);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->handled);
        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testForwardedProtoFromUntrustedClientIsIgnored(): void
    {
        $response = $this->process('POST', 'http://api.test/v1/messages', ['SERVER_PORT' => '80'], ['X-Forwarded-Proto' => 'https']);
        self::assertSame(403, $response->getStatusCode());

        $response = $this->process('GET', 'http://api.test/v1/messages', ['SERVER_PORT' => '80'], ['X-Forwarded-Proto' => 'https']);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame(0, $this->handled);
    }

    public function testProxyReportingPlainHttpIsRedirected(): void
    {
        // Le proxy joint l'hébergement en HTTPS, mais le client lui a parlé en clair.
        $server = ['REMOTE_ADDR' => self::PROXY, 'HTTPS' => 'on', 'SERVER_PORT' => '443'];
        $response = $this->process('GET', 'https://api.test/v1/app', $server, ['X-Forwarded-Proto' => 'http']);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://api.test/v1/app', $response->getHeaderLine('Location'));
    }

    public function testExistingHstsIsKept(): void
    {
        $response = $this->process('GET', 'https://api.test/', ['HTTPS' => 'on'], [], 'max-age=60');

        self::assertSame(['max-age=60'], $response->getHeader('Strict-Transport-Security'));
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    private function process(string $method, string $url, array $server = [], array $headers = [], ?string $hsts = null): ResponseInterface
    {
        $request = new ServerRequestFactory()->createServerRequest($method, $url, $server + ['REMOTE_ADDR' => '198.51.100.7']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $factory = new ResponseFactory();
        $handler = new class ($factory, $hsts, function (): void {
            $this->handled++;
        }) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseFactory $factory,
                private readonly ?string $hsts,
                private readonly Closure $onHandle,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->onHandle)();
                $response = $this->factory->createResponse(200);

                return $this->hsts === null ? $response : $response->withHeader('Strict-Transport-Security', $this->hsts);
            }
        };

        $resolver = new ClientIpResolver(new IpMatcher([self::PROXY]));

        return new ForceHttpsMiddleware(new HttpsDetector($resolver), $resolver, $factory)->process($request, $handler);
    }

    /** Ce que $run écrit dans le log (error_log). */
    private function logOf(callable $run): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'mailpit-https-log-');
        $previous = ini_set('error_log', $file);
        try {
            $run();

            return (string) file_get_contents($file);
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($file);
        }
    }
}
