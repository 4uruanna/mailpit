<?php

declare(strict_types=1);

namespace App\Persistence;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Enregistre en fichiers, hors de la base, les pièces jointes : elles peuvent être lourdes
 * pour une base mutualisée.
 *
 *   <storage_dir>/<ID Mailpit>/part-<PartID>   pièce jointe ou image intégrée
 *
 * Le nom d'origine de la pièce jointe est gardé en base : sur le disque, seuls des noms
 * déjà validés (ID Mailpit, PartID) sont utilisés. Le dossier par défaut (var/messages)
 * est protégé par var/.htaccess.
 *
 * Écriture en deux temps : receive() copie le corps de la requête dans un fichier
 * temporaire en calculant sa taille et son SHA-256, puis commit() le met à sa place
 * (rename atomique) une fois l'empreinte vérifiée. Un fichier n'est donc jamais
 * visible à moitié écrit.
 */
final readonly class FileStore
{
    private const int CHUNK = 1024 * 1024;

    public function __construct(private string $directory)
    {
    }

    /** Nom du fichier d'une pièce, relatif au dossier de stockage. */
    public static function partPath(string $mailpitId, string $partId): string
    {
        return $mailpitId . '/part-' . $partId;
    }

    /**
     * Copie le flux dans un fichier temporaire.
     *
     * @return array{tmp: string, size: int, sha256: string}|null null si le flux dépasse $maxSize octets
     */
    public function receive(StreamInterface $body, int $maxSize): ?array
    {
        $this->ensureDir($this->directory);

        $tmp = $this->directory . '/.upload-' . bin2hex(random_bytes(8));
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('impossible de créer un fichier dans %s', $this->directory));
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $maxSize) {
                    fclose($handle);
                    @unlink($tmp);

                    return null;
                }
                hash_update($hash, $chunk);
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('écriture incomplète (disque plein ?)');
                }
            }
            // Données sur le disque avant de répondre « enregistré » au relais.
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('impossible de forcer l\'écriture sur le disque');
            }
            fclose($handle);
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmp);
            throw $e;
        }

        return ['tmp' => $tmp, 'size' => $size, 'sha256' => hash_final($hash)];
    }

    /**
     * Met le fichier temporaire à sa place définitive (un éventuel fichier précédent est remplacé).
     *
     * @return string chemin relatif au dossier de stockage
     */
    public function commit(string $tmp, string $relativePath): string
    {
        $target = $this->directory . '/' . $relativePath;
        $this->ensureDir(dirname($target));

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('impossible d\'enregistrer %s', $relativePath));
        }

        return $relativePath;
    }

    public function discard(string $tmp): void
    {
        @unlink($tmp);
    }

    /**
     * Efface les fichiers d'un mail supprimé de la base (son dossier).
     *
     * @return bool false si un fichier n'a pas pu être effacé
     */
    public function deleteMessage(string $mailpitId): bool
    {
        // ID Mailpit déjà validé à l'enregistrement : jamais de « / » ni de « .. ».
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $mailpitId)) {
            return false;
        }

        $ok = true;
        $dir = $this->directory . '/' . $mailpitId;
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $ok = @unlink($dir . '/' . $name) && $ok;
                }
            }
            $ok = @rmdir($dir) && $ok;
        }

        return $ok;
    }

    /**
     * Ouvre en lecture le fichier d'une pièce (chemin relatif tel qu'enregistré en base).
     *
     * @return array{handle: resource, size: int}|null null si le chemin est invalide ou le fichier absent
     */
    public function open(?string $relativePath): ?array
    {
        // Chemin écrit par partPath() : on n'accepte rien d'autre (jamais de « .. »).
        if ($relativePath === null || !preg_match('#^[A-Za-z0-9_-]{1,64}/part-[0-9]{1,4}(\.[0-9]{1,4}){0,9}$#', $relativePath)) {
            return null;
        }

        $file = $this->directory . '/' . $relativePath;
        $handle = is_file($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            return null;
        }

        return ['handle' => $handle, 'size' => (int) filesize($file)];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('impossible de créer le dossier %s', $dir));
        }
    }
}
