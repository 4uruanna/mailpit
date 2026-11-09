<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
use Psr\Http\Message\StreamInterface;

/**
 * Corps de requête qui appelle une fonction à sa première lecture : simule ce qui se passe
 * pendant qu'une action lit le corps (ex. le mail supprimé pendant l'envoi d'une pièce).
 */
final class OnFirstReadStream implements StreamInterface
{
    private ?Closure $callback;

    public function __construct(private readonly StreamInterface $stream, Closure $callback)
    {
        $this->callback = $callback;
    }

    public function read(int $length): string
    {
        if ($this->callback !== null) {
            $callback = $this->callback;
            $this->callback = null;
            $callback();
        }

        return $this->stream->read($length);
    }

    public function __toString(): string
    {
        return (string) $this->stream;
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
