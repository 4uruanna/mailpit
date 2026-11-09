<?php

declare(strict_types=1);

namespace App\Mailpit;

/**
 * Adresses e-mail telles qu'elles sont comparées par l'API : sans espaces autour, en minuscules.
 */
final class Address
{
    public const int MAX_LENGTH = 320;

    public static function normalize(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /** Adresse normalisée, ou null si elle n'a pas la forme d'une adresse e-mail. */
    public static function parse(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $address = self::normalize($value);
        if ($address === '' || strlen($address) > self::MAX_LENGTH) {
            return null;
        }

        return filter_var($address, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false ? null : $address;
    }
}
