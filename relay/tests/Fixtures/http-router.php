<?php

declare(strict_types=1);

/*
 * Serveur de test de Relay\Http (php -S … http-router.php) :
 *
 *   /echo           renvoie en JSON la méthode, les en-têtes et le corps reçus
 *   /status/{code}  répond avec ce code HTTP
 *   /bytes/{n}      renvoie n octets
 *   /redirect       redirection 302 vers /echo
 *   /sleep/{s}      attend s secondes
 */

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/echo') {
    header('Content-Type: application/json');
    echo json_encode([
        'method'  => $method,
        'headers' => array_change_key_case(getallheaders()),
        'body'    => file_get_contents('php://input'),
    ]);
} elseif (preg_match('#^/status/(\d{3})$#', $path, $match)) {
    http_response_code((int) $match[1]);
    echo 'réponse ' . $match[1];
} elseif (preg_match('#^/bytes/(\d+)$#', $path, $match)) {
    echo str_repeat('x', (int) $match[1]);
} elseif ($path === '/redirect') {
    header('Location: /echo', true, 302);
} elseif (preg_match('#^/sleep/(\d+)$#', $path, $match)) {
    sleep((int) $match[1]);
    echo 'réveillé';
} else {
    http_response_code(404);
    echo 'Not Found';
}
