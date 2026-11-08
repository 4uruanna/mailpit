<?php

declare(strict_types=1);

namespace Relay;

use RuntimeException;

/**
 * File d'attente sur disque : un fichier JSON par mail à relayer.
 *
 *   queue/<échéance>_<ID>.json   mails à envoyer ; <échéance> = timestamp du prochain essai
 *   failed/<ID>.json             mails abandonnés (voir bin/retry-failed.php)
 *   tmp/                         écritures en cours (renommées ensuite : pas de fichier à moitié écrit)
 *
 * Le nom commence par l'échéance : il suffit de trier les noms pour trouver le prochain mail.
 */
final readonly class Spool
{
    /** Format des ID Mailpit (et garde-fou pour les noms de fichiers). */
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private string $queueDir;
    private string $failedDir;
    private string $tmpDir;

    public function __construct(string $dataDir)
    {
        $this->queueDir = $dataDir . '/queue';
        $this->failedDir = $dataDir . '/failed';
        $this->tmpDir = $dataDir . '/tmp';
    }

    public function init(): void
    {
        foreach ([$this->queueDir, $this->failedDir, $this->tmpDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('impossible de créer %s', $dir));
            }
        }
    }

    public function isQueued(string $id): bool
    {
        return glob($this->queueDir . '/*_' . $id . '.json') !== [];
    }

    /** @param array<string, mixed> $job */
    public function add(array $job, int $dueAt): void
    {
        $this->write($this->queueDir . '/' . self::fileName($dueAt, $job['id']), $job);
    }

    /**
     * Prochain mail dont l'échéance est passée.
     *
     * @return array{file: string, job: array<string, mixed>}|null
     */
    public function nextDue(int $now): ?array
    {
        foreach ($this->queuedFiles() as $name) {
            if ((int) strstr($name, '_', true) > $now) {
                return null; // trié : les suivants sont encore plus tard
            }

            $file = $this->queueDir . '/' . $name;
            $json = @file_get_contents($file);
            $job = $json === false ? null : json_decode($json, true);
            if (!is_array($job) || !is_string($job['id'] ?? null) || !preg_match(self::ID_PATTERN, $job['id'])) {
                Log::error(sprintf('fichier illisible %s : déplacé dans failed/', $name));
                @rename($file, $this->failedDir . '/' . $name . '.corrupt');
                continue;
            }

            return ['file' => $file, 'job' => $job];
        }

        return null;
    }

    /** @param array<string, mixed> $job */
    public function reschedule(string $file, array $job, int $dueAt): void
    {
        $this->add($job, $dueAt);
        @unlink($file);
    }

    public function remove(string $file): void
    {
        @unlink($file);
    }

    /** @param array<string, mixed> $job */
    public function fail(string $file, array $job): void
    {
        $this->write($this->failedDir . '/' . $job['id'] . '.json', $job);
        @unlink($file);
    }

    /** Remet tous les mails abandonnés dans la file, pour un nouvel essai immédiat. */
    public function retryFailed(int $now): int
    {
        $count = 0;
        foreach (glob($this->failedDir . '/*.json') ?: [] as $file) {
            $job = json_decode((string) file_get_contents($file), true);
            if (!is_array($job) || !is_string($job['id'] ?? null) || !preg_match(self::ID_PATTERN, $job['id'])) {
                continue;
            }
            $job['attempts'] = 0;
            $this->add($job, $now);
            unlink($file);
            $count++;
        }

        return $count;
    }

    /** @return array{queued: int, failed: int} */
    public function stats(): array
    {
        return [
            'queued' => count($this->queuedFiles()),
            'failed' => count(glob($this->failedDir . '/*.json') ?: []),
        ];
    }

    /** @return list<string> noms des fichiers en attente, du plus urgent au moins urgent */
    private function queuedFiles(): array
    {
        $names = array_values(array_filter(
            scandir($this->queueDir) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.json'),
        ));
        sort($names, SORT_STRING);

        return $names;
    }

    private static function fileName(int $dueAt, string $id): string
    {
        return sprintf('%010d_%s.json', $dueAt, $id);
    }

    /** @param array<string, mixed> $data */
    private function write(string $destination, array $data): void
    {
        $tmp = tempnam($this->tmpDir, 'job');
        if ($tmp === false) {
            throw new RuntimeException('impossible de créer un fichier dans ' . $this->tmpDir);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $destination)) {
            @unlink($tmp);
            throw new RuntimeException('impossible d\'écrire ' . $destination);
        }
    }
}
