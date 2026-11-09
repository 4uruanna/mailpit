<?php

declare(strict_types=1);

namespace App\Mailpit;

/**
 * Pièce d'un mail annoncée par le relais : pièce jointe, ou image intégrée au HTML (« inline »).
 * Le contenu n'est pas dans le JSON : il est envoyé ensuite, une requête par pièce
 * (POST /v1/messages/{id}/part/{partId}).
 */
final readonly class Part
{
    public const string KIND_ATTACHMENT = 'attachment';
    public const string KIND_INLINE = 'inline';

    /** PartID Mailpit (« 2 », « 1.3 », « 2.1.4 »…). Sert aussi de nom de fichier : rien d'autre n'est accepté. */
    public const string ID_PATTERN = '/^[0-9]{1,4}(\.[0-9]{1,4}){0,9}$/';

    public function __construct(
        public string $partId,
        public string $kind,
        public string $fileName,
        public string $contentType,
        public string $contentId,
        public int $size,
    ) {
    }

    /**
     * @throws InvalidPayloadException
     */
    public static function fromArray(mixed $data, int $index): self
    {
        $where = sprintf('champ « Parts[%d] »', $index);
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidPayloadException($where . ' : objet attendu');
        }

        $partId = $data['PartID'] ?? null;
        if (!is_string($partId) || !preg_match(self::ID_PATTERN, $partId)) {
            throw new InvalidPayloadException($where . ' : « PartID » manquant ou invalide');
        }

        $kind = $data['Kind'] ?? null;
        if (!in_array($kind, [self::KIND_ATTACHMENT, self::KIND_INLINE], true)) {
            throw new InvalidPayloadException($where . ' : « Kind » doit valoir attachment ou inline');
        }

        $size = $data['Size'] ?? null;
        if (!is_int($size) || $size < 0) {
            throw new InvalidPayloadException($where . ' : « Size » doit être un entier positif');
        }

        foreach (['FileName', 'ContentType', 'ContentID'] as $key) {
            if (!is_string($data[$key] ?? '')) {
                throw new InvalidPayloadException(sprintf('%s : « %s » doit être un texte', $where, $key));
            }
        }

        return new self(
            partId: $partId,
            kind: $kind,
            fileName: mb_substr($data['FileName'] ?? '', 0, 255),
            contentType: mb_substr($data['ContentType'] ?? '', 0, 255),
            contentId: mb_substr($data['ContentID'] ?? '', 0, 255),
            size: $size,
        );
    }
}
