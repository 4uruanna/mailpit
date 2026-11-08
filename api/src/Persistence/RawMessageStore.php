<?php

declare(strict_types=1);

namespace App\Persistence;

use RuntimeException;

/**
 * Enregistre les mails bruts (.eml) en fichiers, hors de la base :
 * les pièces jointes peuvent être lourdes pour une base mutualisée.
 * Le dossier par défaut (var/messages) est protégé par var/.htaccess.
 */
final readonly class RawMessageStore
{
    public function __construct(private string $directory)
    {
    }

    /**
     * @param string $id ID Mailpit (déjà validé : lettres, chiffres, - et _)
     * @return string nom du fichier enregistré
     */
    public function save(string $id, string $raw): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('impossible de créer le dossier %s', $this->directory));
        }

        $name = $id . '.eml';
        $tmp = $this->directory . '/.' . $name . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $raw) === false || !rename($tmp, $this->directory . '/' . $name)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('impossible d\'écrire %s dans %s', $name, $this->directory));
        }

        return $name;
    }
}
