<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\HealthRepository;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;

final class HealthRepositoryTest extends DatabaseTestCase
{
    private HealthRepository $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = new HealthRepository($this->database);
    }

    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    public function testRecordAndRead(): void
    {
        // Heure donnée dans un autre fuseau : enregistrée en UTC, avec les microsecondes.
        $this->health->record(new DateTimeImmutable('2026-11-09T13:00:00.250000', new DateTimeZone('Europe/Paris')), null, 3, 1, '203.0.113.10', 'mailpit-prod');
        $this->health->record(self::at('2026-11-09T12:00:30Z'), 85, null, null, '203.0.113.10', null);

        self::assertSame('2026-11-09 12:00:00.250000', $this->scalar('SELECT received_at FROM mailpit_health_pings WHERE id = 1'));
        self::assertSame('203.0.113.10', $this->scalar('SELECT source_ip FROM mailpit_health_pings WHERE id = 1'));

        self::assertEquals([
            ['received_at' => self::at('2026-11-09T12:00:00.25Z'), 'latency_ms' => null, 'pending' => 3, 'failed' => 1, 'label' => 'mailpit-prod'],
            ['received_at' => self::at('2026-11-09T12:00:30Z'), 'latency_ms' => 85, 'pending' => null, 'failed' => null, 'label' => null],
        ], $this->health->since(self::at('2026-11-09T12:00:00Z')));
        self::assertCount(1, $this->health->since(self::at('2026-11-09T12:00:00.5Z')));
        self::assertSame([], $this->health->since(self::at('2026-11-09T12:01:00Z')));
    }

    public function testLastBeforeAndPurge(): void
    {
        foreach (['2026-11-07T11:00:00Z', '2026-11-08T11:59:00Z', '2026-11-09T12:00:00Z'] as $date) {
            $this->health->record(self::at($date), 50, 0, 0, '203.0.113.10', null);
        }

        self::assertEquals(self::at('2026-11-08T11:59:00Z'), $this->health->lastBefore(self::at('2026-11-08T12:00:00Z')));
        self::assertNull($this->health->lastBefore(self::at('2026-11-07T11:00:00Z')));

        self::assertSame(1, $this->health->purgeBefore(self::at('2026-11-07T12:00:00Z')));
        self::assertSame(0, $this->health->purgeBefore(self::at('2026-11-07T12:00:00Z')));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_health_pings'));
    }

    public function testLongLabelIsTruncated(): void
    {
        $this->health->record(self::at('2026-11-09T12:00:00Z'), null, null, null, '203.0.113.10', str_repeat('é', 300));

        self::assertSame(str_repeat('é', 255), $this->health->since(self::at('2026-11-09T00:00:00Z'))[0]['label']);
    }
}
