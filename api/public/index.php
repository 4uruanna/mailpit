<?php

declare(strict_types=1);

use App\Kernel;

$root = dirname(__DIR__);

/** Réponse d'erreur minimale, avant que Slim ne soit disponible. */
$fail = static function (string $message): never {
    error_log('[api] ' . $message);
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"Erreur de configuration du serveur"}';
    exit;
};

if (!is_file($root . '/vendor/autoload.php')) {
    $fail('vendor/autoload.php introuvable : lancez « composer install » puis envoyez le dossier vendor/.');
}
if (!is_file($root . '/config.php')) {
    $fail('config.php introuvable : copiez config.example.php en config.php.');
}

require $root . '/vendor/autoload.php';

$config = require $root . '/config.php';
if (!is_array($config)) {
    $fail('config.php doit retourner un tableau.');
}

try {
    $app = Kernel::create($config);
} catch (Throwable $e) {
    $fail('Configuration invalide : ' . $e->getMessage());
}

$app->run();
