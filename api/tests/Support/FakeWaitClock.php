<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\WaitClock;

/**
 * Horloge des tests de GET /v1/messages?wait=… : sleep() avance le temps sans attendre, puis
 * appelle $onSleep (par exemple pour enregistrer un mail pendant l'attente).
 */
final class FakeWaitClock implements WaitClock
{
    /** @var list<float> durée de chaque pause demandée, en secondes */
    public array $sleeps = [];
    public bool $clientGone = false;

    /** @var (callable(int): void)|null reçoit le numéro de la pause (1, 2…) */
    private $onSleep = null;

    public function __construct(private float $time = 1000.0)
    {
    }

    /** @param callable(int): void $callback appelé après chaque pause, avec son numéro (1, 2…) */
    public function onSleep(callable $callback): void
    {
        $this->onSleep = $callback;
    }

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
        if ($this->onSleep !== null) {
            ($this->onSleep)(count($this->sleeps));
        }
    }

    public function clientGone(): bool
    {
        return $this->clientGone;
    }
}
