<?php

declare(strict_types=1);

/*
 * Une « requête » simultanée de LogRotatorTest : attend l'instant donné (pour partir en même temps que
 * les autres processus), lance la rotation, écrit une ligne dans le log, et affiche le résultat.
 *
 *     php rotate-log.php <fichier de log> <taille maximale> <instant de départ (microtime)>
 */

use App\Log\LogRotator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $file, $maxSize, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$rotation = new LogRotator($file, (int) $maxSize)->rotate();
file_put_contents($file, sprintf("ligne du processus %d\n", getmypid()), FILE_APPEND | LOCK_EX);

echo $rotation->name;
