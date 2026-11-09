<?php

declare(strict_types=1);

/*
 * Point d'entrée du conteneur.
 *  - lance le récepteur de webhook (serveur intégré de PHP) en arrière-plan ;
 *  - lance le ping de santé (bin/heartbeat.php : POST /v1/health toutes les
 *    RELAY_HEARTBEAT_INTERVAL secondes) en arrière-plan, sauf si RELAY_HEARTBEAT_INTERVAL=0 ;
 *  - relaie en continu les mails de Mailpit vers l'API : à chaque webhook (signal SIGUSR1
 *    envoyé par le récepteur), et au plus tard toutes les RELAY_POLL_INTERVAL secondes.
 * Le relais n'enregistre rien : un mail n'est marqué « relayé » dans Mailpit (tag) qu'une fois
 * enregistré par l'API. Arrêté au mauvais moment, il reprend simplement là où il en était.
 * Les mails sont envoyés un par un : jamais deux requêtes simultanées vers l'API pour les mails.
 * Seul le ping de santé, petit et indépendant, part à côté, depuis son propre processus.
 * Si le récepteur ou le ping de santé s'arrête, le relais quitte en erreur et Podman / systemd
 * le redémarre.
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

// Un seul relais à la fois : sinon deux processus enverraient des mails à l'API en même temps.
// Le verrou est une socket Unix « abstraite » (sans fichier : le conteneur est en lecture seule),
// libérée automatiquement à la fin du processus.
$lock = @stream_socket_server("unix://\0mailpit-relay.lock", $errorCode, $errorMessage);
if ($lock === false) {
    Log::error('un autre relais tourne déjà dans ce conteneur : arrêt');
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
$children = ['le récepteur de webhook' => $server];

// Ping de santé : un processus à part, pour qu'un gros mail en cours d'envoi ne le retarde pas.
if ($config->heartbeatInterval > 0) {
    $heartbeat = proc_open(
        [PHP_BINARY, __DIR__ . '/heartbeat.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes,
    );
    if ($heartbeat === false) {
        Log::error('impossible de lancer le ping de santé');
        proc_terminate($server);
        exit(1);
    }
    $children['le ping de santé'] = $heartbeat;
}

/** Arrête les processus lancés par le relais. */
$terminate = static function () use ($children): void {
    foreach ($children as $child) {
        if (proc_get_status($child)['running']) {
            proc_terminate($child);
        }
    }
};

/** Nom du premier processus arrêté (récepteur ou ping de santé), ou null s'ils tournent tous. */
$stopped = static function () use ($children): ?string {
    foreach ($children as $name => $child) {
        if (!proc_get_status($child)['running']) {
            return $name;
        }
    }

    return null;
};

$wake = false;
pcntl_async_signals(true);
// Arrêt propre (podman stop, systemctl stop). Un mail en cours d'envoi n'a pas encore son tag :
// il sera renvoyé au redémarrage (l'API ignore les doublons).
$stop = static function () use ($terminate): never {
    Log::info('arrêt demandé');
    $terminate();
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
    $name = $stopped();
    if ($name !== null) {
        Log::error(sprintf('%s s\'est arrêté', $name));
        $terminate();
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
        if ($stopped() !== null) {
            break;
        }
    }
}
