<?php

declare(strict_types=1);

/*
 * Fausse API pour les tests du Worker et du ping de santé (php -S … api-router.php), avec l'état
 * de FakeState (variable d'environnement FAKE_STATE). Mêmes réponses que la vraie API :
 *
 *   POST /v1/messages                     enregistre le mail, répond avec les pièces attendues
 *   POST /v1/messages/{id}/part/{partId}  enregistre une pièce si l'en-tête X-Content-SHA256 correspond
 *   POST /v1/health                       enregistre un ping de santé
 *
 * État : api.token (jeton attendu), api.max_part_size (pièces plus grosses : « too_large »),
 * api.max_body_size (JSON du mail plus gros : 413 avec {"max_body_size": N}, ou sans ce champ si
 * api.hide_max_body_size vaut true),
 * api.message_status / api.part_status (code d'erreur forcé, ou null), api.failing (ID => code
 * d'erreur forcé pour ce mail seulement), api.messages (ID => JSON reçu + « _label »), api.parts
 * (« ID/PartID » => {sha256, size}), api.health_failures (nombre de pings à refuser en 503 avant
 * d'accepter les suivants), api.pings (pings acceptés : JSON reçu + « _label » et « _type »).
 * Chaque requête est ajoutée à « requests ».
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
    $limit = $api['max_body_size'] ?? null;
    if ($limit !== null && strlen($body) > $limit) {
        $reply(413, ['error' => 'corps de requête trop volumineux'] + (($api['hide_max_body_size'] ?? false) ? [] : ['max_body_size' => $limit]));
    }
    $message = json_decode($body, true);
    if (($headers['content-type'] ?? '') !== 'application/json' || !is_array($message) || !is_string($message['ID'] ?? null)) {
        $reply(422, ['error' => 'mail invalide']);
    }
    if (isset($api['failing'][$message['ID']])) {
        $reply($api['failing'][$message['ID']], ['error' => 'erreur simulée pour ce mail']);
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

if ($method === 'POST' && $path === '/v1/health') {
    $refused = $state->update(static function (array &$data): bool {
        if (($data['api']['health_failures'] ?? 0) > 0) {
            $data['api']['health_failures']--;

            return true;
        }

        return false;
    });
    if ($refused) {
        $reply(503, ['error' => 'erreur simulée']);
    }

    $ping = json_decode($body, true);
    $state->update(static function (array &$data) use ($ping, $headers): void {
        $data['api']['pings'][] = (is_array($ping) ? $ping : ['_body' => $ping]) + [
            '_label' => $headers['mailpit-label'] ?? null,
            '_type'  => $headers['content-type'] ?? null,
        ];
    });

    $reply(200, ['status' => 'ok', 'time' => gmdate('Y-m-d\TH:i:s.000\Z')]);
}

$reply(404, ['error' => 'Not Found']);
