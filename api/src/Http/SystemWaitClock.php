<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Horloge réelle : hrtime(), usleep() et connection_aborted().
 */
final readonly class SystemWaitClock implements WaitClock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    public function clientGone(): bool
    {
        return connection_aborted() === 1;
    }
}
