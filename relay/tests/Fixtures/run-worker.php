<?php

declare(strict_types=1);

/*
 * Lance le Worker comme bin/relay.php, sans le récepteur ni la boucle : configuration lue dans
 * les variables d'environnement (Config::fromEnv), puis N passages (sync()).
 *
 *   php run-worker.php [N]
 *
 * Sortie standard : résultats des passages en JSON (ex. [true,false]). Sortie d'erreur : les logs.
 * Un processus séparé, comme en production : chargement par src/bootstrap.php (sans Composer),
 * et des logs qui n'encombrent pas l'affichage de PHPUnit.
 */

use Relay\ApiClient;
use Relay\Config;
use Relay\MailpitClient;
use Relay\Worker;

require __DIR__ . '/../../src/bootstrap.php';

$config = Config::fromEnv();
$worker = new Worker($config, new MailpitClient($config), new ApiClient($config));

$results = [];
for ($i = 0; $i < max(1, (int) ($argv[1] ?? 1)); $i++) {
    $results[] = $worker->sync();
}

echo json_encode($results);
