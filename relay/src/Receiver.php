<?php

declare(strict_types=1);

namespace Relay;

/**
 * Récepteur HTTP (serveur intégré de PHP) :
 *   POST /hook    webhook de Mailpit → le mail est mis en file d'attente (réponse immédiate)
 *   GET  /health  état du relais
 *
 * Le travail lent (lecture du mail, envoi à l'API) est fait par le worker,
 * pour répondre à Mailpit bien avant son délai de 5 secondes.
 */
final readonly class Receiver
{
    private const int MAX_WEBHOOK_SIZE = 1024 * 1024;

    public function __construct(private Spool $spool)
    {
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        match (true) {
            $path === '/hook' && $method === 'POST' => $this->hook(),
            $path === '/health' && $method === 'GET' => self::json(200, ['status' => 'ok'] + $this->spool->stats()),
            $path === '/hook', $path === '/health' => self::json(405, ['error' => 'Method Not Allowed']),
            default => self::json(404, ['error' => 'Not Found']),
        };
    }

    private function hook(): void
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            self::json(415, ['error' => 'Content-Type application/json attendu']);

            return;
        }

        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_WEBHOOK_SIZE + 1);
        if (strlen($raw) > self::MAX_WEBHOOK_SIZE) {
            self::json(413, ['error' => 'corps de requête trop volumineux']);

            return;
        }

        $summary = json_decode($raw, true);
        if (!is_array($summary) || array_is_list($summary)) {
            self::json(400, ['error' => 'objet JSON attendu']);

            return;
        }

        $id = $summary['ID'] ?? null;
        if (!is_string($id) || !preg_match(Spool::ID_PATTERN, $id)) {
            self::json(422, ['error' => 'champ « ID » manquant ou invalide']);

            return;
        }

        if ($this->spool->isQueued($id)) {
            self::json(202, ['status' => 'already-queued', 'id' => $id]);

            return;
        }

        $label = trim((string) ($_SERVER['HTTP_MAILPIT_LABEL'] ?? ''));
        $this->spool->add([
            'id'          => $id,
            'label'       => $label === '' ? null : $label,
            'summary'     => $summary,
            'attempts'    => 0,
            'received_at' => gmdate('c'),
            'last_error'  => null,
        ], time());

        Log::info(sprintf('mail %s reçu : %s', $id, mb_substr((string) ($summary['Subject'] ?? ''), 0, 80)));
        self::json(202, ['status' => 'queued', 'id' => $id]);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
