<?php

declare(strict_types=1);

namespace Relay;

/**
 * Petit client HTTP (extension curl). Tout reste en mémoire : rien n'est écrit sur le disque.
 */
final class Http
{
    /**
     * @param list<string> $headers
     * @param int $maxSize taille maximale de la réponse (octets), 0 = sans limite
     * @return array{status: int, body: string, error: ?string} status = 0 si la requête n'a pas abouti
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $auth = null,
        int $timeout = 30,
        int $maxSize = 0,
    ): array {
        $received = '';
        $tooLarge = false;

        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => array_merge(['User-Agent: mailpit-relay/2.0', 'Expect:'], $headers),
            CURLOPT_CONNECTTIMEOUT  => min(10, $timeout),
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS_STR   => 'http,https',
            CURLOPT_WRITEFUNCTION   => static function ($curl, string $chunk) use (&$received, &$tooLarge, $maxSize): int {
                if ($maxSize > 0 && strlen($received) + strlen($chunk) > $maxSize) {
                    $tooLarge = true;

                    return 0; // interrompt le transfert
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($auth !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $auth;
        }
        curl_setopt_array($curl, $options);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($tooLarge) {
            return ['status' => 0, 'body' => '', 'error' => sprintf('réponse de plus de %d octets', $maxSize)];
        }
        if ($ok === false) {
            return ['status' => 0, 'body' => '', 'error' => curl_error($curl)];
        }

        return ['status' => $status, 'body' => $received, 'error' => null];
    }

    /** @param array{status: int, body: string, error: ?string} $response */
    public static function describe(array $response, string $what): string
    {
        return $response['status'] === 0
            ? sprintf('%s : %s', $what, $response['error'])
            : sprintf('%s : HTTP %d %s', $what, $response['status'], mb_substr(trim($response['body']), 0, 200));
    }
}
