<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\ClientIpResolver;
use App\Http\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientIpResolverTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, ?string, string}> */
    public static function cases(): iterable
    {
        // [proxys de confiance, REMOTE_ADDR, en-tête X-Forwarded-For, IP attendue]
        yield 'sans proxy : REMOTE_ADDR'                    => [[], '203.0.113.10', null, '203.0.113.10'];
        yield 'en-tête ignoré sans proxy de confiance'      => [[], '198.51.100.1', '203.0.113.10', '198.51.100.1'];
        yield 'en-tête ignoré si REMOTE_ADDR n\'est pas un proxy' => [['10.0.0.0/8'], '198.51.100.1', '203.0.113.10', '198.51.100.1'];
        yield 'proxy de confiance sans en-tête'             => [['10.0.0.1'], '10.0.0.1', null, '10.0.0.1'];
        yield 'proxy de confiance : IP de l\'en-tête'       => [['10.0.0.1'], '10.0.0.1', '203.0.113.10', '203.0.113.10'];
        yield 'chaîne : première IP non fiable en partant de la droite' => [
            ['10.0.0.0/8'], '10.0.0.1', '192.0.2.99, 203.0.113.10, 10.0.0.2', '203.0.113.10',
        ];
        yield 'chaîne : une fausse IP ajoutée à gauche est ignorée' => [
            ['10.0.0.0/8'], '10.0.0.1', '203.0.113.10, 198.51.100.1', '198.51.100.1',
        ];
        yield 'chaîne entièrement composée de proxys'       => [['10.0.0.0/8'], '10.0.0.1', '10.0.0.3, 10.0.0.2', '10.0.0.1'];
        yield 'valeur illisible : REMOTE_ADDR'              => [['10.0.0.0/8'], '10.0.0.1', '203.0.113.10, pas-une-ip', '10.0.0.1'];
        yield 'IPv6 dans la chaîne'                         => [['2001:db8::/32'], '2001:db8::1', '2001:db8::2, 2001:db8:ffff::1, 2a00::5', '2a00::5'];
        yield 'espaces autour des IP'                       => [['10.0.0.1'], '10.0.0.1', '  203.0.113.10  ', '203.0.113.10'];
    }

    /** @param list<string> $trusted */
    #[DataProvider('cases')]
    public function testResolve(array $trusted, string $remoteAddr, ?string $header, string $expected): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://api.test/', ['REMOTE_ADDR' => $remoteAddr]);
        if ($header !== null) {
            $request = $request->withHeader('X-Forwarded-For', $header);
        }

        self::assertSame($expected, new ClientIpResolver(new IpMatcher($trusted))->resolve($request));
    }

    public function testCustomHeader(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://api.test/', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', '198.51.100.1')
            ->withHeader('X-Real-IP', '203.0.113.10');

        self::assertSame('203.0.113.10', new ClientIpResolver(new IpMatcher(['10.0.0.1']), 'X-Real-IP')->resolve($request));
    }

    public function testFromTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(new IpMatcher(['10.0.0.0/8']));
        $factory = new ServerRequestFactory();

        self::assertTrue($resolver->fromTrustedProxy($factory->createServerRequest('GET', 'https://api.test/', ['REMOTE_ADDR' => '10.1.2.3'])));
        self::assertFalse($resolver->fromTrustedProxy($factory->createServerRequest('GET', 'https://api.test/', ['REMOTE_ADDR' => '203.0.113.10'])));
        // Seul REMOTE_ADDR compte : un X-Forwarded-For ne fait pas d'un client un proxy.
        self::assertFalse($resolver->fromTrustedProxy(
            $factory->createServerRequest('GET', 'https://api.test/', ['REMOTE_ADDR' => '203.0.113.10'])->withHeader('X-Forwarded-For', '10.0.0.1'),
        ));
        self::assertFalse($resolver->fromTrustedProxy($factory->createServerRequest('GET', 'https://api.test/')));
    }

    public function testWithoutRemoteAddr(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://api.test/');

        self::assertSame('', new ClientIpResolver(new IpMatcher([]))->resolve($request));
    }
}
