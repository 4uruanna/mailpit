<?php

declare(strict_types=1);

namespace App\Persistence;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Nettoyage de l'API, sans tâche planifiée (hébergement mutualisé) :
 *
 *   1. supprime les mails reçus par Mailpit il y a plus de webhook.max_age_days jours ;
 *   2. ne garde que les webhook.max_messages mails les plus récents ;
 *   3. efface les fichiers temporaires « .upload-… » d'envois interrompus (PHP arrêté en cours de route) ;
 *   4. efface les dossiers de mails sans ligne en base (effacement raté après une suppression), et
 *      les fichiers rattachés à aucune pièce.
 *
 * Les mails sont supprimés en base ET sur le disque, par paquets, comme MessageRepository::prune().
 * Les étapes 3 et 4 ne touchent qu'à ce qui n'a pas bougé depuis ORPHAN_AGE : jamais à un envoi en cours.
 *
 * runIfDue() est appelé après les requêtes du relais (MaintenanceMiddleware) : le nettoyage a lieu
 * au plus une fois par INTERVAL. La date du dernier passage est dans <storage_dir>/.maintenance,
 * verrouillé (flock) pendant le nettoyage : deux requêtes simultanées ne nettoient pas en même temps.
 * run() force un nettoyage complet (bin/cleanup.php).
 */
final readonly class Maintenance
{
    /** Au plus un nettoyage toutes les 15 minutes. */
    public const int INTERVAL = 15 * 60;

    /** Un fichier temporaire ou un dossier orphelin n'est effacé qu'après 1 heure sans modification. */
    public const int ORPHAN_AGE = 60 * 60;

    private const int DAY = 24 * 60 * 60;

    public function __construct(
        private MessageRepository $messages,
        private FileStore $files,
        /** 0 = pas de suppression par âge. */
        private int $maxAgeDays,
        /** 0 = sans limite. */
        private int $maxMessages,
        /** Mails supprimés par requête SQL. */
        private int $batchSize = 200,
        /**
         * runIfDue() : paquets supprimés au plus par étape (5 × 200 = 1000 mails), pour que la requête
         * du relais reste rapide. S'il en reste, le passage suivant a lieu dès la requête d'après.
         */
        private int $maxBatches = 5,
    ) {
    }

    /**
     * Nettoie si le dernier passage date de plus de INTERVAL. Ne lève jamais d'exception : une erreur
     * est écrite dans le log, et ne doit pas faire échouer la requête qui a déclenché le nettoyage.
     *
     * @return MaintenanceReport|null null si ce n'était pas le moment, si un autre nettoyage est en
     *                                cours, ou en cas d'erreur
     */
    public function runIfDue(): ?MaintenanceReport
    {
        try {
            return $this->locked(force: false);
        } catch (Throwable $e) {
            error_log('[maintenance] nettoyage impossible : ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Nettoie tout de suite, jusqu'au bout (sans limite de paquets). Attend la fin d'un nettoyage
     * en cours.
     *
     * @throws Throwable base injoignable, dossier de stockage inaccessible…
     */
    public function run(): MaintenanceReport
    {
        return $this->locked(force: true) ?? throw new RuntimeException('nettoyage non lancé');
    }

    private function locked(bool $force): ?MaintenanceReport
    {
        $file = $this->files->maintenanceFile();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('impossible d\'ouvrir %s', $file));
        }

        $locked = false;
        try {
            // Une requête qui trouve le verrou pris n'attend pas : l'autre requête nettoie déjà.
            $locked = flock($handle, $force ? LOCK_EX : LOCK_EX | LOCK_NB, $wouldBlock);
            if (!$locked && $wouldBlock === 1) {
                return null;
            }
            // Verrou impossible (système de fichiers qui ne le gère pas) : on nettoie quand même,
            // plutôt que jamais. Le relais n'envoie qu'une requête à la fois, et l'intervalle reste.

            $now = time();
            $last = (int) trim((string) stream_get_contents($handle));
            // Une date dans le futur (horloge changée) ne bloque pas le nettoyage.
            if (!$force && $last <= $now && $now - $last < self::INTERVAL) {
                return null;
            }

            // Date écrite AVANT le nettoyage : un nettoyage qui échoue n'est pas relancé à chaque requête.
            self::write($handle, $now);
            $report = $this->clean($now, $force ? PHP_INT_MAX : $this->maxBatches);
            if (!$report->finished) {
                // Il reste des mails à supprimer : le passage suivant a lieu dès la prochaine requête.
                self::write($handle, 0);
            }
            if (!$report->isEmpty()) {
                error_log('[maintenance] ' . $report->summary());
            }

            return $report;
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function clean(int $now, int $maxBatches): MaintenanceReport
    {
        $errors = 0;
        $finished = true;

        $expired = 0;
        if ($this->maxAgeDays > 0) {
            $before = new DateTimeImmutable('@' . ($now - $this->maxAgeDays * self::DAY));
            [$expired, $done] = $this->purge(
                fn (): array => $this->messages->deleteReceivedBefore($before, $this->batchSize),
                $maxBatches,
                $errors,
            );
            $finished = $done;
        }

        $pruned = 0;
        if ($this->maxMessages > 0) {
            [$pruned, $done] = $this->purge(
                fn (): array => $this->messages->prune($this->maxMessages, $this->batchSize),
                $maxBatches,
                $errors,
            );
            $finished = $finished && $done;
        }

        $orphanBefore = $now - self::ORPHAN_AGE;
        $uploads = $this->files->deleteStaleUploads($orphanBefore);
        [$directories, $files] = $this->deleteOrphans($orphanBefore, $errors);

        return new MaintenanceReport($expired, $pruned, $uploads, $directories, $files, $errors, $finished);
    }

    /**
     * Supprime des mails par paquets, puis leurs dossiers.
     *
     * @param callable(): list<string> $deleteBatch supprime un paquet, renvoie les ID Mailpit supprimés
     * @return array{0: int, 1: bool} nombre de mails supprimés, et true s'il n'en reste plus
     */
    private function purge(callable $deleteBatch, int $maxBatches, int &$errors): array
    {
        $count = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $deleted = $deleteBatch();
            foreach ($deleted as $mailpitId) {
                if (!$this->files->deleteMessage($mailpitId)) {
                    // Le dossier reste sans ligne en base : il sera retiré avec les orphelins.
                    error_log(sprintf('[maintenance] fichiers de %s pas tous effacés', $mailpitId));
                    $errors++;
                }
            }
            $count += count($deleted);
            if (count($deleted) < $this->batchSize) {
                return [$count, true];
            }
        }

        return [$count, false];
    }

    /**
     * Efface ce que la base ne connaît pas : dossiers de mails supprimés, fichiers d'aucune pièce.
     * Un fichier ou un dossier modifié depuis moins de ORPHAN_AGE est gardé : il peut appartenir
     * à un envoi en cours (pièce sur le disque, pas encore marquée en base).
     *
     * @return array{0: int, 1: int} dossiers et fichiers effacés
     */
    private function deleteOrphans(int $before, int &$errors): array
    {
        $directories = $this->files->messageDirectories();
        if ($directories === []) {
            return [0, 0];
        }

        // MySQL compare mailpit_id sans tenir compte de la casse : on fait de même.
        $known = [];
        $referenced = [];
        foreach ($this->messages->storedPaths(array_column($directories, 'id')) as $mailpitId => $paths) {
            $known[strtolower((string) $mailpitId)] = true;
            foreach ($paths as $path) {
                $referenced[$path] = true;
            }
        }

        $deletedDirectories = 0;
        $deletedFiles = 0;
        foreach ($directories as ['id' => $mailpitId, 'modified' => $modified]) {
            foreach ($this->files->messageFiles($mailpitId) as $path => $fileModified) {
                if ($fileModified >= $before || isset($referenced[$path])) {
                    continue;
                }
                if ($this->files->deleteFile($path)) {
                    $deletedFiles++;
                } else {
                    error_log(sprintf('[maintenance] impossible d\'effacer %s', $path));
                    $errors++;
                }
            }

            // Dossier d'un mail inconnu, vide maintenant (sinon il reste des fichiers récents).
            if (!isset($known[strtolower($mailpitId)]) && $modified < $before && $this->files->deleteDirectoryIfEmpty($mailpitId)) {
                $deletedDirectories++;
            }
        }

        return [$deletedDirectories, $deletedFiles];
    }

    /** @param resource $handle */
    private static function write($handle, int $timestamp): void
    {
        if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, (string) $timestamp) === false || !fflush($handle)) {
            throw new RuntimeException('impossible d\'écrire la date du nettoyage');
        }
    }
}
