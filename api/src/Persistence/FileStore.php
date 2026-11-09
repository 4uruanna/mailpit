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
 *
 *   <storage_dir>/.upload-<aléatoire>          fichier temporaire d'un envoi en cours
 *   <storage_dir>/.maintenance                 date du dernier nettoyage (voir Maintenance)
 *
 * Un envoi interrompu (PHP arrêté en cours de route) laisse son fichier temporaire, et un
 * effacement raté laisse un dossier sans mail en base : Maintenance les retire.
 */
final readonly class FileStore
{
    private const int CHUNK = 1024 * 1024;

    /** Préfixe des fichiers temporaires de receive(). */
    private const string UPLOAD_PREFIX = '.upload-';

    /** Nom d'un dossier de mail : un ID Mailpit (jamais de « / » ni de « .. »). */
    private const string MESSAGE_DIR_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Nom d'un fichier de pièce (voir partPath()). */
    private const string PART_FILE_PATTERN = '/^part-[0-9]{1,4}(\.[0-9]{1,4}){0,9}$/';

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

        $tmp = $this->directory . '/' . self::UPLOAD_PREFIX . bin2hex(random_bytes(8));
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
        if (!preg_match(self::MESSAGE_DIR_PATTERN, $mailpitId)) {
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
     * Efface le fichier d'une pièce (chemin relatif tel que renvoyé par commit()), puis son
     * dossier s'il est vide. Sert quand le mail a été supprimé pendant l'envoi de la pièce.
     *
     * @return bool false si le chemin est invalide ou si le fichier n'a pas pu être effacé
     */
    public function deletePart(string $relativePath): bool
    {
        if (!self::isPartPath($relativePath)) {
            return false;
        }

        $file = $this->directory . '/' . $relativePath;
        $ok = !is_file($file) || @unlink($file);
        // Échoue sans bruit si le dossier contient encore d'autres fichiers.
        @rmdir(dirname($file));

        return $ok;
    }

    /**
     * Fichier qui garde la date du dernier nettoyage (voir Maintenance). Le dossier de stockage
     * est créé au besoin.
     */
    public function maintenanceFile(): string
    {
        $this->ensureDir($this->directory);

        return $this->directory . '/.maintenance';
    }

    /**
     * Efface les fichiers temporaires (« .upload-… ») modifiés avant $before : ceux d'un envoi
     * interrompu, que PHP a arrêté avant qu'il ne les efface (délai dépassé, par exemple).
     *
     * @param int $before date Unix ; un fichier plus récent peut appartenir à un envoi en cours
     * @return int nombre de fichiers effacés
     */
    public function deleteStaleUploads(int $before): int
    {
        $deleted = 0;
        foreach ($this->entries($this->directory) as [$name, $mtime]) {
            $path = $this->directory . '/' . $name;
            if (str_starts_with($name, self::UPLOAD_PREFIX) && $mtime < $before && is_file($path) && !is_link($path) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Dossiers de mails présents sur le disque.
     *
     * @return list<array{id: string, modified: int}> ID Mailpit et date de modification du dossier (Unix)
     */
    public function messageDirectories(): array
    {
        $directories = [];
        foreach ($this->entries($this->directory) as [$name, $mtime]) {
            $path = $this->directory . '/' . $name;
            if (preg_match(self::MESSAGE_DIR_PATTERN, $name) && is_dir($path) && !is_link($path)) {
                $directories[] = ['id' => $name, 'modified' => $mtime];
            }
        }

        return $directories;
    }

    /**
     * Fichiers du dossier d'un mail, quels que soient leurs noms.
     *
     * @return array<string, int> chemin relatif au dossier de stockage (« <ID>/<nom> ») => date de
     *                            modification (Unix)
     */
    public function messageFiles(string $mailpitId): array
    {
        if (!preg_match(self::MESSAGE_DIR_PATTERN, $mailpitId)) {
            return [];
        }

        $files = [];
        $dir = $this->directory . '/' . $mailpitId;
        foreach ($this->entries($dir) as [$name, $mtime]) {
            if (is_file($dir . '/' . $name) || is_link($dir . '/' . $name)) {
                $files[$mailpitId . '/' . $name] = $mtime;
            }
        }

        return $files;
    }

    /**
     * Efface un fichier du dossier d'un mail (chemin relatif renvoyé par messageFiles()).
     */
    public function deleteFile(string $relativePath): bool
    {
        $parts = explode('/', $relativePath);
        if (count($parts) !== 2 || !preg_match(self::MESSAGE_DIR_PATTERN, $parts[0]) || in_array($parts[1], ['', '.', '..'], true)) {
            return false;
        }

        return @unlink($this->directory . '/' . $relativePath);
    }

    /** Supprime le dossier d'un mail s'il est vide. */
    public function deleteDirectoryIfEmpty(string $mailpitId): bool
    {
        if (!preg_match(self::MESSAGE_DIR_PATTERN, $mailpitId)) {
            return false;
        }

        $dir = $this->directory . '/' . $mailpitId;

        return is_dir($dir) && !is_link($dir) && @rmdir($dir);
    }

    /**
     * Ouvre en lecture le fichier d'une pièce (chemin relatif tel qu'enregistré en base).
     *
     * @return array{handle: resource, size: int}|null null si le chemin est invalide ou le fichier absent
     */
    public function open(?string $relativePath): ?array
    {
        // Chemin écrit par partPath() : on n'accepte rien d'autre (jamais de « .. »).
        if ($relativePath === null || !self::isPartPath($relativePath)) {
            return null;
        }

        $file = $this->directory . '/' . $relativePath;
        $handle = is_file($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            return null;
        }

        return ['handle' => $handle, 'size' => (int) filesize($file)];
    }

    /** Chemin écrit par partPath() : « <ID Mailpit>/part-<PartID> ». */
    private static function isPartPath(string $relativePath): bool
    {
        $parts = explode('/', $relativePath);

        return count($parts) === 2
            && preg_match(self::MESSAGE_DIR_PATTERN, $parts[0]) === 1
            && preg_match(self::PART_FILE_PATTERN, $parts[1]) === 1;
    }

    /**
     * Entrées d'un dossier (sans « . » ni « .. »), avec leur date de modification.
     * Un dossier absent ou illisible donne une liste vide.
     *
     * @return list<array{0: string, 1: int}> [nom, date de modification (Unix)]
     */
    private function entries(string $dir): array
    {
        $entries = [];
        foreach (is_dir($dir) ? scandir($dir) ?: [] : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // lstat : un lien symbolique est daté lui-même, jamais suivi.
            $stat = @lstat($dir . '/' . $name);
            if ($stat !== false) {
                $entries[] = [(string) $name, (int) $stat['mtime']];
            }
        }

        return $entries;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('impossible de créer le dossier %s', $dir));
        }
    }
}
