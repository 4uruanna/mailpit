<?php

declare(strict_types=1);

namespace Relay;

use Throwable;

/**
 * Relaie les mails de Mailpit vers l'API, sans rien enregistrer : c'est Mailpit qui sert de file d'attente.
 *
 * 1. Recherche dans Mailpit les mails qui n'ont ni le tag RELAY_TAG_DONE ni RELAY_TAG_FAILED,
 *    et les traite du plus ancien au plus récent (lots de RELAY_BATCH_SIZE mails).
 * 2. Pour chacun : lit le mail complet (texte, HTML, en-têtes, liste des pièces), l'envoie à l'API,
 *    puis envoie le contenu de chaque pièce que l'API réclame (une requête par pièce, avec SHA-256).
 * 3. Seulement quand l'API répond « complete », pose le tag RELAY_TAG_DONE dans Mailpit.
 *
 * Les mails sont traités strictement un par un : le message, puis ses pièces une à une, puis le tag,
 * avant de passer au mail suivant. Le relais n'envoie donc jamais deux requêtes en même temps à l'API
 * pour les mails. Seul le ping de santé (bin/heartbeat.php, voir Heartbeat) part à côté, depuis son
 * propre processus.
 *
 * Tant que le tag n'est pas posé, le mail est réessayé (30 s, 1 min, 2 min… puis toutes les heures),
 * même après un redémarrage du relais : aucun mail ne peut être perdu en route. L'API ignore
 * les doublons, renvoyer un mail est donc sans risque.
 * Un refus définitif de l'API (mail jugé invalide) pose le tag RELAY_TAG_FAILED (voir bin/retry-failed.php).
 */
final class Worker
{
    private const int MIN_DELAY = 30;
    private const int MAX_DELAY = 3600;

    /** Marge sur la taille annoncée par Mailpit lors du téléchargement d'une pièce (octets). */
    private const int DOWNLOAD_MARGIN = 1024 * 1024;

    /** Lots lus au plus par passage : 20 × 50 = les 1000 mails que garde Mailpit (MP_MAX_MESSAGES). */
    private const int MAX_WINDOWS = 20;

    /**
     * Mails en échec passager : prochain essai (en mémoire seulement, remis à zéro au redémarrage).
     *
     * @var array<string, array{attempts: int, next: int}>
     */
    private array $retries = [];

    public function __construct(
        private readonly Config $config,
        private readonly MailpitClient $mailpit,
        private readonly ApiClient $api,
    ) {
    }

    /**
     * Traite les mails en attente, du plus ancien au plus récent.
     *
     * Mailpit renvoie les résultats du plus récent au plus ancien. Si tout ne tient pas dans un lot,
     * on lit donc les lots en partant de la fin de la liste (les plus anciens, que MP_MAX_MESSAGES
     * et MP_MAX_AGE suppriment en premier), puis on remonte vers les plus récents. Un lot dont tous
     * les mails attendent leur nouvel essai ne bloque pas les suivants.
     *
     * Un passage s'arrête dès qu'il a traité RELAY_BATCH_SIZE mails (en finissant le lot en cours)
     * ou lu MAX_WINDOWS lots.
     *
     * @return bool true s'il reste probablement des mails prêts à traiter tout de suite
     */
    public function sync(): bool
    {
        $query = $this->config->pendingQuery();
        $batch = $this->config->batchSize;
        $seen = [];

        try {
            // Première recherche : les plus récents, et surtout le nombre de mails en attente.
            $first = $this->mailpit->search($query, $batch);

            // Cas courant : tous les mails en attente tiennent dans un lot.
            if ($first['total'] <= count($first['messages'])) {
                $this->processWindow($first['messages'], $seen);
                $this->forgetUnseen($seen);

                return false;
            }

            // Positions dans la recherche : 0 = le plus récent, total - 1 = le plus ancien.
            // Les mails que l'on relaie sortent de la recherche, mais ils sont plus anciens que
            // le lot suivant : sa position ne change pas. En revanche, un mail arrivé ou supprimé
            // pendant le passage décale tout. On le repère au total renvoyé par chaque recherche :
            // au pire, un mail n'est vu qu'au passage suivant, lancé aussitôt.
            $end = $first['total'];
            $expected = $first['total'];
            $consistent = true;
            $processed = 0;

            for ($windows = 0; $end > 0 && $windows < self::MAX_WINDOWS && $processed < $batch; $windows++) {
                $start = max(0, $end - $batch);
                $result = $this->mailpit->search($query, $end - $start, $start);
                $consistent = $consistent && $result['total'] === $expected;

                [$done, $removed] = $this->processWindow($result['messages'], $seen);
                $processed += $done;
                $expected = $result['total'] - $removed;
                $end = $start;
            }
        } catch (TransientException $e) {
            Log::warning('recherche des mails à relayer impossible : ' . $e->getMessage());

            return false;
        }

        // Tous les lots lus, sans décalage : on a vu tous les mails en attente.
        if ($end === 0 && $consistent) {
            $this->forgetUnseen($seen);
        }

        // Des mails plus récents n'ont pas été lus, ou un décalage a pu en faire sauter un : on
        // continue tout de suite, sauf si tous les mails lus attendaient leur nouvel essai.
        return $processed > 0 && ($end > 0 || !$consistent);
    }

    /**
     * Traite un lot de résultats de recherche, du plus ancien au plus récent, sauf les mails
     * qui attendent leur nouvel essai.
     *
     * @param list<array<string, mixed>> $messages résumés, dans l'ordre de Mailpit (plus récents d'abord)
     * @param array<string, true> $seen identifiants des mails vus, complété au passage
     * @return array{int, int} mails traités, et mails sortis de la recherche (relayés, refusés ou supprimés)
     */
    private function processWindow(array $messages, array &$seen): array
    {
        $processed = 0;
        $removed = 0;

        foreach (array_reverse($messages) as $summary) {
            $id = $summary['ID'] ?? null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                continue;
            }
            $seen[$id] = true;

            if (($this->retries[$id]['next'] ?? 0) > time()) {
                continue;
            }

            $processed++;
            if ($this->process($id, $summary)) {
                $removed++;
            }
        }

        return [$processed, $removed];
    }

    /**
     * Oublie les mails qui ne sont plus en attente (relayés ou supprimés de Mailpit).
     * À n'appeler que si $seen contient tous les mails en attente.
     *
     * @param array<string, true> $seen
     */
    private function forgetUnseen(array $seen): void
    {
        $this->retries = array_intersect_key($this->retries, $seen);
    }

    /**
     * @param array<string, mixed> $summary résumé du mail (résultat de recherche Mailpit)
     * @return bool true si le mail est sorti de la recherche : tag posé, ou mail supprimé de Mailpit
     */
    private function process(string $id, array $summary): bool
    {
        try {
            $this->deliver($id, $summary);
            unset($this->retries[$id]);

            return true;
        } catch (PermanentException $e) {
            unset($this->retries[$id]);
            Log::error(sprintf('mail %s refusé par l\'API (%s) : tag « %s » posé dans Mailpit', $id, $e->getMessage(), $this->config->tagFailed));
            try {
                $this->mailpit->setTags($id, self::withTag($summary, $this->config->tagFailed));

                return true;
            } catch (TransientException $tagError) {
                Log::warning(sprintf('mail %s : tag d\'échec non posé (%s), nouvel essai au prochain passage', $id, $tagError->getMessage()));

                return false;
            }
        } catch (TransientException $e) {
            $this->retryLater($id, $e->getMessage());
        } catch (Throwable $e) {
            // Bug ou donnée inattendue : le mail reste en attente dans Mailpit, on réessaiera.
            $this->retryLater($id, $e::class . ' : ' . $e->getMessage());
        }

        return false;
    }

    /**
     * @param array<string, mixed> $summary
     * @throws TransientException|PermanentException
     */
    private function deliver(string $id, array $summary): void
    {
        $message = $this->mailpit->message($id);
        $headers = $message === null ? null : $this->mailpit->headers($id);
        if ($message === null || $headers === null) {
            Log::warning(sprintf('mail %s supprimé de Mailpit avant d\'avoir été relayé', $id));

            return;
        }

        // La lecture via l'API de Mailpit marque le message comme lu : on rétablira l'état d'origine.
        $wasUnread = ($summary['Read'] ?? false) === false;

        $parts = $this->parts($message);
        $payload = $summary;
        unset($payload['Read']);
        $payload['ReturnPath'] = is_string($message['ReturnPath'] ?? null) ? $message['ReturnPath'] : (string) ($summary['ReturnPath'] ?? '');
        $payload['Date'] = is_string($message['Date'] ?? null) ? $message['Date'] : null;
        $payload['Text'] = is_string($message['Text'] ?? null) ? $message['Text'] : '';
        $payload['HTML'] = is_string($message['HTML'] ?? null) ? $message['HTML'] : '';
        $payload['Headers'] = $headers === [] ? new \stdClass() : $headers;
        $payload['Parts'] = array_values(array_map(
            static fn (array $part): array => array_diff_key($part, ['SHA256' => 0]),
            $parts,
        ));

        $state = $this->api->postMessage($payload, $this->config->label);

        $sent = 0;
        foreach ($state['missing'] as $partId) {
            $content = $this->download($id, $partId, $parts[$partId] ?? null, (int) ($summary['Size'] ?? 0));
            $state = $this->api->putPart($id, $partId, $content);
            $sent++;
        }

        if (!$state['complete']) {
            throw new TransientException(sprintf('l\'API attend encore : %s', implode(', ', $state['missing'])));
        }

        // Livré : le tag est posé seulement maintenant que l'API a tout enregistré.
        $this->mailpit->setTags($id, self::withTag($summary, $this->config->tagDone));
        if ($this->config->keepUnread && $wasUnread) {
            $this->mailpit->markUnread($id);
        }

        $attempt = ($this->retries[$id]['attempts'] ?? 0) + 1;
        Log::info(sprintf(
            'mail %s enregistré par l\'API (%s), %d pièce(s) envoyée(s), essai %d',
            $id,
            mb_substr((string) ($summary['Subject'] ?? ''), 0, 60),
            $sent,
            $attempt,
        ));
        foreach ($state['too_large'] as $partId) {
            Log::warning(sprintf(
                'mail %s : pièce %s « %s » (%d octets) au-delà de la limite de l\'API, seule sa description est enregistrée',
                $id,
                $partId,
                $parts[$partId]['FileName'] ?? '',
                $parts[$partId]['Size'] ?? 0,
            ));
        }
    }

    /**
     * Pièces du mail, indexées par PartID : images intégrées et pièces jointes.
     *
     * @param array<string, mixed> $message
     * @return array<string, array{PartID: string, Kind: string, FileName: string, ContentType: string, ContentID: string, Size: int, SHA256: ?string}>
     */
    private function parts(array $message): array
    {
        $parts = [];
        foreach (['inline' => 'Inline', 'attachment' => 'Attachments'] as $kind => $key) {
            foreach (is_array($message[$key] ?? null) ? $message[$key] : [] as $item) {
                $partId = is_array($item) ? ($item['PartID'] ?? null) : null;
                if (!is_string($partId) || $partId === '') {
                    continue;
                }
                $sha256 = $item['Checksums']['SHA256'] ?? null;
                $parts[$partId] = [
                    'PartID'      => $partId,
                    'Kind'        => $kind,
                    'FileName'    => (string) ($item['FileName'] ?? ''),
                    'ContentType' => (string) ($item['ContentType'] ?? ''),
                    'ContentID'   => (string) ($item['ContentID'] ?? ''),
                    'Size'        => max(0, (int) ($item['Size'] ?? 0)),
                    'SHA256'      => is_string($sha256) && $sha256 !== '' ? strtolower($sha256) : null,
                ];
            }
        }

        return $parts;
    }

    /**
     * Télécharge une pièce depuis Mailpit et vérifie son empreinte.
     *
     * @param array{Size: int, SHA256: ?string}|null $part
     * @throws TransientException
     */
    private function download(string $id, string $partId, ?array $part, int $messageSize): string
    {
        // Sans description (pièce annoncée lors d'un envoi précédent), la taille du mail sert de borne.
        $maxSize = max($part['Size'] ?? 0, $messageSize) + self::DOWNLOAD_MARGIN;

        $content = $this->mailpit->part($id, $partId, $maxSize);
        if ($content === null) {
            throw new TransientException(sprintf('pièce %s introuvable dans Mailpit', $partId));
        }

        $expected = $part['SHA256'] ?? null;
        if ($expected !== null && !hash_equals($expected, hash('sha256', $content))) {
            throw new TransientException(sprintf('pièce %s : empreinte différente de celle annoncée par Mailpit', $partId));
        }

        return $content;
    }

    private function retryLater(string $id, string $error): void
    {
        $attempts = ($this->retries[$id]['attempts'] ?? 0) + 1;
        $delay = min(self::MAX_DELAY, self::MIN_DELAY * 2 ** min($attempts - 1, 10));
        $this->retries[$id] = ['attempts' => $attempts, 'next' => time() + $delay];

        $log = $attempts >= 10 ? Log::error(...) : Log::warning(...);
        $log(sprintf('mail %s : échec de l\'essai %d (%s), nouvel essai dans %d s', $id, $attempts, $error, $delay));
    }

    /**
     * Tags actuels du mail, plus $tag (l'API de Mailpit remplace la liste entière).
     *
     * @param array<string, mixed> $summary
     * @return list<string>
     */
    private static function withTag(array $summary, string $tag): array
    {
        $tags = array_filter(is_array($summary['Tags'] ?? null) ? $summary['Tags'] : [], 'is_string');

        return array_values(array_unique([...$tags, $tag]));
    }
}
