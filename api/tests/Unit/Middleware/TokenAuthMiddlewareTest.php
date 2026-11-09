<?php

declare(strict_types=1);

namespace App\Tests\Unit\Middleware;

use App\Middleware\TokenAuthMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TokenAuthMiddlewareTest extends TestCase
{
    private const string TOKEN = 'secret-du-relais-0123456789abcdef';

    /** @return iterable<string, array{array<string, string>, array<string, string>, ?string}> */
    public static function bearerCases(): iterable
    {
        // [en-têtes, variables serveur, jeton attendu]
        yield 'en-tête Authorization'         => [['Authorization' => 'Bearer abc123'], [], 'abc123'];
        yield 'casse de « Bearer » indifférente' => [['Authorization' => 'bearer abc123'], [], 'abc123'];
        yield 'espaces en trop'               => [['Authorization' => 'Bearer    abc123  '], [], 'abc123'];
        yield 'HTTP_AUTHORIZATION (.htaccess)' => [[], ['HTTP_AUTHORIZATION' => 'Bearer abc123'], 'abc123'];
        yield 'REDIRECT_HTTP_AUTHORIZATION'   => [[], ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc123'], 'abc123'];
        yield 'en-tête prioritaire'           => [['Authorization' => 'Bearer depuis-en-tete'], ['HTTP_AUTHORIZATION' => 'Bearer serveur'], 'depuis-en-tete'];
        yield 'absent'                        => [[], [], null];
        yield 'Basic'                         => [['Authorization' => 'Basic dXNlcjpwYXNz'], [], null];
        yield 'Bearer sans jeton'             => [['Authorization' => 'Bearer '], [], null];
        yield 'jeton avec espace'             => [['Authorization' => 'Bearer abc def'], [], null];
        yield 'jeton sans « Bearer »'         => [['Authorization' => 'abc123'], [], null];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    #[DataProvider('bearerCases')]
    public function testBearer(array $headers, array $server, ?string $expected): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://api.test/v1/messages', $server);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        self::assertSame($expected, TokenAuthMiddleware::bearer($request));
    }

    /** @return iterable<string, array{array<string, string>, string, int}> */
    public static function processCases(): iterable
    {
        yield 'bon jeton dans l\'en-tête' => [['Authorization' => 'Bearer ' . self::TOKEN], '', 200];
        yield 'bon jeton dans l\'URL'     => [[], '?token=' . self::TOKEN, 200];
        yield 'mauvais jeton'            => [['Authorization' => 'Bearer ' . self::TOKEN . 'x'], '', 401];
        yield 'mauvais jeton dans l\'URL' => [[], '?token=faux', 401];
        yield 'jeton vide dans l\'URL'    => [[], '?token=', 401];
        yield 'jeton en tableau dans l\'URL' => [[], '?token[]=' . self::TOKEN, 401];
        yield 'absent'                   => [[], '', 401];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('processCases')]
    public function testProcess(array $headers, string $query, int $expected): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', 'https://api.test/v1/messages' . $query);
        parse_str(ltrim($query, '?'), $params);
        $request = $request->withQueryParams($params);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $factory = new ResponseFactory();
        $handler = new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        $response = new TokenAuthMiddleware(self::TOKEN, $factory)->process($request, $handler);

        self::assertSame($expected, $response->getStatusCode());
        if ($expected === 401) {
            self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('{"error":"Unauthorized"}', (string) $response->getBody());
        }
    }
}
