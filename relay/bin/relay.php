<?php

declare(strict_types=1);

/*
 * Point d'entrée du conteneur.
 *  - lance le récepteur de webhook (serveur intégré de PHP) en arrière-plan ;
 *  - relaie en continu les mails de Mailpit vers l'API : à chaque webhook (signal SIGUSR1
 *    envoyé par le récepteur), et au plus tard toutes les RELAY_POLL_INTERVAL secondes.
 * Le relais n'enregistre rien : un mail n'est marqué « relayé » dans Mailpit (tag) qu'une fois
 * enregistré par l'API. Arrêté au mauvais moment, il reprend simplement là où il en était.
 * Si le récepteur s'arrête, le relais quitte en erreur et Podman / systemd le redémarre.
 */

use Relay\ApiClient;
use Relay\Config;
use Relay\Log;
use Relay\MailpitClient;
use Relay\Receiver;
use Relay\Worker;

require __DIR__ . '/../src/bootstrap.php';

try {
    $config = Config::fromEnv();
} catch (Throwable $e) {
    Log::error('configuration invalide : ' . $e->getMessage());
    exit(1);
}

// Le récepteur réveille ce processus (SIGUSR1) quand Mailpit envoie un webhook.
$server = proc_open(
    [PHP_BINARY, '-q', '-S', $config->listen, dirname(__DIR__) . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes,
    null,
    [...getenv(), Receiver::WORKER_PID_ENV => (string) getmypid()],
);
if ($server === false) {
    Log::error('impossible de lancer le récepteur de webhook');
    exit(1);
}

$wake = false;
pcntl_async_signals(true);
// Arrêt propre (podman stop, systemctl stop). Un mail en cours d'envoi n'a pas encore son tag :
// il sera renvoyé au redémarrage (l'API ignore les doublons).
$stop = static function () use ($server): never {
    Log::info('arrêt demandé');
    proc_terminate($server);
    exit(0);
};
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);
pcntl_signal(SIGUSR1, static function () use (&$wake): void {
    $wake = true;
});

Log::info(sprintf('démarrage : écoute sur %s, %s', $config->listen, $config->describe()));

$worker = new Worker($config, new MailpitClient($config), new ApiClient($config));

while (true) {
    if (!proc_get_status($server)['running']) {
        Log::error('le récepteur de webhook s\'est arrêté');
        exit(1);
    }

    $wake = false;
    $more = $worker->sync();

    // Un webhook arrivé pendant le traitement relance aussitôt une recherche.
    if ($more || $wake) {
        continue;
    }

    // sleep() est interrompu par SIGUSR1 (webhook) : on repart alors tout de suite.
    $deadline = time() + $config->pollInterval;
    while (!$wake && time() < $deadline) {
        sleep(max(1, $deadline - time()));
        if (!proc_get_status($server)['running']) {
            break;
        }
    }
}
