<?php

declare(strict_types=1);

namespace App\Mailpit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Message reçu sur POST /v1/messages.
 *
 * Envoyé directement par le webhook de Mailpit, c'est un simple résumé (MessageSummary). Exemple :
 * {
 *   "ID": "VnV7f5bZqzoCs2LCh3yGNN", "MessageID": "abc@example.com", "Read": false,
 *   "From": {"Name": "Alice", "Address": "alice@example.com"},
 *   "To": [{"Name": "", "Address": "test@test.mondomaine.fr"}], "Cc": [], "Bcc": [], "ReplyTo": [],
 *   "Subject": "Bonjour", "Created": "2026-11-08T21:15:03.123456789+01:00",
 *   "Username": "", "Tags": [], "Size": 1234, "Attachments": 0, "Snippet": "Texte…"
 * }
 *
 * Envoyé par le relais (dossier relay/ du dépôt), c'est le mail complet. Il contient en plus :
 *   "ReturnPath": "…", "Date": "2026-11-08T21:15:01+01:00",
 *   "Text": "corps texte", "HTML": "corps HTML",
 *   "Headers": {"Received": ["…", "…"], "X-Mailer": ["…"]},
 *   "Parts": [{"PartID": "2", "Kind": "attachment", "FileName": "devis.pdf",
 *              "ContentType": "application/pdf", "ContentID": "", "Size": 123456}, …]
 * Le contenu des pièces (Parts) est envoyé ensuite, une requête par pièce.
 */
final readonly class Message
{
    /** Format des ID Mailpit (et garde-fou pour les noms de dossiers). */
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Au-delà, le message est refusé (protection contre un JSON aberrant). */
    private const int MAX_PARTS = 1000;

    /**
     * @param array{name: string, address: string}|null $from
     * @param list<array{name: string, address: string}> $to
     * @param list<array{name: string, address: string}> $cc
     * @param list<array{name: string, address: string}> $bcc
     * @param list<array{name: string, address: string}> $replyTo
     * @param list<string> $tags
     * @param array<string, list<string>>|null $headers
     * @param list<Part> $parts
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public ?array $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public array $replyTo,
        public string $subject,
        public DateTimeImmutable $created,
        public string $username,
        public array $tags,
        public int $size,
        public int $attachments,
        public string $snippet,
        public ?string $text = null,
        public ?string $html = null,
        public string $returnPath = '',
        /** En-tête Date du mail (UTC), si fourni. */
        public ?DateTimeImmutable $date = null,
        /** Tous les en-têtes du mail (nom => valeurs), envoyés par le relais. */
        public ?array $headers = null,
        public array $parts = [],
    ) {
    }

    /**
     * @throws InvalidPayloadException
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidPayloadException('le corps doit être un objet JSON');
        }

        $id = $data['ID'] ?? null;
        if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidPayloadException('champ « ID » manquant ou invalide');
        }

        $created = $data['Created'] ?? null;
        if (!is_string($created) || $created === '') {
            throw new InvalidPayloadException('champ « Created » manquant');
        }

        $from = $data['From'] ?? null;

        return new self(
            id: $id,
            messageId: self::string($data, 'MessageID'),
            from: $from === null ? null : self::address($from, 'From'),
            to: self::addressList($data, 'To'),
            cc: self::addressList($data, 'Cc'),
            bcc: self::addressList($data, 'Bcc'),
            replyTo: self::addressList($data, 'ReplyTo'),
            subject: self::string($data, 'Subject'),
            created: self::date($created, 'Created'),
            username: self::string($data, 'Username'),
            tags: self::tags($data),
            size: self::uint($data, 'Size'),
            attachments: self::uint($data, 'Attachments'),
            snippet: self::string($data, 'Snippet'),
            text: self::optionalString($data, 'Text'),
            html: self::optionalString($data, 'HTML'),
            returnPath: self::string($data, 'ReturnPath'),
            date: self::optionalDate($data, 'Date'),
            headers: self::headers($data),
            parts: self::parts($data),
        );
    }

    /** Pièce annoncée dans le JSON, ou null. */
    public function part(string $partId): ?Part
    {
        foreach ($this->parts as $part) {
            if ($part->partId === $partId) {
                return $part;
            }
        }

        return null;
    }

    private static function date(string $value, string $key): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new InvalidPayloadException(sprintf('champ « %s » : date invalide', $key));
        }
    }

    private static function optionalDate(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::optionalString($data, $key);
        // Mailpit renvoie la date zéro de Go quand le mail n'a pas d'en-tête Date.
        if ($value === null || $value === '' || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        return self::date($value, $key);
    }

    /** @return array<string, list<string>>|null */
    private static function headers(array $data): ?array
    {
        $value = $data['Headers'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidPayloadException('champ « Headers » : objet attendu');
        }
        foreach ($value as $name => $values) {
            if (!is_array($values) || !array_is_list($values)) {
                throw new InvalidPayloadException(sprintf('champ « Headers.%s » : liste attendue', $name));
            }
            foreach ($values as $item) {
                if (!is_string($item)) {
                    throw new InvalidPayloadException(sprintf('champ « Headers.%s » : liste de textes attendue', $name));
                }
            }
        }

        return $value;
    }

    /** @return list<Part> */
    private static function parts(array $data): array
    {
        $value = $data['Parts'] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidPayloadException('champ « Parts » : liste attendue');
        }
        if (count($value) > self::MAX_PARTS) {
            throw new InvalidPayloadException(sprintf('champ « Parts » : %d pièces au maximum', self::MAX_PARTS));
        }

        $parts = [];
        foreach ($value as $index => $item) {
            $part = Part::fromArray($item, $index);
            if (isset($parts[$part->partId])) {
                throw new InvalidPayloadException(sprintf('champ « Parts » : PartID « %s » en double', $part->partId));
            }
            $parts[$part->partId] = $part;
        }

        return array_values($parts);
    }

    private static function optionalString(array $data, string $key): ?string
    {
        return array_key_exists($key, $data) && $data[$key] !== null ? self::string($data, $key) : null;
    }

    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value)) {
            throw new InvalidPayloadException(sprintf('champ « %s » : texte attendu', $key));
        }

        return $value;
    }

    private static function uint(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;
        if ((!is_int($value) && !is_float($value)) || $value < 0) {
            throw new InvalidPayloadException(sprintf('champ « %s » : entier positif attendu', $key));
        }

        return (int) $value;
    }

    /** @return array{name: string, address: string} */
    private static function address(mixed $value, string $key): array
    {
        if (!is_array($value)
            || !is_string($value['Address'] ?? '')
            || !is_string($value['Name'] ?? '')
        ) {
            throw new InvalidPayloadException(sprintf('champ « %s » : adresse invalide', $key));
        }

        return ['name' => $value['Name'] ?? '', 'address' => $value['Address'] ?? ''];
    }

    /** @return list<array{name: string, address: string}> */
    private static function addressList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidPayloadException(sprintf('champ « %s » : liste attendue', $key));
        }

        return array_map(static fn (mixed $item): array => self::address($item, $key), $value);
    }

    /** @return list<string> */
    private static function tags(array $data): array
    {
        $value = $data['Tags'] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidPayloadException('champ « Tags » : liste attendue');
        }
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                throw new InvalidPayloadException('champ « Tags » : liste de textes attendue');
            }
        }

        return $value;
    }
}
