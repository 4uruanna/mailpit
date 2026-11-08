<?php

declare(strict_types=1);

namespace Relay;

/**
 * Petit client HTTP (extension curl).
 */
final class Http
{
    /**
     * @param list<string> $headers
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
        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => array_merge(['User-Agent: mailpit-relay/1.0', 'Expect:'], $headers),
            CURLOPT_CONNECTTIMEOUT  => min(10, $timeout),
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS_STR   => 'http,https',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($auth !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $auth;
        }
        if ($maxSize > 0) {
            $options[CURLOPT_MAXFILESIZE] = $maxSize;
        }
        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = $response === false ? curl_error($curl) : null;

        if ($response === false) {
            return ['status' => 0, 'body' => '', 'error' => $error];
        }

        return ['status' => $status, 'body' => (string) $response, 'error' => null];
    }
}
