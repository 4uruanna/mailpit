<?php

declare(strict_types=1);

/*
 * Lance le ping de santé comme bin/heartbeat.php, sans attendre entre deux pings : configuration
 * lue dans les variables d'environnement (Config::fromEnv), puis N pings (beat()).
 *
 *   php run-heartbeat.php [N]
 *
 * Horloge simulée : le Ne ping a lieu à (N - 1) × HEARTBEAT_CLOCK_STEP secondes (30 par défaut),
 * pour tester les rappels du journal sans attendre 10 minutes.
 * Sortie standard : résultats des pings en JSON (ex. [true,false]). Sortie d'erreur : les logs.
 */

use Relay\ApiClient;
use Relay\Config;
use Relay\Heartbeat;
use Relay\MailpitClient;

require __DIR__ . '/../../src/bootstrap.php';

$config = Config::fromEnv();
$config = $config->withTimeout(min($config->timeout, Heartbeat::TIMEOUT));

$beat = 0;
$step = (int) (getenv('HEARTBEAT_CLOCK_STEP') ?: 30);
$clock = static function () use (&$beat, $step): float {
    return 1_800_000_000.0 + $beat * $step;
};
$heartbeat = new Heartbeat($config, new MailpitClient($config), new ApiClient($config), $clock);

$results = [];
for ($beat = 0; $beat < max(1, (int) ($argv[1] ?? 1)); $beat++) {
    $results[] = $heartbeat->beat();
}

echo json_encode($results);
