<?php

declare(strict_types=1);

// Script « routeur » du serveur intégré de PHP : reçoit toutes les requêtes HTTP.

use Relay\Config;
use Relay\Receiver;
use Relay\Spool;

require __DIR__ . '/../src/bootstrap.php';

(new Receiver(new Spool(Config::fromEnv()->dataDir)))->handle();
