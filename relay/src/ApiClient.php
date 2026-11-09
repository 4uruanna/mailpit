<?php

declare(strict_types=1);

namespace Relay;

/**
 * Accès à l'API (dossier api/ du dépôt), avec le jeton RELAY_API_TOKEN
 * dans l'en-tête « Authorization: Bearer … ».
 *
 *   POST {RELAY_API_URL}/v1/messages                     le mail (JSON) ; l'API répond avec les pièces attendues
 *   POST {RELAY_API_URL}/v1/messages/{id}/part/{partId}  le contenu d'une pièce, avec son SHA-256
 */
final readonly class ApiClient
{
    /** Refus du message que réessayer ne changera pas. */
    private const array PERMANENT_MESSAGE_ERRORS = [400, 413, 415, 422];

    /**
     * Refus d'une pièce que réessayer ne changera pas.
     * 422 (empreinte différente) n'en fait pas partie : le contenu a pu être abîmé en route.
     */
    private const array PERMANENT_PART_ERRORS = [409, 413, 415];

    public function __construct(private Config $config)
    {
    }

    /**
     * @param array<string, mixed> $message
     * @return array{complete: bool, missing: list<string>, too_large: list<string>}
     * @throws TransientException|PermanentException
     */
    public function postMessage(array $message, ?string $label): array
    {
        $headers = ['Content-Type: application/json'];
        if ($label !== null && $label !== '') {
            $headers[] = 'Mailpit-Label: ' . str_replace(["\r", "\n"], '', $label);
        }

        $response = $this->send(
            'POST',
            $this->config->apiUrl . '/v1/messages',
            $headers,
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        );

        return $this->state($response, 'API (message)', self::PERMANENT_MESSAGE_ERRORS);
    }

    /**
     * @return array{complete: bool, missing: list<string>, too_large: list<string>}
     * @throws TransientException|PermanentException
     */
    public function putPart(string $id, string $partId, string $content): array
    {
        $response = $this->send(
            'POST',
            sprintf('%s/v1/messages/%s/part/%s', $this->config->apiUrl, rawurlencode($id), rawurlencode($partId)),
            [
                'Content-Type: application/octet-stream',
                'X-Content-SHA256: ' . hash('sha256', $content),
            ],
            $content,
        );

        return $this->state($response, sprintf('API (pièce %s)', $partId), self::PERMANENT_PART_ERRORS);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, error: ?string}
     */
    private function send(string $method, string $url, array $headers, string $body): array
    {
        $headers[] = 'Authorization: Bearer ' . $this->config->apiToken;

        return Http::request($method, $url, $headers, $body, null, $this->config->timeout);
    }

    /**
     * @param array{status: int, body: string, error: ?string} $response
     * @param list<int> $permanent
     * @return array{complete: bool, missing: list<string>, too_large: list<string>}
     * @throws TransientException|PermanentException
     */
    private function state(array $response, string $what, array $permanent): array
    {
        $status = $response['status'];
        if ($status === 0) {
            throw new TransientException(Http::describe($response, $what . ' injoignable'));
        }
        if (in_array($status, $permanent, true)) {
            throw new PermanentException(Http::describe($response, $what . ' refus définitif'));
        }
        if ($status < 200 || $status > 299) {
            // 401 (jeton), 403 (IP), 404, 5xx… : on réessaie, le temps de corriger.
            throw new TransientException(Http::describe($response, $what));
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !is_bool($data['complete'] ?? null) || !is_array($data['missing'] ?? null)) {
            throw new TransientException(Http::describe($response, $what . ' réponse inattendue'));
        }

        return [
            'complete'  => $data['complete'],
            'missing'   => array_values(array_map('strval', $data['missing'])),
            'too_large' => array_values(array_map('strval', is_array($data['too_large'] ?? null) ? $data['too_large'] : [])),
        ];
    }
}
