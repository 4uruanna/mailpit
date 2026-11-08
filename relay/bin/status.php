<?php

declare(strict_types=1);

/*
 * Mails en attente de relais et mails refusés par l'API, lus dans Mailpit (le relais n'enregistre rien).
 *   sudo podman exec mailpit-relay php /app/bin/status.php
 */

use Relay\Config;
use Relay\MailpitClient;

require __DIR__ . '/../src/bootstrap.php';

$config = Config::fromEnv();
$mailpit = new MailpitClient($config);

$sections = [
    'En attente (pas encore enregistrés par l\'API)' => $config->pendingQuery(),
    sprintf('Refusés par l\'API (tag « %s »)', $config->tagFailed) => 'tag:' . $config->tagFailed,
];

foreach ($sections as $title => $query) {
    $result = $mailpit->search($query, 20);
    printf("%s : %d\n", $title, $result['total']);
    foreach ($result['messages'] as $message) {
        printf(
            "  %s  %s  %s\n",
            $message['ID'] ?? '?',
            substr((string) ($message['Created'] ?? ''), 0, 19),
            mb_substr((string) ($message['Subject'] ?? ''), 0, 60),
        );
    }
    if ($result['total'] > count($result['messages'])) {
        printf("  … et %d autre(s)\n", $result['total'] - count($result['messages']));
    }
    echo "\n";
}

echo "Le détail des erreurs est dans les logs : journalctl -u mailpit-relay\n";
