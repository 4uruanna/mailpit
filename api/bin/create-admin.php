<?php

declare(strict_types=1);

/*
 * Prépare la création d'un administrateur du Dashboard (table mailpit_admins).
 *
 * À lancer sur votre ordinateur (PHP 8.4 ou plus, aucune dépendance) :
 *
 *     php bin/create-admin.php
 *
 * Le script demande l'identifiant et le mot de passe, puis affiche la requête SQL à exécuter
 * dans phpMyAdmin (onglet SQL). Le mot de passe n'y apparaît que haché (password_hash).
 * Si l'identifiant existe déjà, la requête remplace son mot de passe.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$prompt = static function (string $label, bool $hidden = false): string {
    fwrite(STDOUT, $label);
    $hide = $hidden && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $value = fgets(STDIN);
    if ($hide) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return $value === false ? '' : rtrim($value, "\r\n");
};

$username = mb_strtolower(trim($prompt('Identifiant : ')));
if ($username === '' || mb_strlen($username) > 255 || preg_match('/\s/', $username)) {
    fwrite(STDERR, "Identifiant invalide (1 à 255 caractères, sans espace).\n");
    exit(1);
}

$password = $prompt('Mot de passe (12 caractères minimum) : ', true);
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Mot de passe trop court : 12 caractères minimum.\n");
    exit(1);
}
if ($prompt('Confirmez le mot de passe : ', true) !== $password) {
    fwrite(STDERR, "Les deux mots de passe sont différents.\n");
    exit(1);
}

$quote = static fn (string $value): string => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
$hash = password_hash($password, PASSWORD_DEFAULT);

fwrite(STDOUT, PHP_EOL . "Requête à exécuter dans phpMyAdmin (onglet SQL) :" . PHP_EOL . PHP_EOL);
fwrite(STDOUT, sprintf(
    "INSERT INTO mailpit_admins (username, password) VALUES (%s, %s) AS new\n    ON DUPLICATE KEY UPDATE password = new.password;\n",
    $quote($username),
    $quote($hash),
));
