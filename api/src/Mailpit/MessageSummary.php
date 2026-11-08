<?php

declare(strict_types=1);

namespace App\Mailpit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Résumé de message envoyé par le webhook Mailpit (MessageSummary côté Mailpit).
 *
 * Exemple de payload :
 * {
 *   "ID": "VnV7f5bZqzoCs2LCh3yGNN", "MessageID": "abc@example.com", "Read": false,
 *   "From": {"Name": "Alice", "Address": "alice@example.com"},
 *   "To": [{"Name": "", "Address": "test@test.mondomaine.fr"}], "Cc": [], "Bcc": [], "ReplyTo": [],
 *   "Subject": "Bonjour", "Created": "2026-11-08T21:15:03.123456789+01:00",
 *   "Username": "", "Tags": [], "Size": 1234, "Attachments": 0, "Snippet": "Texte…"
 * }
 *
 * Envoyé directement par Mailpit, il ne contient que ce résumé. Envoyé par le relais
 * (dossier relay/ du dépôt), il contient en plus :
 *   "Text": "corps texte", "HTML": "corps HTML", "Raw": "mail brut .eml en base64" (facultatif)
 */
final readonly class MessageSummary
{
    /**
     * @param array{name: string, address: string}|null $from
     * @param list<array{name: string, address: string}> $to
     * @param list<array{name: string, address: string}> $cc
     * @param list<array{name: string, address: string}> $bcc
     * @param list<array{name: string, address: string}> $replyTo
     * @param list<string> $tags
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
        /** Mail brut (.eml) décodé, ou null. */
        public ?string $raw = null,
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
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new InvalidPayloadException('champ « ID » manquant ou invalide');
        }

        $created = $data['Created'] ?? null;
        if (!is_string($created) || $created === '') {
            throw new InvalidPayloadException('champ « Created » manquant');
        }
        try {
            $createdAt = new DateTimeImmutable($created)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new InvalidPayloadException('champ « Created » : date invalide');
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
            created: $createdAt,
            username: self::string($data, 'Username'),
            tags: self::tags($data),
            size: self::uint($data, 'Size'),
            attachments: self::uint($data, 'Attachments'),
            snippet: self::string($data, 'Snippet'),
            text: self::optionalString($data, 'Text'),
            html: self::optionalString($data, 'HTML'),
            raw: self::raw($data),
        );
    }

    private static function optionalString(array $data, string $key): ?string
    {
        return array_key_exists($key, $data) && $data[$key] !== null ? self::string($data, $key) : null;
    }

    private static function raw(array $data): ?string
    {
        $encoded = self::optionalString($data, 'Raw');
        if ($encoded === null) {
            return null;
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new InvalidPayloadException('champ « Raw » : base64 invalide');
        }

        return $raw;
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
