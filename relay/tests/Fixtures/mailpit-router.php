<?php

declare(strict_types=1);

/*
 * Faux Mailpit pour les tests du Worker (php -S … mailpit-router.php), avec l'état de
 * FakeState (variable d'environnement FAKE_STATE). Il reproduit les routes de l'API de Mailpit
 * utilisées par le relais :
 *
 *   GET /api/v1/search?query=…&limit=…&start=…   résumés, du plus récent au plus ancien (filtre « !tag:… » seulement)
 *   GET /api/v1/message/{id}                     mail complet (le marque comme lu, comme Mailpit)
 *   GET /api/v1/message/{id}/headers             en-têtes
 *   GET /api/v1/message/{id}/part/{partId}       contenu d'une pièce
 *   PUT /api/v1/tags                             {"IDs": […], "Tags": […]} remplace les tags
 *   PUT /api/v1/messages                         {"IDs": […], "Read": false} marque lu / non lu
 *
 * État : mailpit.auth (« utilisateur:motdepasse » attendu, ou null), mailpit.messages (ID => mail,
 * du plus ancien au plus récent ; champs du résumé, plus « _message », « _headers », « _parts »
 * (PartID => contenu en base64) et « _gone » (mail supprimé entre la recherche et la lecture)).
 * Chaque requête est ajoutée à « requests ».
 */

require __DIR__ . '/../Support/FakeState.php';

use Relay\Tests\Support\FakeState;

$state = new FakeState((string) getenv('FAKE_STATE'));
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = (string) file_get_contents('php://input');
$headers = array_change_key_case(getallheaders());

$reply = static function (int $status, mixed $data, string $type = 'application/json'): never {
    http_response_code($status);
    header('Content-Type: ' . $type);
    echo is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

/** Résumé d'un mail, tel que dans les résultats de recherche. */
$summary = static fn (array $message): array => array_filter(
    $message,
    static fn (string $key): bool => !str_starts_with($key, '_'),
    ARRAY_FILTER_USE_KEY,
);

$state->update(static function (array &$data) use ($method, $path, $query, $body): void {
    $data['requests'][] = [
        'server' => 'mailpit',
        'method' => $method,
        'path'   => $path,
        'query'  => $query,
        'body'   => $body,
    ];
});

$auth = $state->read()['mailpit']['auth'] ?? null;
if ($auth !== null && ($headers['authorization'] ?? '') !== 'Basic ' . base64_encode($auth)) {
    header('WWW-Authenticate: Basic realm="Mailpit"');
    $reply(401, 'Unauthorised.', 'text/plain');
}

if ($method === 'GET' && $path === '/api/v1/search') {
    preg_match_all('/!tag:(\S+)/', (string) ($query['query'] ?? ''), $match);
    $excluded = $match[1];
    $all = $state->read()['mailpit']['messages'] ?? [];
    $found = array_values(array_filter(
        array_reverse($all),
        static fn (array $message): bool => array_intersect($message['Tags'] ?? [], $excluded) === [],
    ));
    $start = (int) ($query['start'] ?? 0);
    $limit = (int) ($query['limit'] ?? 50);

    $reply(200, [
        'total'          => count($all),
        'unread'         => 0,
        'count'          => count(array_slice($found, $start, $limit)),
        'messages_count' => count($found),
        'start'          => $start,
        'tags'           => [],
        'messages'       => array_map($summary, array_slice($found, $start, $limit)),
    ]);
}

if ($method === 'GET' && preg_match('#^/api/v1/message/([^/]+)(?:/(headers)|/part/([^/]+))?$#', $path, $match)) {
    $id = rawurldecode($match[1]);
    $result = $state->update(static function (array &$data) use ($id, $match): ?array {
        $message = $data['mailpit']['messages'][$id] ?? null;
        if ($message === null || ($message['_gone'] ?? false)) {
            return null;
        }
        if (($match[2] ?? '') === 'headers') {
            return ['json' => $message['_headers'] ?? []];
        }
        if (isset($match[3])) {
            $content = $message['_parts'][rawurldecode($match[3])] ?? null;

            return $content === null ? null : ['raw' => base64_decode($content)];
        }
        // Comme Mailpit : lire le mail le marque comme lu.
        $data['mailpit']['messages'][$id]['Read'] = true;

        return ['json' => ['ID' => $id] + ($message['_message'] ?? [])];
    });

    if ($result === null) {
        $reply(404, 'Not found', 'text/plain');
    }
    isset($result['raw']) ? $reply(200, $result['raw'], 'application/octet-stream') : $reply(200, $result['json']);
}

if ($method === 'PUT' && ($path === '/api/v1/tags' || $path === '/api/v1/messages')) {
    $input = json_decode($body, true);
    if (!is_array($input) || !is_array($input['IDs'] ?? null) || $input['IDs'] === []) {
        $reply(400, 'IDs manquant', 'text/plain');
    }
    $state->update(static function (array &$data) use ($input, $path): void {
        foreach ($input['IDs'] as $id) {
            if (!isset($data['mailpit']['messages'][$id])) {
                continue;
            }
            if ($path === '/api/v1/tags') {
                $data['mailpit']['messages'][$id]['Tags'] = $input['Tags'];
            } else {
                $data['mailpit']['messages'][$id]['Read'] = $input['Read'];
            }
        }
    });
    $reply(200, 'ok', 'text/plain');
}

$reply(404, 'Not found', 'text/plain');
