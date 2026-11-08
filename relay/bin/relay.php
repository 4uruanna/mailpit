<?php

declare(strict_types=1);

/*
 * Point d'entrée du conteneur.
 *  - lance le récepteur de webhook (serveur intégré de PHP) en arrière-plan ;
 *  - traite la file d'attente en continu.
 * Si le récepteur s'arrête, le relais quitte en erreur et Podman / systemd le redémarre.
 */

use Relay\Config;
use Relay\Log;
use Relay\MailpitClient;
use Relay\Spool;
use Relay\Worker;

require __DIR__ . '/../src/bootstrap.php';

try {
    $config = Config::fromEnv();
    $spool = new Spool($config->dataDir);
    $spool->init();
} catch (Throwable $e) {
    Log::error('configuration invalide : ' . $e->getMessage());
    exit(1);
}

$server = proc_open(
    [PHP_BINARY, '-q', '-S', $config->listen, dirname(__DIR__) . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes,
);
if ($server === false) {
    Log::error('impossible de lancer le récepteur de webhook');
    exit(1);
}

// Arrêt propre (podman stop, systemctl stop) : on arrête aussi le récepteur.
// Un mail en cours d'envoi reste dans la file : il sera renvoyé au redémarrage
// (l'API ignore les doublons).
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use ($server): never {
        Log::info('arrêt demandé');
        proc_terminate($server);
        exit(0);
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$stats = $spool->stats();
Log::info(sprintf('démarrage : écoute sur %s, %s', $config->listen, $config->describe()));
Log::info(sprintf('file d\'attente : %d mail(s) en attente, %d abandonné(s)', $stats['queued'], $stats['failed']));

$worker = new Worker($config, $spool, new MailpitClient($config));

while (true) {
    if (!proc_get_status($server)['running']) {
        Log::error('le récepteur de webhook s\'est arrêté');
        exit(1);
    }

    if (!$worker->tick()) {
        sleep(1);
    }
}
