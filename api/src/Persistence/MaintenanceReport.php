<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Bilan d'un nettoyage (voir Maintenance).
 */
final readonly class MaintenanceReport
{
    public function __construct(
        /** Mails supprimés car reçus par Mailpit il y a plus de webhook.max_age_days jours. */
        public int $expired,
        /** Mails supprimés au-delà des webhook.max_messages plus récents. */
        public int $pruned,
        /** Fichiers temporaires « .upload-… » d'envois interrompus. */
        public int $uploads,
        /** Dossiers de mails sans ligne dans mailpit_messages. */
        public int $directories,
        /** Fichiers rattachés à aucune pièce (mailpit_parts.storage_path). */
        public int $files,
        /** Fichiers ou dossiers qui n'ont pas pu être effacés (détail dans le log). */
        public int $errors,
        /** false : limite par passage atteinte, le reste sera fait au passage suivant. */
        public bool $finished,
    ) {
    }

    /** Rien n'a été supprimé, et aucune erreur. */
    public function isEmpty(): bool
    {
        return $this->expired + $this->pruned + $this->uploads + $this->directories + $this->files + $this->errors === 0;
    }

    /** Une ligne pour le log. */
    public function summary(): string
    {
        $text = sprintf(
            'mails supprimés : %d trop anciens, %d au-delà de max_messages ; effacés : %d fichiers temporaires, %d dossiers et %d fichiers orphelins',
            $this->expired,
            $this->pruned,
            $this->uploads,
            $this->directories,
            $this->files,
        );
        if ($this->errors > 0) {
            $text .= sprintf(' ; %d effacements impossibles', $this->errors);
        }
        if (!$this->finished) {
            $text .= ' ; la suite au prochain passage';
        }

        return $text;
    }
}
