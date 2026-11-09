<?php

declare(strict_types=1);

/*
 * Nettoyage de l'API, lancé tout de suite et jusqu'au bout :
 *   - mails reçus il y a plus de webhook.max_age_days jours (base ET pièces jointes) ;
 *   - mails au-delà des webhook.max_messages plus récents ;
 *   - fichiers temporaires d'envois interrompus, dossiers et fichiers sans ligne en base.
 *
 * Inutile en temps normal : l'API fait ce nettoyage toute seule, au plus toutes les 15 minutes,
 * après les requêtes du relais. Ce script sert à le lancer à la main, ou depuis une tâche
 * planifiée de l'hébergement (facultative), par exemple si le relais est arrêté.
 *
 * Sur l'hébergement (tâche planifiée OVH, ou SSH), depuis le dossier api/ :
 *
 *     php bin/cleanup.php
 *
 * Il lit config.php et vendor/ comme l'API. Les erreurs détaillées vont dans le log (log_file).
 */

use App\Persistence\Database;
use App\Persistence\FileStore;
use App\Persistence\Maintenance;
use App\Persistence\MessageRepository;
use App\Settings;

// Jamais depuis le web (le .htaccess bloque déjà bin/).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

$fail = static function (string $message): never {
    fwrite(STDERR, 'Nettoyage impossible : ' . $message . PHP_EOL);
    exit(1);
};

if (!is_file($root . '/vendor/autoload.php')) {
    $fail('vendor/autoload.php introuvable : lancez « composer install » puis envoyez le dossier vendor/.');
}
if (!is_file($root . '/config.php')) {
    $fail('config.php introuvable : copiez config.example.php en config.php.');
}

require $root . '/vendor/autoload.php';

$config = require $root . '/config.php';
if (!is_array($config)) {
    $fail('config.php doit retourner un tableau.');
}

try {
    $settings = Settings::fromArray($config);
    // Même log que l'API : les fichiers impossibles à effacer y sont détaillés.
    if ($settings->logFile !== null) {
        ini_set('log_errors', '1');
        ini_set('error_log', $settings->logFile);
    }

    $maintenance = new Maintenance(
        new MessageRepository(new Database($settings->db)),
        new FileStore($settings->storageDir),
        $settings->maxAgeDays,
        $settings->maxMessages,
    );
    $report = $maintenance->run();
} catch (Throwable $e) {
    $fail($e->getMessage());
}

$lines = [
    $settings->maxAgeDays > 0
        ? [sprintf('Mails de plus de %d jours supprimés', $settings->maxAgeDays), (string) $report->expired]
        : ['Mails trop anciens', 'désactivé (max_age_days = 0)'],
    $settings->maxMessages > 0
        ? [sprintf('Mails au-delà des %d plus récents', $settings->maxMessages), (string) $report->pruned]
        : ['Mails au-delà de max_messages', 'désactivé (max_messages = 0)'],
    ['Fichiers temporaires effacés', (string) $report->uploads],
    ['Dossiers sans mail en base effacés', (string) $report->directories],
    ['Fichiers sans pièce en base effacés', (string) $report->files],
];
$width = max(array_map(static fn (array $line): int => mb_strlen($line[0]), $lines));

fwrite(STDOUT, 'Nettoyage terminé :' . PHP_EOL);
foreach ($lines as [$label, $value]) {
    fwrite(STDOUT, sprintf('  %s%s : %s', $label, str_repeat(' ', $width - mb_strlen($label)), $value) . PHP_EOL);
}

if ($report->errors > 0) {
    fwrite(STDERR, sprintf(
        '%d fichiers ou dossiers n\'ont pas pu être effacés : détail dans %s.' . PHP_EOL,
        $report->errors,
        $settings->logFile ?? 'le log de l\'hébergement',
    ));
    exit(1);
}
