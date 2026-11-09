<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Ce qu'un appelant de l'API a le droit de voir, déterminé par son jeton.
 *
 *  - jeton développeur : les mails des adresses associées au jeton (Dashboard > Tokens) ;
 *  - jeton du relais   : tous les mails (lecture seule, depuis les IP autorisées).
 */
final readonly class ApiScope
{
    public const string ATTRIBUTE = 'api_scope';

    /**
     * @param list<string>|null $addresses adresses autorisées (normalisées), null = toutes
     */
    private function __construct(
        public ?int $tokenId,
        public ?array $addresses,
    ) {
    }

    /** @param list<string> $addresses */
    public static function token(int $tokenId, array $addresses): self
    {
        return new self($tokenId, $addresses);
    }

    public static function relay(): self
    {
        return new self(null, null);
    }

    public function isRelay(): bool
    {
        return $this->tokenId === null;
    }

    public function allows(string $address): bool
    {
        return $this->addresses === null || in_array($address, $this->addresses, true);
    }
}
