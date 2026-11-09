<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailpit;

use App\Mailpit\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('alice@test.fr', Address::normalize('  Alice@Test.FR '));
        self::assertSame('élodie@exemple.fr', Address::normalize('ÉLODIE@exemple.fr'));
        self::assertSame('', Address::normalize('   '));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function parseCases(): iterable
    {
        yield 'adresse simple'           => ['alice@test.fr', 'alice@test.fr'];
        yield 'majuscules et espaces'    => ['  Bob@Test.FR  ', 'bob@test.fr'];
        yield 'avec sous-adresse'        => ['alice+tag@test.mondomaine.fr', 'alice+tag@test.mondomaine.fr'];
        yield 'unicode'                  => ['élodie@exemple.fr', 'élodie@exemple.fr'];
        yield 'vide'                     => ['', null];
        yield 'espaces seuls'            => ['   ', null];
        yield 'sans @'                   => ['alice.test.fr', null];
        yield 'deux @'                   => ['alice@@test.fr', null];
        yield 'avec un nom'              => ['Alice <alice@test.fr>', null];
        yield 'trop longue'              => [str_repeat('a', 64) . '@' . str_repeat('b', 270) . '.fr', null];
        yield 'pas un texte (entier)'    => [42, null];
        yield 'pas un texte (null)'      => [null, null];
        yield 'pas un texte (tableau)'   => [['alice@test.fr'], null];
    }

    #[DataProvider('parseCases')]
    public function testParse(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, Address::parse($value));
    }
}
