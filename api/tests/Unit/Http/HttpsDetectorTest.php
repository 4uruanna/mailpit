<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\ClientIpResolver;
use App\Http\HttpsDetector;
use App\Http\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HttpsDetectorTest extends TestCase
{
    private const string PROXY = '10.0.0.1';

    /** @return iterable<string, array{string, array<string, string>, ?string, bool}> */
    public static function cases(): iterable
    {
        // [URL, variables serveur, en-tête X-Forwarded-Proto, HTTPS attendu]
        $client = ['REMOTE_ADDR' => '198.51.100.7'];
        $proxy = ['REMOTE_ADDR' => self::PROXY];

        yield 'HTTPS=on'                           => ['http://api.test/', $client + ['HTTPS' => 'on', 'SERVER_PORT' => '443'], null, true];
        yield 'HTTPS=1'                            => ['http://api.test/', $client + ['HTTPS' => '1'], null, true];
        yield 'port 443 sans HTTPS (frontal OVH)'  => ['http://api.test/', $client + ['SERVER_PORT' => '443'], null, true];
        yield 'URL en https://'                    => ['https://api.test/', $client, null, true];
        yield 'port 80'                            => ['http://api.test/', $client + ['SERVER_PORT' => '80'], null, false];
        yield 'HTTPS=off'                          => ['http://api.test/', $client + ['HTTPS' => 'off', 'SERVER_PORT' => '80'], null, false];
        yield 'HTTPS=OFF'                          => ['http://api.test/', $client + ['HTTPS' => 'OFF'], null, false];
        yield 'HTTPS vide, port 8080'              => ['http://api.test:8080/', $client + ['HTTPS' => '', 'SERVER_PORT' => '8080'], null, false];
        yield 'rien du tout'                       => ['http://api.test/', [], null, false];
        // X-Forwarded-Proto : ignoré hors proxy de confiance.
        yield 'X-Forwarded-Proto d\'un client'     => ['http://api.test/', $client + ['SERVER_PORT' => '80'], 'https', false];
        yield 'X-Forwarded-Proto d\'un client en HTTPS' => ['https://api.test/', $client + ['HTTPS' => 'on'], 'http', true];
        // Proxy de confiance : X-Forwarded-Proto décide.
        yield 'proxy : https, hébergement en http' => ['http://api.test/', $proxy + ['SERVER_PORT' => '80'], 'https', true];
        yield 'proxy : HTTPS en majuscules'        => ['http://api.test/', $proxy, ' HTTPS ', true];
        yield 'proxy : http, hébergement en https' => ['https://api.test/', $proxy + ['HTTPS' => 'on', 'SERVER_PORT' => '443'], 'http', false];
        yield 'proxy : valeur inconnue'            => ['https://api.test/', $proxy + ['HTTPS' => 'on'], 'wss', false];
        yield 'proxy : dernière valeur (http)'     => ['http://api.test/', $proxy, 'https, http', false];
        yield 'proxy : dernière valeur (https)'    => ['http://api.test/', $proxy, 'http, https', true];
        yield 'proxy sans X-Forwarded-Proto : port' => ['http://api.test/', $proxy + ['SERVER_PORT' => '443'], null, true];
        yield 'proxy sans X-Forwarded-Proto : http' => ['http://api.test/', $proxy + ['SERVER_PORT' => '80'], null, false];
    }

    /** @param array<string, string> $server */
    #[DataProvider('cases')]
    public function testIsHttps(string $url, array $server, ?string $proto, bool $expected): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', $url, $server);
        if ($proto !== null) {
            $request = $request->withHeader('X-Forwarded-Proto', $proto);
        }

        $detector = new HttpsDetector(new ClientIpResolver(new IpMatcher([self::PROXY])));

        self::assertSame($expected, $detector->isHttps($request));
    }

    public function testProxyRangeAndIpv6(): void
    {
        $detector = new HttpsDetector(new ClientIpResolver(new IpMatcher(['10.0.0.0/8', '2001:db8::/32'])));
        $factory = new ServerRequestFactory();

        foreach (['10.20.30.40', '2001:db8::5', '::ffff:10.0.0.9'] as $proxy) {
            $request = $factory->createServerRequest('GET', 'http://api.test/', ['REMOTE_ADDR' => $proxy])->withHeader('X-Forwarded-Proto', 'https');
            self::assertTrue($detector->isHttps($request), $proxy);
        }

        $request = $factory->createServerRequest('GET', 'http://api.test/', ['REMOTE_ADDR' => '11.0.0.1'])->withHeader('X-Forwarded-Proto', 'https');
        self::assertFalse($detector->isHttps($request));
    }
}
