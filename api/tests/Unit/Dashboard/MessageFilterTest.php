<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\MessageFilter;
use PHPUnit\Framework\TestCase;

final class MessageFilterTest extends TestCase
{
    public function testDefaults(): void
    {
        $filter = MessageFilter::fromQuery([]);

        self::assertSame([], $filter->addresses);
        self::assertFalse($filter->oldestFirst);
        self::assertSame(1, $filter->page);
        self::assertFalse($filter->isActive());
        self::assertSame('', $filter->query());
    }

    public function testAddressesAreNormalizedAndDeduplicated(): void
    {
        $filter = MessageFilter::fromQuery(['address' => [' Alice@Test.fr', 'alice@test.fr', 'bob@test.fr', '', 12, ['x']]]);

        self::assertSame(['alice@test.fr', 'bob@test.fr'], $filter->addresses);
        self::assertTrue($filter->isActive());
    }

    public function testSingleAddress(): void
    {
        self::assertSame(['alice@test.fr'], MessageFilter::fromQuery(['address' => 'alice@test.fr'])->addresses);
    }

    public function testTooLongAddressIsIgnored(): void
    {
        self::assertSame([], MessageFilter::fromQuery(['address' => [str_repeat('a', 321)]])->addresses);
    }

    public function testAddressesAreLimited(): void
    {
        $addresses = array_map(static fn (int $i): string => "u$i@test.fr", range(1, MessageFilter::MAX_ADDRESSES + 10));

        self::assertCount(MessageFilter::MAX_ADDRESSES, MessageFilter::fromQuery(['address' => $addresses])->addresses);
    }

    public function testNumericAddressKeysStayStrings(): void
    {
        // Une clé de tableau PHP « 123 » devient un entier : elle doit rester un texte.
        self::assertSame(['123'], MessageFilter::fromQuery(['address' => ['123']])->addresses);
    }

    public function testSortAndPage(): void
    {
        $filter = MessageFilter::fromQuery(['sort' => 'asc', 'page' => '3']);

        self::assertTrue($filter->oldestFirst);
        self::assertSame(3, $filter->page);
        self::assertTrue($filter->isActive());
        self::assertFalse(MessageFilter::fromQuery(['sort' => 'desc'])->oldestFirst);
    }

    public function testInvalidPageFallsBackToFirst(): void
    {
        foreach (['0', '-2', 'abc', '1.5', '1234567', ['2']] as $page) {
            self::assertSame(1, MessageFilter::fromQuery(['page' => $page])->page, var_export($page, true));
        }
    }

    public function testQueryString(): void
    {
        $filter = MessageFilter::fromQuery(['address' => ['alice@test.fr', 'bob+x@test.fr'], 'sort' => 'asc', 'page' => '2']);

        self::assertSame(
            '?address%5B0%5D=alice%40test.fr&address%5B1%5D=bob%2Bx%40test.fr&sort=asc&page=2',
            $filter->query(),
        );
        // Changement de page, retour au tri par défaut, paramètre ajouté.
        self::assertSame(
            '?address%5B0%5D=alice%40test.fr&address%5B1%5D=bob%2Bx%40test.fr&tab=headers',
            $filter->query(['page' => 1, 'oldestFirst' => false], ['tab' => 'headers']),
        );
        // Un paramètre null est omis.
        self::assertSame('', MessageFilter::fromQuery([])->query([], ['tab' => null]));
    }
}
