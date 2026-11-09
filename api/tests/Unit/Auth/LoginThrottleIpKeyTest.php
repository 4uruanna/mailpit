<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\LoginThrottle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Adresse comptée par la limitation des connexions au Dashboard. */
final class LoginThrottleIpKeyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function addresses(): iterable
    {
        yield 'IPv4 exacte' => ['203.0.113.10', '203.0.113.10'];
        yield 'IPv4 mappée en IPv6' => ['::ffff:203.0.113.10', '203.0.113.10'];
        yield 'IPv6 ramenée à son /64' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64'];
        yield 'IPv6 en majuscules et non abrégée' => ['2001:0DB8:0001:0002:FFFF:FFFF:FFFF:FFFF', '2001:db8:1:2::/64'];
        yield 'IPv6 courte' => ['::1', '::/64'];
        yield 'IPv6 avec zéros dans le préfixe' => ['2001:db8::1', '2001:db8::/64'];
        yield 'pas une IP' => ['', ''];
        yield 'valeur illisible' => ['pas-une-ip', 'pas-une-ip'];
    }

    #[DataProvider('addresses')]
    public function testIpKey(string $ip, string $expected): void
    {
        self::assertSame($expected, LoginThrottle::ipKey($ip));
    }
}
