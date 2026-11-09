<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\HealthReport;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calculs de la page « Statut » : état, créneaux de 5 minutes, disponibilité, coupures.
 */
final class HealthReportTest extends TestCase
{
    /** Heure fixe des tests : le créneau en cours commence à 12:00:00, la période le 08/11 à 12:05:00. */
    private const string NOW = '2026-11-09T12:02:30Z';

    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    /**
     * Un ping toutes les 30 secondes de $from à $to (inclus), sauf dans [$skipFrom, $skipTo[.
     *
     * @param callable(DateTimeImmutable): ?int|null $latency
     * @return list<array{received_at: DateTimeImmutable, latency_ms: ?int, pending: ?int, failed: ?int, label: ?string}>
     */
    private static function pings(string $from, string $to, ?string $skipFrom = null, ?string $skipTo = null, ?callable $latency = null): array
    {
        $pings = [];
        for ($t = self::at($from); $t <= self::at($to); $t = $t->modify('+30 seconds')) {
            if ($skipFrom !== null && $t >= self::at($skipFrom) && $t < self::at((string) $skipTo)) {
                continue;
            }
            $pings[] = ['received_at' => $t, 'latency_ms' => $latency === null ? 80 : $latency($t), 'pending' => 0, 'failed' => 0, 'label' => null];
        }

        return $pings;
    }

    /** @return array<string, int> nombre de créneaux par état */
    private static function states(HealthReport $report): array
    {
        $counts = array_count_values(array_column($report->slots, 'state'));
        ksort($counts);

        return $counts;
    }

    public function testWindowIsAlignedOnFiveMinuteSlots(): void
    {
        self::assertEquals(self::at('2026-11-08T12:05:00Z'), HealthReport::windowStart(self::at(self::NOW)));
        self::assertEquals(self::at('2026-11-08T12:05:00Z'), HealthReport::windowStart(self::at('2026-11-09T12:00:00Z')));
        self::assertEquals(self::at('2026-11-08T12:10:00Z'), HealthReport::windowStart(self::at('2026-11-09T12:05:00.5Z')));
        self::assertSame(288, HealthReport::SLOTS);
    }

    public function testWithoutAnyPing(): void
    {
        $report = new HealthReport([], null, self::at(self::NOW));

        self::assertSame('unknown', $report->state);
        self::assertNull($report->last);
        self::assertNull($report->lastSeen);
        self::assertNull($report->secondsSinceLastPing());
        self::assertSame(['none' => 288], self::states($report));
        self::assertNull($report->availability);
        self::assertSame([], $report->outages);
        self::assertSame(0, $report->downtime);
        self::assertNull($report->medianLatency);
        self::assertSame(100, $report->scale());
    }

    public function testConnectedWithAnOutage(): void
    {
        // 24 h de pings, sauf de 06:00:00 à 06:20:00 : 4 créneaux sans aucun ping.
        $pings = self::pings(
            '2026-11-08T12:05:00Z',
            '2026-11-09T12:02:00Z',
            '2026-11-09T06:00:00Z',
            '2026-11-09T06:20:00Z',
            static fn (DateTimeImmutable $t): ?int => match ($t->format('H:i:s')) {
                '12:05:00' => null,  // premier ping : pas encore de mesure
                '10:00:30' => 900,   // un pic
                default    => 80,
            },
        );
        $report = new HealthReport($pings, self::at('2026-11-08T12:04:40Z'), self::at(self::NOW));

        self::assertSame('connected', $report->state);
        self::assertSame(30, $report->secondsSinceLastPing());
        self::assertEquals(self::at('2026-11-09T12:02:00Z'), $report->last['received_at']);
        self::assertNull($report->monitoredSince, 'l\'historique remonte avant la période');
        self::assertSame(count($pings), $report->pings);

        self::assertSame(['down' => 4, 'up' => 284], self::states($report));
        self::assertSame(98.6, $report->availability);
        $slots = array_values(array_filter($report->slots, static fn (array $slot): bool => $slot['state'] === 'down'));
        self::assertEquals(self::at('2026-11-09T06:00:00Z'), $slots[0]['start']);
        self::assertEquals(self::at('2026-11-09T06:15:00Z'), $slots[3]['start']);

        // Créneau du pic : 10 pings, médiane 80, maximum 900.
        $peak = $report->slots[(int) ((self::at('2026-11-09T10:00:00Z')->getTimestamp() - $report->from->getTimestamp()) / 300)];
        self::assertSame(['pings' => 10, 'median' => 80, 'max' => 900], array_intersect_key($peak, ['pings' => 0, 'median' => 0, 'max' => 0]));
        self::assertSame(80, $report->medianLatency);
        self::assertSame(900, $report->maxLatency);
        self::assertSame(1000, $report->scale());

        // Dernier créneau (en cours) : 12:00:00 à 12:02:30.
        $current = $report->slots[287];
        self::assertSame('up', $current['state']);
        self::assertEquals(self::at('2026-11-09T12:00:00Z'), $current['start']);
        self::assertEquals(self::at(self::NOW), $current['end']);

        // Une coupure : du dernier ping avant (05:59:30) au premier ping après (06:20:00).
        self::assertEquals([[
            'start'   => self::at('2026-11-09T05:59:30Z'),
            'end'     => self::at('2026-11-09T06:20:00Z'),
            'seconds' => 1230,
        ]], $report->outages);
        self::assertSame(1230, $report->downtime);
    }

    public function testDisconnectedSinceTheLastPing(): void
    {
        // Pings de 10:00 à 11:00 seulement, sans historique avant.
        $report = new HealthReport(self::pings('2026-11-09T10:00:00Z', '2026-11-09T11:00:00Z'), null, self::at(self::NOW));

        self::assertSame('disconnected', $report->state);
        self::assertEquals(self::at('2026-11-09T11:00:00Z'), $report->lastSeen);
        self::assertSame(3750, $report->secondsSinceLastPing());
        self::assertEquals(self::at('2026-11-09T10:00:00Z'), $report->monitoredSince);

        // Avant le premier ping : non compté. 10:00 à 11:00 (13 créneaux) : up. Ensuite : down,
        // y compris le créneau en cours.
        self::assertSame(['down' => 12, 'none' => 263, 'up' => 13], self::states($report));
        self::assertSame('down', $report->slots[287]['state']);
        self::assertSame(52.0, $report->availability);

        // Coupure en cours depuis le dernier ping.
        self::assertEquals([['start' => self::at('2026-11-09T11:00:00Z'), 'end' => null, 'seconds' => 3750]], $report->outages);
        self::assertSame(3750, $report->downtime);
    }

    public function testCurrentSlotWithoutPingYetIsNotCountedWhileConnected(): void
    {
        // 12:00:20 : le dernier ping (11:59:50) date de 30 s, le créneau de 12:00 n'en a pas encore.
        $now = self::at('2026-11-09T12:00:20Z');
        $report = new HealthReport(self::pings('2026-11-09T11:00:00Z', '2026-11-09T11:59:50Z'), null, $now);

        self::assertSame('connected', $report->state);
        self::assertSame('current', $report->slots[287]['state']);
        self::assertSame(100.0, $report->availability);
        self::assertSame([], $report->outages);
    }

    public function testOnlyAnOlderPing(): void
    {
        // Dernier ping il y a 26 h 30 (conservé 48 h) : aucun ping sur 24 h, tout est coupure.
        $report = new HealthReport([], self::at('2026-11-08T10:00:00Z'), self::at(self::NOW));

        self::assertSame('disconnected', $report->state);
        self::assertNull($report->last);
        self::assertEquals(self::at('2026-11-08T10:00:00Z'), $report->lastSeen);
        self::assertSame(['down' => 288], self::states($report));
        self::assertSame(0.0, $report->availability);
        self::assertEquals([['start' => self::at('2026-11-08T10:00:00Z'), 'end' => null, 'seconds' => 93750]], $report->outages);
        self::assertSame(86250, $report->downtime, 'durée comptée depuis le début de la période');
    }

    public function testOutageThresholdIs90Seconds(): void
    {
        $ping = static fn (string $time): array => ['received_at' => self::at($time), 'latency_ms' => 50, 'pending' => null, 'failed' => null, 'label' => 'mailpit-prod'];
        $pings = [
            $ping('2026-11-09T11:00:00Z'),
            $ping('2026-11-09T11:01:30Z'), // 90 s : pas une coupure
            $ping('2026-11-09T11:03:01Z'), // 91 s : coupure
            $ping('2026-11-09T11:10:00Z'), // 419 s : coupure
            $ping('2026-11-09T12:01:00Z'), // la plus récente
        ];
        $report = new HealthReport($pings, null, self::at(self::NOW));

        self::assertSame('connected', $report->state, '90 s après le dernier ping');
        self::assertSame(['2026-11-09T11:10:00', '2026-11-09T11:03:01', '2026-11-09T11:01:30'], array_map(
            static fn (array $outage): string => $outage['start']->format('Y-m-d\TH:i:s'),
            $report->outages,
        ), 'la plus récente d\'abord');
        self::assertSame([3060, 419, 91], array_column($report->outages, 'seconds'));
        self::assertSame(3570, $report->downtime);
        self::assertSame('mailpit-prod', $report->last['label']);
        self::assertNull($report->last['pending']);

        // 91 s après le dernier ping : déconnecté.
        $report = new HealthReport($pings, null, self::at('2026-11-09T12:02:31Z'));
        self::assertSame('disconnected', $report->state);
        self::assertNull($report->outages[0]['end']);
        self::assertSame(91, $report->outages[0]['seconds']);
    }

    public function testLatencyNullIsNotMeasured(): void
    {
        $report = new HealthReport(self::pings('2026-11-09T12:00:00Z', '2026-11-09T12:02:00Z', latency: static fn (): ?int => null), null, self::at(self::NOW));

        self::assertSame('up', $report->slots[287]['state']);
        self::assertSame(5, $report->slots[287]['pings']);
        self::assertNull($report->slots[287]['median']);
        self::assertNull($report->slots[287]['max']);
        self::assertNull($report->medianLatency);
    }

    public function testMedianOfAnEvenNumberOfValues(): void
    {
        $values = [10, 40, 20, 30];
        $pings = [];
        foreach ($values as $i => $value) {
            $pings[] = ['received_at' => self::at('2026-11-09T12:00:00Z')->modify(sprintf('+%d seconds', 30 * $i)), 'latency_ms' => $value, 'pending' => 0, 'failed' => 0, 'label' => null];
        }
        $report = new HealthReport($pings, null, self::at(self::NOW));

        self::assertSame(25, $report->slots[287]['median']);
        self::assertSame(40, $report->slots[287]['max']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function scales(): iterable
    {
        yield 'petit'           => [12, 100];
        yield 'juste 100'       => [100, 100];
        yield 'au-dessus'       => [101, 200];
        yield '450 ms'          => [450, 500];
        yield '1,2 s'           => [1200, 2000];
        yield '7 s'             => [7000, 10000];
    }

    #[DataProvider('scales')]
    public function testScale(int $max, int $expected): void
    {
        $ping = ['received_at' => self::at('2026-11-09T12:00:00Z'), 'latency_ms' => $max, 'pending' => 0, 'failed' => 0, 'label' => null];

        self::assertSame($expected, new HealthReport([$ping], null, self::at(self::NOW))->scale());
    }

    /** @return iterable<string, array{int, string}> */
    public static function durations(): iterable
    {
        yield 'secondes'           => [45, '45 s'];
        yield 'minutes rondes'     => [180, '3 min'];
        yield 'minutes et secondes' => [200, '3 min 20 s'];
        yield 'zéro devant'        => [185, '3 min 05 s'];
        yield 'heures'             => [7500, '2 h 05 min'];
        yield 'jours'              => [97200, '1 j 3 h'];
        yield 'négatif'            => [-5, '0 s'];
    }

    #[DataProvider('durations')]
    public function testDuration(int $seconds, string $expected): void
    {
        self::assertSame($expected, HealthReport::duration($seconds));
    }
}
