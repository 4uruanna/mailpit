<?php

declare(strict_types=1);

/*
 * Ping de santé du relais : lancé et surveillé par bin/relay.php, dans un processus séparé pour
 * que les pings ne soient pas retardés par l'envoi d'un gros mail.
 *
 * Toutes les RELAY_HEARTBEAT_INTERVAL secondes (30 par défaut) : compte les mails en attente et
 * refusés dans Mailpit, puis envoie POST {RELAY_API_URL}/v1/health (voir Relay\Heartbeat).
 * Chaque requête attend au plus 10 secondes. Arrêté par bin/relay.php (SIGTERM) ; s'arrête
 * aussi tout seul si bin/relay.php n'est plus là.
 */

use Relay\ApiClient;
use Relay\Config;
use Relay\Heartbeat;
use Relay\Log;
use Relay\MailpitClient;

require __DIR__ . '/../src/bootstrap.php';

try {
    $config = Config::fromEnv();
} catch (Throwable $e) {
    Log::error('ping de santé : configuration invalide : ' . $e->getMessage());
    exit(1);
}

if ($config->heartbeatInterval === 0) {
    Log::info('ping de santé désactivé (RELAY_HEARTBEAT_INTERVAL=0)');
    exit(0);
}

$config = $config->withTimeout(min($config->timeout, Heartbeat::TIMEOUT));
$heartbeat = new Heartbeat($config, new MailpitClient($config), new ApiClient($config));
$parent = function_exists('posix_getppid') ? posix_getppid() : null;

$next = microtime(true);
while (true) {
    $heartbeat->beat();

    // Prochain ping à heure fixe ; un ping plus long que l'intervalle ne crée pas de rafale.
    $next += $config->heartbeatInterval;
    while ($next <= microtime(true)) {
        $next += $config->heartbeatInterval;
    }
    // usleep plutôt que time_sleep_until, qui avertit si l'heure visée vient de passer.
    usleep(max(0, (int) (($next - microtime(true)) * 1_000_000)));

    // bin/relay.php arrêté sans avoir pu prévenir (kill -9) : inutile de continuer seul.
    if ($parent !== null && posix_getppid() !== $parent) {
        exit(0);
    }
}
