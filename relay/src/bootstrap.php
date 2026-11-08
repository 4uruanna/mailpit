<?php

declare(strict_types=1);

// Chargement automatique des classes Relay\… depuis src/ (aucune dépendance Composer).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Relay\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
