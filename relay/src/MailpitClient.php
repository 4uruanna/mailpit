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
     * Message complet : Text, HTML, en-têtes principaux, liste des pièces jointes…
     * ⚠️ Mailpit marque le message comme lu.
     *
     * @return array<string, mixed>|null null si le message n'existe plus dans Mailpit
     * @throws TransientException
     */
    public function message(string $id): ?array
    {
        $response = $this->get('/api/v1/message/' . rawurlencode($id));
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new TransientException('réponse JSON invalide de Mailpit');
        }

        return $data;
    }

    /**
     * Mail brut (.eml), pièces jointes comprises.
     *
     * @return string|null null si le message n'existe plus
     * @throws TransientException
     */
    public function raw(string $id): ?string
    {
        return $this->get('/api/v1/message/' . rawurlencode($id) . '/raw', $this->config->maxRawSize);
    }

    /**
     * Remet le message en « non lu ».
     *
     * @throws TransientException
     */
    public function markUnread(string $id): void
    {
        // Toujours préciser IDs : sans liste, Mailpit modifie TOUS les messages.
        $response = Http::request(
            'PUT',
            $this->config->mailpitUrl . '/api/v1/messages',
            ['Content-Type: application/json'],
            json_encode(['IDs' => [$id], 'Read' => false], JSON_THROW_ON_ERROR),
            $this->config->mailpitAuth,
            $this->config->timeout,
        );
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new TransientException(self::describe($response, 'marquage non lu'));
        }
    }

    /** @throws TransientException */
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
            throw new TransientException(self::describe($response, 'Mailpit ' . $path));
        }

        return $response['body'];
    }

    /** @param array{status: int, body: string, error: ?string} $response */
    private static function describe(array $response, string $what): string
    {
        return $response['status'] === 0
            ? sprintf('%s : %s', $what, $response['error'])
            : sprintf('%s : HTTP %d %s', $what, $response['status'], mb_substr(trim($response['body']), 0, 200));
    }
}
