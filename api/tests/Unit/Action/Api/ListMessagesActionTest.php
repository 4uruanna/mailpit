<?php

declare(strict_types=1);

namespace App\Tests\Unit\Action\Api;

use App\Action\Api\ListMessagesAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lecture de « since » et limite de durée de PHP pour « wait » (GET /v1/messages).
 */
final class ListMessagesActionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validDates(): iterable
    {
        yield 'UTC'                   => ['2026-11-09T14:03:00Z', '2026-11-09 14:03:00.000000'];
        yield 'minuscules'            => ['2026-11-09t14:03:00z', '2026-11-09 14:03:00.000000'];
        yield 'sans secondes'         => ['2026-11-09T14:03Z', '2026-11-09 14:03:00.000000'];
        yield 'millisecondes'         => ['2026-11-09T14:03:00.809Z', '2026-11-09 14:03:00.809000'];
        yield 'nanosecondes, fuseau'  => ['2026-11-09T15:03:00.123456789+01:00', '2026-11-09 14:03:00.123456'];
        yield 'fuseau sans deux-points' => ['2026-11-09T12:33:00-0130', '2026-11-09 14:03:00.000000'];
        yield '« + » devenu espace'   => ['2026-11-09T15:03:00 01:00', '2026-11-09 14:03:00.000000'];
    }

    #[DataProvider('validDates')]
    public function testValidDates(string $value, string $utc): void
    {
        $date = ListMessagesAction::date($value);

        self::assertNotNull($date);
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame($utc, $date->format('Y-m-d H:i:s.u'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDates(): iterable
    {
        yield 'texte'              => ['hier'];
        yield 'date seule'         => ['2026-11-09'];
        yield 'sans fuseau'        => ['2026-11-09T14:03:00'];
        yield 'jour inexistant'    => ['2026-02-30T10:00:00Z'];
        yield 'mois inexistant'    => ['2026-13-01T10:00:00Z'];
        yield '24 h'               => ['2026-11-09T24:00:00Z'];
        yield 'timestamp'          => ['1762697000'];
        yield 'espaces autour'     => [' 2026-11-09T14:03:00Z'];
        yield 'avant l\'an 1000'   => ['0999-12-31T23:00:00Z'];
        yield 'après 9998 en UTC'  => ['9999-12-31T23:30:00-01:00'];
        yield 'tableau'            => [['2026-11-09T14:03:00Z']];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDates(mixed $value): void
    {
        self::assertNull(ListMessagesAction::date($value));
    }

    public function testTimeLimit(): void
    {
        // Sans limite (ligne de commande, ou max_execution_time = 0) : rien à changer.
        self::assertNull(ListMessagesAction::timeLimit(60, 0));
        // Limite trop courte : attente + marge.
        self::assertSame(90, ListMessagesAction::timeLimit(60, 30));
        self::assertSame(40, ListMessagesAction::timeLimit(10, 30));
        // Jamais réduite.
        self::assertSame(165, ListMessagesAction::timeLimit(60, 165));
    }
}
