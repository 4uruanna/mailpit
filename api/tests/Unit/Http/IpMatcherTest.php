<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\IpMatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, bool}> */
    public static function cases(): iterable
    {
        yield 'IPv4 identique'                 => [['203.0.113.10'], '203.0.113.10', true];
        yield 'IPv4 différente'                => [['203.0.113.10'], '203.0.113.11', false];
        yield 'IPv4 dans un /24'               => [['198.51.100.0/24'], '198.51.100.254', true];
        yield 'IPv4 hors du /24'               => [['198.51.100.0/24'], '198.51.101.1', false];
        yield 'IPv4 dans un /20 (bits partiels)' => [['10.1.16.0/20'], '10.1.31.255', true];
        yield 'IPv4 hors du /20 (bits partiels)' => [['10.1.16.0/20'], '10.1.32.0', false];
        yield 'IPv4 /0 : tout'                 => [['0.0.0.0/0'], '192.0.2.1', true];
        yield 'IPv4 /32'                       => [['192.0.2.1/32'], '192.0.2.1', true];
        yield 'IPv6 identique'                 => [['2001:db8::10'], '2001:db8::10', true];
        yield 'IPv6 écriture différente'       => [['2001:db8::10'], '2001:0db8:0000:0000:0000:0000:0000:0010', true];
        yield 'IPv6 dans un /64'               => [['2001:db8:1:2::/64'], '2001:db8:1:2:abcd::1', true];
        yield 'IPv6 hors du /64'               => [['2001:db8:1:2::/64'], '2001:db8:1:3::1', false];
        yield 'IPv6 dans un /57 (bits partiels)' => [['2001:db8:0:80::/57'], '2001:db8:0:ff::1', true];
        yield 'IPv6 hors du /57 (bits partiels)' => [['2001:db8:0:80::/57'], '2001:db8:0:7f::1', false];
        yield 'IPv4 mappée ::ffff: reconnue'   => [['203.0.113.10'], '::ffff:203.0.113.10', true];
        yield 'IPv4 mappée dans une plage'     => [['203.0.113.0/24'], '::ffff:203.0.113.99', true];
        yield 'entrée mappée ::ffff:'          => [['::ffff:203.0.113.10'], '203.0.113.10', true];
        yield 'IPv4 contre une IPv6'           => [['2001:db8::/32'], '203.0.113.10', false];
        yield 'IPv6 contre une IPv4'           => [['0.0.0.0/0'], '2001:db8::1', false];
        yield 'liste vide : tout refusé'       => [[], '203.0.113.10', false];
        yield 'plusieurs entrées'              => [['192.0.2.1', '2001:db8::/32'], '2001:db8:ffff::1', true];
        yield 'adresse vide'                   => [['203.0.113.10'], '', false];
        yield 'adresse illisible'              => [['203.0.113.10'], 'pas-une-ip', false];
        yield 'adresse avec plage'             => [['203.0.113.0/24'], '203.0.113.10/24', false];
    }

    /** @param list<string> $entries */
    #[DataProvider('cases')]
    public function testMatches(array $entries, string $ip, bool $expected): void
    {
        self::assertSame($expected, new IpMatcher($entries)->matches($ip));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEntries(): iterable
    {
        yield 'texte'                => ['localhost', 'adresse IP invalide'];
        yield 'vide'                 => ['', 'adresse IP invalide'];
        yield 'IPv4 incomplète'      => ['203.0.113', 'adresse IP invalide'];
        yield 'plage sans adresse'   => ['/24', 'adresse IP invalide'];
        yield 'préfixe IPv4 trop long' => ['203.0.113.0/33', 'plage CIDR invalide'];
        yield 'préfixe IPv6 trop long' => ['2001:db8::/129', 'plage CIDR invalide'];
        yield 'préfixe négatif'      => ['203.0.113.0/-1', 'plage CIDR invalide'];
        yield 'préfixe vide'         => ['203.0.113.0/', 'plage CIDR invalide'];
        yield 'préfixe non numérique' => ['203.0.113.0/abc', 'plage CIDR invalide'];
    }

    #[DataProvider('invalidEntries')]
    public function testRejectsInvalidEntries(string $entry, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new IpMatcher([$entry]);
    }
}
