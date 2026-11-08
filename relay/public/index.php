<?php

declare(strict_types=1);

// Script « routeur » du serveur intégré de PHP : reçoit toutes les requêtes HTTP.

use Relay\Receiver;

require __DIR__ . '/../src/bootstrap.php';

Receiver::handle();
