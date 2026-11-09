<?php

declare(strict_types=1);

namespace App\Log;

use Throwable;

/**
 * Rotation du fichier de log de l'API (log_file), sans tâche planifiée (hébergement mutualisé).
 *
 * Appelée au démarrage de chaque requête (Kernel), avant que PHP n'y écrive : quand le fichier atteint
 * maxSize octets, il est renommé en « <log_file>.1 » (l'archive précédente est remplacée), et le log
 * repart de zéro dans un nouveau fichier, créé par la prochaine écriture. Deux fichiers au plus, donc :
 * le log en cours et une archive, chacun d'environ maxSize octets.
 *
 * Coût : un simple stat tant que le fichier est sous la limite.
 *
 * Plusieurs requêtes simultanées : le renommage se fait sous un verrou non bloquant (flock sur
 * « <log_file>.lock »), et la taille est vérifiée de nouveau une fois le verrou obtenu. Une requête
 * qui arrive juste après un renommage voit le nouveau fichier, vide : elle ne remplace pas l'archive
 * par un fichier neuf.
 *
 * Ne lève jamais d'exception : une rotation impossible (droits…) ne doit pas faire échouer la requête.
 */
final readonly class LogRotator
{
    /** Taille maximale par défaut d'un fichier de log : 10 Mo. */
    public const int DEFAULT_MAX_SIZE = 10 * 1024 * 1024;

    public function __construct(
        /** Fichier de log ; null = log par défaut de l'hébergement, jamais touché. */
        private ?string $file,
        /** Taille, en octets, à partir de laquelle le fichier est archivé ; 0 = jamais. */
        private int $maxSize = self::DEFAULT_MAX_SIZE,
    ) {
    }

    /** Archive : le fichier de log suivi de « .1 ». */
    public function archive(): ?string
    {
        return $this->file === null ? null : $this->file . '.1';
    }

    /**
     * Au démarrage d'une requête (Kernel) ou d'un script (bin/cleanup.php) : archive le log s'il a
     * atteint la limite, puis y dirige error_log(). Un archivage impossible est signalé dans le log.
     */
    public function start(): LogRotation
    {
        $rotation = $this->rotate();
        if ($this->file === null) {
            return $rotation;
        }

        ini_set('error_log', $this->file);
        if ($rotation === LogRotation::Failed) {
            error_log(sprintf(
                '[log] archivage de « %s » en « %s » impossible : le dossier doit être accessible en écriture par PHP',
                $this->file,
                $this->archive(),
            ));
        }

        return $rotation;
    }

    /** Archive le fichier s'il a atteint la limite, sans changer la destination de error_log(). */
    public function rotate(): LogRotation
    {
        if ($this->file === null || $this->maxSize <= 0) {
            return LogRotation::NotNeeded;
        }

        try {
            if (!$this->isFull()) {
                return LogRotation::NotNeeded;
            }

            return $this->locked();
        } catch (Throwable) {
            return LogRotation::Failed;
        }
    }

    private function locked(): LogRotation
    {
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            return LogRotation::Failed;
        }

        $locked = false;
        try {
            $locked = flock($lock, LOCK_EX | LOCK_NB, $wouldBlock);
            if (!$locked && $wouldBlock === 1) {
                return LogRotation::Busy;
            }
            // Verrou impossible (système de fichiers qui ne le gère pas) : on archive quand même,
            // plutôt que jamais, comme le nettoyage (Maintenance). Sinon le log grossirait sans limite,
            // et sans rien signaler. La nouvelle vérification de la taille protège toujours l'archive.

            try {
                // Une autre requête a pu archiver le fichier entre le premier stat et le verrou :
                // le nouveau fichier est alors vide (ou absent), et l'archive ne doit pas être remplacée.
                if (!$this->isFull()) {
                    return LogRotation::NotNeeded;
                }

                // rename() remplace l'archive précédente d'un coup. Une requête qui écrit au même moment
                // écrit dans l'un ou l'autre fichier : aucune ligne n'est perdue.
                return @rename($this->file, $this->file . '.1') ? LogRotation::Rotated : LogRotation::Failed;
            } finally {
                if ($locked) {
                    flock($lock, LOCK_UN);
                }
            }
        } finally {
            fclose($lock);
        }
    }

    private function isFull(): bool
    {
        clearstatcache(true, $this->file);
        $size = @filesize($this->file);

        return $size !== false && $size >= $this->maxSize && is_file($this->file);
    }
}
