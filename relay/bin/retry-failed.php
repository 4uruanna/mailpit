<?php

declare(strict_types=1);

/*
 * Remet les mails abandonnés (failed/) dans la file d'attente, pour un nouvel essai immédiat.
 * À lancer après avoir corrigé la cause (IP non autorisée, jeton, API en panne…) :
 *   sudo podman exec mailpit-relay php /app/bin/retry-failed.php
 */

use Relay\Config;
use Relay\Spool;

require __DIR__ . '/../src/bootstrap.php';

$count = (new Spool(Config::fromEnv()->dataDir))->retryFailed(time());

printf("%d mail(s) remis dans la file d'attente.\n", $count);
