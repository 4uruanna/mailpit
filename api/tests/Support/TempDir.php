<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * Dossiers temporaires des tests (stockage des pièces jointes), supprimés après chaque test.
 */
final class TempDir
{
    /** Fichier qui reçoit les error_log() du code pendant les tests. */
    public static function logFile(): string
    {
        return sys_get_temp_dir() . '/mailpit-api-tests.log';
    }

    public static function create(string $prefix = 'mailpit-api-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException(sprintf('impossible de créer le dossier temporaire %s', $dir));
        }

        return $dir;
    }

    /** Supprime le dossier et tout son contenu. */
    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Fichiers contenus dans le dossier (chemins relatifs, triés), dossiers exclus.
     * Le fichier « .maintenance » (date du dernier nettoyage, voir Maintenance) n'est pas listé :
     * il est créé après chaque requête du relais. Les fichiers temporaires « .upload-… » le sont.
     *
     * @return list<string>
     */
    public static function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($dir) + 1);
            if ($file->isFile() && $path !== '.maintenance') {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }
}
