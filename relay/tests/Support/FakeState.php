<?php

declare(strict_types=1);

namespace Relay\Tests\Support;

use RuntimeException;

/**
 * État partagé entre un test et ses faux serveurs (faux Mailpit, fausse API) : un fichier JSON
 * temporaire, verrouillé (flock) à chaque lecture ou écriture.
 *
 * Aussi chargé directement (require) par les scripts de tests/Fixtures/, sans autoloader.
 */
final class FakeState
{
    public function __construct(public readonly string $file)
    {
    }

    /** @param array<string, mixed> $state */
    public static function create(array $state): self
    {
        $file = tempnam(sys_get_temp_dir(), 'relay-state-');
        if ($file === false) {
            throw new RuntimeException('impossible de créer le fichier d\'état');
        }
        $self = new self($file);
        $self->update(static function (array &$current) use ($state): void {
            $current = $state;
        });

        return $self;
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        return $this->update(static fn (array $state): array => $state);
    }

    /**
     * Lit l'état, le passe à $callback (par référence pour le modifier), puis l'enregistre.
     *
     * @template T
     * @param callable(array<string, mixed>&): T $callback
     * @return T
     */
    public function update(callable $callback): mixed
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('fichier d\'état inaccessible : ' . $this->file);
        }

        try {
            $json = stream_get_contents($handle);
            $state = $json === '' || $json === false ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $result = $callback($state);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function delete(): void
    {
        @unlink($this->file);
    }
}
