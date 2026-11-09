<?php

declare(strict_types=1);

// Chargé par PHPUnit avant les tests (voir phpunit.xml.dist).

require dirname(__DIR__) . '/vendor/autoload.php';

// Le code écrit dans le log (error_log) : pendant les tests, ce log va dans un fichier temporaire
// plutôt que sur la sortie d'erreur, pour garder un affichage lisible.
ini_set('log_errors', '1');
ini_set('error_log', App\Tests\Support\TempDir::logFile());
