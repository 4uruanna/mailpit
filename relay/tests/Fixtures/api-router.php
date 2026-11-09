<?php

declare(strict_types=1);

/*
 * Fausse API pour les tests du Worker (php -S … api-router.php), avec l'état de FakeState
 * (variable d'environnement FAKE_STATE). Mêmes réponses que la vraie API :
 *
 *   POST /v1/messages                     enregistre le mail, répond avec les pièces attendues
 *   POST /v1/messages/{id}/part/{partId}  enregistre une pièce si l'en-tête X-Content-SHA256 correspond
 *
 * État : api.token (jeton attendu), api.max_part_size (pièces plus grosses : « too_large »),
 * api.message_status / api.part_status (code d'erreur forcé, ou null), api.messages (ID => JSON
 * reçu + « _label »), api.parts (« ID/PartID » => {sha256, size}). Chaque requête est ajoutée
 * à « requests ».
 */

require __DIR__ . '/../Support/FakeState.php';

use Relay\Tests\Support\FakeState;

$state = new FakeState((string) getenv('FAKE_STATE'));
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$headers = array_change_key_case(getallheaders());

$reply = static function (int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$state->update(static function (array &$data) use ($method, $path, $body, $headers): void {
    $data['requests'][] = [
        'server'  => 'api',
        'method'  => $method,
        'path'    => $path,
        'size'    => strlen($body),
        'headers' => array_intersect_key($headers, array_flip(['content-type', 'x-content-sha256', 'mailpit-label', 'user-agent'])),
    ];
});

$api = $state->read()['api'];
if (($headers['authorization'] ?? '') !== 'Bearer ' . $api['token']) {
    $reply(401, ['error' => 'Unauthorized']);
}

/** Pièces encore attendues / trop volumineuses d'un mail. */
$completion = static function (array $data, string $id): array {
    $missing = [];
    $tooLarge = [];
    foreach ($data['api']['messages'][$id]['Parts'] ?? [] as $part) {
        if ($part['Size'] > ($data['api']['max_part_size'] ?? PHP_INT_MAX)) {
            $tooLarge[] = $part['PartID'];
        } elseif (!isset($data['api']['parts'][$id . '/' . $part['PartID']])) {
            $missing[] = $part['PartID'];
        }
    }

    return ['complete' => $missing === [], 'missing' => $missing, 'too_large' => $tooLarge];
};

if ($method === 'POST' && $path === '/v1/messages') {
    if (($api['message_status'] ?? null) !== null) {
        $reply($api['message_status'], ['error' => 'erreur simulée']);
    }
    $message = json_decode($body, true);
    if (($headers['content-type'] ?? '') !== 'application/json' || !is_array($message) || !is_string($message['ID'] ?? null)) {
        $reply(422, ['error' => 'mail invalide']);
    }

    $result = $state->update(static function (array &$data) use ($message, $headers, $completion): array {
        $created = !isset($data['api']['messages'][$message['ID']]);
        $data['api']['messages'][$message['ID']] = $message + ['_label' => $headers['mailpit-label'] ?? null];

        return ['created' => $created] + $completion($data, $message['ID']);
    });
    $created = $result['created'];
    unset($result['created']);

    $reply($created ? 201 : 200, ['id' => $message['ID'], 'status' => $created ? 'created' : 'duplicate'] + $result);
}

if ($method === 'POST' && preg_match('#^/v1/messages/([^/]+)/part/([^/]+)$#', $path, $match)) {
    [, $id, $partId] = array_map('rawurldecode', $match);
    if (($api['part_status'] ?? null) !== null) {
        $reply($api['part_status'], ['error' => 'erreur simulée']);
    }
    if (!isset($api['messages'][$id])) {
        $reply(404, ['error' => 'pièce inconnue']);
    }
    if (($headers['x-content-sha256'] ?? '') !== hash('sha256', $body)) {
        $reply(422, ['error' => 'empreinte SHA-256 différente du contenu reçu']);
    }

    $result = $state->update(static function (array &$data) use ($id, $partId, $body, $completion): array {
        $data['api']['parts'][$id . '/' . $partId] = ['sha256' => hash('sha256', $body), 'size' => strlen($body)];

        return $completion($data, $id);
    });

    $reply(201, ['status' => 'stored', 'id' => $id, 'part' => $partId] + $result);
}

$reply(404, ['error' => 'Not Found']);
