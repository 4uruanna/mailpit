<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\SystemWaitClock;
use PHPUnit\Framework\TestCase;

final class SystemWaitClockTest extends TestCase
{
    public function testSleepWaitsAndNowFollows(): void
    {
        $clock = new SystemWaitClock();

        $start = $clock->now();
        $clock->sleep(0.05);
        $elapsed = $clock->now() - $start;

        self::assertGreaterThanOrEqual(0.05, $elapsed);
        self::assertLessThan(1.0, $elapsed);
    }

    public function testZeroOrNegativeSleepDoesNotWait(): void
    {
        $clock = new SystemWaitClock();

        $start = $clock->now();
        $clock->sleep(0.0);
        $clock->sleep(-1.0);

        self::assertLessThan(0.05, $clock->now() - $start);
    }

    public function testClientIsNeverGoneOnTheCommandLine(): void
    {
        self::assertFalse(new SystemWaitClock()->clientGone());
    }
}
