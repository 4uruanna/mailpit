<?php

declare(strict_types=1);

namespace Relay;

/**
 * Accès à l'API de Mailpit (mêmes identifiants que MP_UI_AUTH).
 */
final readonly class MailpitClient
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Recherche de messages (résumés), du plus récent au plus ancien.
     *
     * @return array{total: int, messages: list<array<string, mixed>>}
     * @throws TransientException
     */
    public function search(string $query, int $limit, int $start = 0): array
    {
        $body = $this->get('/api/v1/search?' . http_build_query(['query' => $query, 'limit' => $limit, 'start' => $start]));
        $data = $body === null ? null : json_decode($body, true);
        if (!is_array($data) || !is_array($data['messages'] ?? null)) {
            throw new TransientException('Mailpit : réponse de recherche invalide');
        }

        return [
            'total'    => (int) ($data['messages_count'] ?? $data['total'] ?? 0),
            'messages' => array_values(array_filter($data['messages'], 'is_array')),
        ];
    }

    /**
     * Message complet : Text, HTML, liste des pièces jointes (Attachments) et images intégrées (Inline).
     * ⚠️ Mailpit marque le message comme lu.
     *
     * @return array<string, mixed>|null null si le message n'existe plus dans Mailpit
     * @throws TransientException
     */
    public function message(string $id): ?array
    {
        return $this->getJson('/api/v1/message/' . rawurlencode($id));
    }

    /**
     * Tous les en-têtes du message : {"Nom": ["valeur", …]}.
     *
     * @return array<string, list<string>>|null
     * @throws TransientException
     */
    public function headers(string $id): ?array
    {
        return $this->getJson('/api/v1/message/' . rawurlencode($id) . '/headers');
    }

    /**
     * Contenu d'une pièce (pièce jointe ou image intégrée).
     *
     * @throws TransientException
     */
    public function part(string $id, string $partId, int $maxSize): ?string
    {
        return $this->get('/api/v1/message/' . rawurlencode($id) . '/part/' . rawurlencode($partId), $maxSize);
    }

    /**
     * Mail brut (.eml), pièces jointes comprises.
     *
     * @throws TransientException
     */
    public function raw(string $id, int $maxSize): ?string
    {
        return $this->get('/api/v1/message/' . rawurlencode($id) . '/raw', $maxSize);
    }

    /**
     * Remplace les tags du message (l'API de Mailpit ne sait que remplacer la liste complète).
     *
     * @param list<string> $tags
     * @throws TransientException
     */
    public function setTags(string $id, array $tags): void
    {
        $this->put('/api/v1/tags', ['IDs' => [$id], 'Tags' => array_values($tags)], 'tags');
    }

    /**
     * Remet le message en « non lu ».
     *
     * @throws TransientException
     */
    public function markUnread(string $id): void
    {
        // Toujours préciser IDs : sans liste, Mailpit modifie TOUS les messages.
        $this->put('/api/v1/messages', ['IDs' => [$id], 'Read' => false], 'marquage non lu');
    }

    /**
     * @param array<string, mixed> $data
     * @throws TransientException
     */
    private function put(string $path, array $data, string $what): void
    {
        $response = Http::request(
            'PUT',
            $this->config->mailpitUrl . $path,
            ['Content-Type: application/json'],
            json_encode($data, JSON_THROW_ON_ERROR),
            $this->config->mailpitAuth,
            $this->config->timeout,
        );
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new TransientException(Http::describe($response, 'Mailpit ' . $what));
        }
    }

    /**
     * @return array<mixed>|null
     * @throws TransientException
     */
    private function getJson(string $path): ?array
    {
        $body = $this->get($path);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new TransientException('Mailpit ' . $path . ' : réponse JSON invalide');
        }

        return $data;
    }

    /**
     * @return string|null null si le message (ou la pièce) n'existe plus
     * @throws TransientException
     */
    private function get(string $path, int $maxSize = 0): ?string
    {
        $response = Http::request(
            'GET',
            $this->config->mailpitUrl . $path,
            [],
            null,
            $this->config->mailpitAuth,
            $this->config->timeout,
            $maxSize,
        );

        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 200) {
            throw new TransientException(Http::describe($response, 'Mailpit ' . strtok($path, '?')));
        }

        return $response['body'];
    }
}
