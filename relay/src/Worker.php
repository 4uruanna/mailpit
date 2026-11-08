<?php

declare(strict_types=1);

namespace Relay;

use Throwable;

/**
 * Traite la file d'attente : lit chaque mail complet dans Mailpit puis l'envoie à l'API.
 * En cas d'échec passager, le mail est réessayé plus tard (30 s, 1 min, 2 min… jusqu'à 1 h).
 */
final readonly class Worker
{
    /** Réponses de l'API qui ne changeront pas en réessayant : le mail est abandonné. */
    private const array PERMANENT_ERRORS = [400, 413, 415, 422];

    private const int MIN_DELAY = 30;
    private const int MAX_DELAY = 3600;

    public function __construct(
        private Config $config,
        private Spool $spool,
        private MailpitClient $mailpit,
    ) {
    }

    /** Traite un mail si l'un d'eux est prêt. Retourne false si la file est vide (ou en attente). */
    public function tick(): bool
    {
        $next = $this->spool->nextDue(time());
        if ($next === null) {
            return false;
        }

        ['file' => $file, 'job' => $job] = $next;
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;

        try {
            $status = $this->forward($job);
        } catch (TransientException $e) {
            $this->retryLater($file, $job, $e->getMessage());

            return true;
        } catch (Throwable $e) {
            // Bug ou donnée inattendue : on garde le mail, on réessaiera.
            $this->retryLater($file, $job, $e::class . ' : ' . $e->getMessage());

            return true;
        }

        if ($status >= 200 && $status <= 299) {
            $this->spool->remove($file);
            Log::info(sprintf('mail %s envoyé à l\'API (HTTP %d, essai %d)', $job['id'], $status, $job['attempts']));
        } elseif (in_array($status, self::PERMANENT_ERRORS, true)) {
            $job['last_error'] = sprintf('API : HTTP %d (refus définitif)', $status);
            $this->spool->fail($file, $job);
            Log::error(sprintf('mail %s refusé par l\'API (HTTP %d) : abandonné, voir failed/', $job['id'], $status));
        } else {
            $this->retryLater($file, $job, sprintf('API : HTTP %d', $status));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $job
     * @return int code HTTP de l'API
     * @throws TransientException
     */
    private function forward(array $job): int
    {
        $id = $job['id'];
        $payload = $job['summary'];

        $message = $this->mailpit->message($id);
        if ($message === null) {
            Log::warning(sprintf('mail %s introuvable dans Mailpit (supprimé ?) : envoi du résumé seul', $id));
        } else {
            // La lecture via l'API marque le message comme lu : on rétablit l'état d'origine.
            if ($this->config->keepUnread && ($payload['Read'] ?? false) === false) {
                $this->mailpit->markUnread($id);
            }

            $payload['Text'] = is_string($message['Text'] ?? null) ? $message['Text'] : '';
            $payload['HTML'] = is_string($message['HTML'] ?? null) ? $message['HTML'] : '';

            if ($this->config->includeRaw) {
                $size = (int) ($payload['Size'] ?? 0);
                if ($size > $this->config->maxRawSize) {
                    Log::warning(sprintf('mail %s : %d octets > RELAY_MAX_RAW_SIZE, mail brut non joint', $id, $size));
                } else {
                    $raw = $this->mailpit->raw($id);
                    if ($raw !== null) {
                        $payload['Raw'] = base64_encode($raw);
                    }
                }
            }
        }

        $headers = ['Content-Type: application/json'];
        if (is_string($job['label'] ?? null) && $job['label'] !== '') {
            $headers[] = 'Mailpit-Label: ' . str_replace(["\r", "\n"], '', $job['label']);
        }

        $response = Http::request(
            'POST',
            $this->config->targetUrl,
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            null,
            $this->config->timeout,
        );

        if ($response['status'] === 0) {
            throw new TransientException('API injoignable : ' . $response['error']);
        }

        return $response['status'];
    }

    /** @param array<string, mixed> $job */
    private function retryLater(string $file, array $job, string $error): void
    {
        $job['last_error'] = $error;

        if ($job['attempts'] >= $this->config->maxAttempts) {
            $this->spool->fail($file, $job);
            Log::error(sprintf('mail %s abandonné après %d essais (%s), voir failed/', $job['id'], $job['attempts'], $error));

            return;
        }

        $delay = min(self::MAX_DELAY, self::MIN_DELAY * 2 ** ($job['attempts'] - 1));
        $this->spool->reschedule($file, $job, time() + $delay);
        Log::warning(sprintf('mail %s : échec de l\'essai %d (%s), nouvel essai dans %d s', $job['id'], $job['attempts'], $error, $delay));
    }
}
