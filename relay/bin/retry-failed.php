<?php

declare(strict_types=1);

/*
 * Retire le tag d'échec (RELAY_TAG_FAILED) des mails refusés par l'API : le relais les renvoie aussitôt.
 * À lancer après avoir corrigé la cause (voir les logs du relais et de l'API) :
 *   sudo podman exec mailpit-relay php /app/bin/retry-failed.php
 */

use Relay\Config;
use Relay\MailpitClient;

require __DIR__ . '/../src/bootstrap.php';

$config = Config::fromEnv();
$mailpit = new MailpitClient($config);

$count = 0;
$done = [];
do {
    $result = $mailpit->search('tag:' . $config->tagFailed, 100);
    foreach ($result['messages'] as $message) {
        if (isset($done[$message['ID']])) {
            fwrite(STDERR, "Mailpit n'a pas retiré le tag de {$message['ID']} : arrêt.\n");
            exit(1);
        }
        $done[$message['ID']] = true;
        $tags = array_values(array_filter(
            is_array($message['Tags'] ?? null) ? $message['Tags'] : [],
            static fn (mixed $tag): bool => is_string($tag) && $tag !== $config->tagFailed,
        ));
        $mailpit->setTags((string) $message['ID'], $tags);
        $count++;
    }
} while ($result['messages'] !== []);

// Réveille le relais (processus principal du conteneur) pour un nouvel essai immédiat.
if ($count > 0 && function_exists('posix_kill')) {
    posix_kill(1, SIGUSR1);
}

printf("%d mail(s) remis en attente.\n", $count);
