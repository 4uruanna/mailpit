<?php

declare(strict_types=1);

namespace Relay;

use Closure;

/**
 * Ping de santé du relais (bin/heartbeat.php, toutes les RELAY_HEARTBEAT_INTERVAL secondes) :
 * compte les mails en attente et refusés dans Mailpit, puis envoie POST {RELAY_API_URL}/v1/health
 * avec la latence du ping précédent. L'API s'en sert pour la page « Statut » du Dashboard.
 *
 * Mailpit injoignable : le ping part quand même, avec des compteurs à null.
 *
 * Les échecs ne remplissent pas le journal : une ligne au premier échec, puis un rappel toutes les
 * 10 minutes tant que l'échec dure, et une ligne au rétablissement (séparément pour l'API et Mailpit).
 */
final class Heartbeat
{
    /** Délai maximal de chaque requête du ping (secondes), RELAY_TIMEOUT s'il est plus court. */
    public const int TIMEOUT = 10;

    /** Pendant un échec qui dure, un rappel dans le journal toutes les 10 minutes. */
    public const int REMINDER = 10 * 60;

    /** Latence du dernier ping accepté (ms), envoyée avec le ping suivant ; null après un échec. */
    private ?int $latency = null;

    /** @var array<string, array{since: float, count: int, logged: float}> échecs en cours, par service */
    private array $failures = [];

    /** @var Closure(): float horloge (secondes), remplaçable dans les tests */
    private readonly Closure $clock;

    public function __construct(
        private readonly Config $config,
        private readonly MailpitClient $mailpit,
        private readonly ApiClient $api,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Un ping. Renvoie true si l'API l'a enregistré. */
    public function beat(): bool
    {
        $now = ($this->clock)();

        try {
            $pending = $this->mailpit->search($this->config->pendingQuery(), 1)['total'];
            $failed = $this->mailpit->search('tag:' . $this->config->tagFailed, 1)['total'];
            $this->recovered('Mailpit', 'compteurs du ping de santé de nouveau lus dans Mailpit', $now);
        } catch (TransientException $e) {
            $pending = null;
            $failed = null;
            $this->failed('Mailpit', 'ping de santé envoyé sans les compteurs : ' . $e->getMessage(), $now);
        }

        try {
            $this->latency = $this->api->health($this->latency, $pending, $failed);
            $this->recovered('API', 'ping de santé de nouveau accepté par l\'API', $now);

            return true;
        } catch (TransientException $e) {
            // La durée d'un ping perdu ne veut rien dire : le suivant part sans latence.
            $this->latency = null;
            $this->failed('API', 'ping de santé non enregistré : ' . $e->getMessage(), $now);

            return false;
        }
    }

    private function failed(string $service, string $message, float $now): void
    {
        $failure = $this->failures[$service] ?? null;
        if ($failure === null) {
            $this->failures[$service] = ['since' => $now, 'count' => 1, 'logged' => $now];
            Log::warning(sprintf('%s (rappel toutes les %d minutes tant que l\'échec dure)', $message, intdiv(self::REMINDER, 60)));

            return;
        }

        $failure['count']++;
        if ($now - $failure['logged'] >= self::REMINDER) {
            $failure['logged'] = $now;
            Log::warning(sprintf('%s (en échec depuis %s, %d essais)', $message, self::duration($now - $failure['since']), $failure['count']));
        }
        $this->failures[$service] = $failure;
    }

    private function recovered(string $service, string $message, float $now): void
    {
        $failure = $this->failures[$service] ?? null;
        if ($failure === null) {
            return;
        }

        unset($this->failures[$service]);
        Log::info(sprintf('%s, après %s d\'échec (%d essai(s) en échec)', $message, self::duration($now - $failure['since']), $failure['count']));
    }

    /** « 45 s », « 12 min ». */
    private static function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return $seconds < 60 ? sprintf('%d s', $seconds) : sprintf('%d min', intdiv($seconds, 60));
    }
}
