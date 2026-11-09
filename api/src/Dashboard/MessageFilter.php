<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Mailpit\Address;

/**
 * Filtres de la page Messages du Dashboard, lus dans l'URL :
 *
 *   ?address[]=a@test.fr&address[]=b@test.fr   mails reçus par l'une de ces adresses (To, Cc, Bcc)
 *   ?sort=asc                                  du plus ancien au plus récent (par défaut : l'inverse)
 *   ?page=2                                    page (50 mails par page)
 *
 * Ils sont transmis à la page d'un mail, pour que le lien « retour » ramène à la même liste.
 */
final readonly class MessageFilter
{
    public const int MAX_ADDRESSES = 200;

    /** @param list<string> $addresses */
    private function __construct(
        public array $addresses,
        public bool $oldestFirst,
        public int $page,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $values = $query['address'] ?? [];
        $values = is_array($values) ? $values : [$values];

        $addresses = [];
        foreach ($values as $value) {
            $address = is_string($value) ? Address::normalize($value) : '';
            if ($address !== '' && strlen($address) <= Address::MAX_LENGTH) {
                $addresses[$address] = true;
            }
        }

        $page = $query['page'] ?? '1';
        $page = is_string($page) && ctype_digit($page) && strlen($page) <= 6 ? max(1, (int) $page) : 1;

        return new self(
            array_slice(array_map('strval', array_keys($addresses)), 0, self::MAX_ADDRESSES),
            ($query['sort'] ?? '') === 'asc',
            $page,
        );
    }

    /**
     * Chaîne de requête (avec « ? », ou vide) reproduisant ces filtres.
     *
     * @param array{page?: int, oldestFirst?: bool} $override
     * @param array<string, string|null> $extra paramètres ajoutés (ex. ['tab' => 'headers']) ; null = absent
     */
    public function query(array $override = [], array $extra = []): string
    {
        $page = $override['page'] ?? $this->page;
        $oldestFirst = $override['oldestFirst'] ?? $this->oldestFirst;

        $params = array_filter([
            'address' => $this->addresses,
            'sort'    => $oldestFirst ? 'asc' : null,
            'page'    => $page > 1 ? $page : null,
        ] + $extra, static fn (mixed $value): bool => $value !== null && $value !== []);

        return $params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function isActive(): bool
    {
        return $this->addresses !== [] || $this->oldestFirst;
    }
}
