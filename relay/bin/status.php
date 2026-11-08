<?php

declare(strict_types=1);

/*
 * État de la file d'attente.
 *   sudo podman exec mailpit-relay php /app/bin/status.php
 */

use Relay\Config;
use Relay\Spool;

require __DIR__ . '/../src/bootstrap.php';

$dir = Config::fromEnv()->dataDir;
$stats = (new Spool($dir))->stats();

printf("En attente : %d\nAbandonnés : %d\n", $stats['queued'], $stats['failed']);

foreach (['queue' => 'En attente', 'failed' => 'Abandonnés'] as $sub => $title) {
    $files = glob($dir . '/' . $sub . '/*.json') ?: [];
    if ($files === []) {
        continue;
    }
    echo "\n$title :\n";
    foreach (array_slice($files, 0, 20) as $file) {
        $job = json_decode((string) file_get_contents($file), true) ?: [];
        printf(
            "  %s  essais=%d  %s\n",
            $job['id'] ?? basename($file),
            $job['attempts'] ?? 0,
            $job['last_error'] ?? '',
        );
    }
    if (count($files) > 20) {
        printf("  … et %d autre(s)\n", count($files) - 20);
    }
}
