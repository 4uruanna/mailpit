<?php

declare(strict_types=1);

namespace App\Dashboard;

use DateTimeImmutable;
use DateTimeZone;

/**
 * État de la connexion du relais sur les dernières 24 heures (page « Statut » du Dashboard),
 * calculé à partir des pings de santé (POST /v1/health, toutes les 30 secondes).
 *
 *  - état : « connected » si le dernier ping date de 90 secondes au plus, « disconnected » sinon,
 *    « unknown » si aucun ping n'a jamais été reçu (ou plus aucun depuis 48 heures) ;
 *  - créneaux de 5 minutes (288 sur 24 heures, alignés sur l'horloge) : « up » (au moins un ping,
 *    avec médiane et maximum de la latence), « down » (aucun ping), « none » (avant le premier ping,
 *    non compté), « current » (créneau en cours, encore sans ping alors que le relais est connecté :
 *    non compté) ;
 *  - disponibilité : part des créneaux « up » parmi les créneaux « up » et « down » ;
 *  - coupures : intervalles de plus de 90 secondes entre deux pings (ou depuis le dernier ping).
 *
 * Aucune base ici : les pings sont passés au constructeur (voir HealthRepository), ce qui permet
 * de tester tous les calculs avec une heure fixe.
 */
final readonly class HealthReport
{
    /** Période affichée : 24 heures. */
    public const int WINDOW = 24 * 60 * 60;

    /** Durée d'un créneau du graphique : 5 minutes. */
    public const int SLOT = 5 * 60;

    /** Nombre de créneaux : 288. */
    public const int SLOTS = self::WINDOW / self::SLOT;

    /** Sans ping depuis plus de 90 secondes (3 pings manqués) : déconnecté, et c'est une coupure. */
    public const int TIMEOUT = 90;

    /** « connected », « disconnected » ou « unknown » (aucun ping). */
    public string $state;

    /** @var array{received_at: DateTimeImmutable, latency_ms: ?int, pending: ?int, failed: ?int, label: ?string}|null dernier ping de la période */
    public ?array $last;

    /** Heure du dernier ping (même avant la période), null si aucun. */
    public ?DateTimeImmutable $lastSeen;

    /** Début de la période (début du premier créneau). */
    public DateTimeImmutable $from;

    /** @var list<array{start: DateTimeImmutable, end: DateTimeImmutable, state: string, pings: int, median: ?int, max: ?int}> */
    public array $slots;

    /** Disponibilité en %, ou null si aucun créneau n'est compté. */
    public ?float $availability;

    /** Début du suivi si l'historique fait moins de 24 heures (premier ping de la période), sinon null. */
    public ?DateTimeImmutable $monitoredSince;

    /** @var list<array{start: DateTimeImmutable, end: ?DateTimeImmutable, seconds: int}> coupures, la plus récente d'abord ; end = null : en cours */
    public array $outages;

    /** Durée totale des coupures dans la période (secondes). */
    public int $downtime;

    /** Nombre de pings reçus dans la période. */
    public int $pings;

    /** Médiane et maximum de la latence sur la période (ms), null si aucune mesure. */
    public ?int $medianLatency;
    public ?int $maxLatency;

    /**
     * @param list<array{received_at: DateTimeImmutable, latency_ms: ?int, pending: ?int, failed: ?int, label: ?string}> $pings
     *        pings reçus depuis windowStart($now), du plus ancien au plus récent
     * @param DateTimeImmutable|null $previous dernier ping reçu avant windowStart($now) (l'historique remonte plus loin), ou null
     */
    public function __construct(array $pings, ?DateTimeImmutable $previous, public DateTimeImmutable $now)
    {
        $nowTs = self::ts($now);
        $this->from = self::windowStart($now);
        $fromTs = self::ts($this->from);
        $this->pings = count($pings);
        $this->last = $pings === [] ? null : $pings[array_key_last($pings)];
        $this->lastSeen = $this->last['received_at'] ?? $previous;

        $this->state = match (true) {
            $this->lastSeen === null                              => 'unknown',
            $nowTs - self::ts($this->lastSeen) <= self::TIMEOUT => 'connected',
            default                                               => 'disconnected',
        };

        // Sans historique avant la période, les créneaux d'avant le premier ping ne comptent pas.
        $this->monitoredSince = $previous === null && $pings !== [] ? $pings[0]['received_at'] : null;

        // Pings et latences de chaque créneau.
        $counts = array_fill(0, self::SLOTS, 0);
        $latencies = array_fill(0, self::SLOTS, []);
        $all = [];
        foreach ($pings as $ping) {
            $index = max(0, min(self::SLOTS - 1, intdiv((int) floor(self::ts($ping['received_at']) - $fromTs), self::SLOT)));
            $counts[$index]++;
            if ($ping['latency_ms'] !== null) {
                $latencies[$index][] = $ping['latency_ms'];
                $all[] = $ping['latency_ms'];
            }
        }

        $slots = [];
        $up = 0;
        $down = 0;
        for ($i = 0; $i < self::SLOTS; $i++) {
            $start = $this->from->modify(sprintf('+%d seconds', $i * self::SLOT));
            $end = $i === self::SLOTS - 1 ? $now : $start->modify(sprintf('+%d seconds', self::SLOT));
            $state = match (true) {
                $counts[$i] > 0 => 'up',
                $this->lastSeen === null,
                $this->monitoredSince !== null && self::ts($end) <= self::ts($this->monitoredSince) => 'none',
                $i === self::SLOTS - 1 && $this->state === 'connected' => 'current',
                default => 'down',
            };
            if ($state === 'up') {
                $up++;
            } elseif ($state === 'down') {
                $down++;
            }
            $slots[] = [
                'start'  => $start,
                'end'    => $end,
                'state'  => $state,
                'pings'  => $counts[$i],
                'median' => self::median($latencies[$i]),
                'max'    => $latencies[$i] === [] ? null : max($latencies[$i]),
            ];
        }
        $this->slots = $slots;
        $this->availability = $up + $down === 0 ? null : round(100 * $up / ($up + $down), 1);
        $this->medianLatency = self::median($all);
        $this->maxLatency = $all === [] ? null : max($all);

        // Coupures : plus de 90 secondes sans ping, entre deux pings ou depuis le dernier.
        $times = array_map(static fn (array $ping): DateTimeImmutable => $ping['received_at'], $pings);
        if ($previous !== null) {
            array_unshift($times, $previous);
        }
        $outages = [];
        $downtime = 0.0;
        foreach ($times as $i => $start) {
            $end = $times[$i + 1] ?? null;
            $endTs = $end === null ? $nowTs : self::ts($end);
            $seconds = $endTs - self::ts($start);
            if ($seconds <= self::TIMEOUT) {
                continue;
            }
            $outages[] = ['start' => $start, 'end' => $end, 'seconds' => (int) floor($seconds)];
            $downtime += $endTs - max($fromTs, self::ts($start));
        }
        $this->outages = array_reverse($outages);
        $this->downtime = (int) floor($downtime);
    }

    /** Début de la période affichée à l'heure $now : début du créneau en cours, moins 287 créneaux. */
    public static function windowStart(DateTimeImmutable $now): DateTimeImmutable
    {
        $current = intdiv((int) floor(self::ts($now)), self::SLOT) * self::SLOT;

        return new DateTimeImmutable('@' . ($current - (self::SLOTS - 1) * self::SLOT))->setTimezone(new DateTimeZone('UTC'));
    }

    /** Secondes écoulées depuis le dernier ping (null si aucun). */
    public function secondsSinceLastPing(): ?int
    {
        return $this->lastSeen === null ? null : max(0, (int) floor(self::ts($this->now) - self::ts($this->lastSeen)));
    }

    /**
     * Maximum de l'axe des latences (ms) : 1, 2 ou 5 × 10ⁿ, au-dessus du plus grand maximum
     * des créneaux, et 100 ms au moins.
     */
    public function scale(): int
    {
        $max = max(0, ...array_map(static fn (array $slot): int => $slot['max'] ?? 0, $this->slots));
        for ($power = 100; ; $power *= 10) {
            foreach ([1, 2, 5] as $factor) {
                if ($max <= $factor * $power) {
                    return $factor * $power;
                }
            }
        }
    }

    /** Durée lisible : « 45 s », « 3 min 20 s », « 2 h 05 min », « 1 j 3 h ». */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60    => sprintf('%d s', $seconds),
            $seconds < 3600  => $seconds % 60 === 0 ? sprintf('%d min', intdiv($seconds, 60)) : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60),
            $seconds < 86400 => sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
            default          => sprintf('%d j %d h', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600)),
        };
    }

    /** @param list<int> $values */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }

    /** Horodatage en secondes, avec les microsecondes. */
    private static function ts(DateTimeImmutable $date): float
    {
        return (float) $date->format('U.u');
    }
}
