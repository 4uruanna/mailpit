<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/**
 * Vérifie qu'une adresse IP (v4 ou v6) appartient à une liste d'adresses
 * ou de plages CIDR.
 */
final readonly class IpMatcher
{
    /** @var list<array{bytes: string, prefix: int}> */
    private array $ranges;

    /**
     * @param list<string> $entries ex. ['203.0.113.10', '198.51.100.0/24', '2001:db8::/64']
     * @throws InvalidArgumentException si une entrée est invalide
     */
    public function __construct(array $entries)
    {
        $ranges = [];
        foreach ($entries as $entry) {
            [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            $bytes = self::toBytes($ip);
            if ($bytes === null) {
                throw new InvalidArgumentException(sprintf('adresse IP invalide : « %s »', $entry));
            }

            $maxPrefix = strlen($bytes) * 8;
            if ($prefix === null) {
                $prefix = $maxPrefix;
            } elseif (!ctype_digit($prefix) || (int) $prefix > $maxPrefix) {
                throw new InvalidArgumentException(sprintf('plage CIDR invalide : « %s »', $entry));
            }

            $ranges[] = ['bytes' => $bytes, 'prefix' => (int) $prefix];
        }
        $this->ranges = $ranges;
    }

    public function matches(string $ip): bool
    {
        $bytes = self::toBytes($ip);
        if ($bytes === null) {
            return false;
        }

        foreach ($this->ranges as $range) {
            if (strlen($range['bytes']) === strlen($bytes) && self::inRange($bytes, $range['bytes'], $range['prefix'])) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if (strncmp($ip, $network, $fullBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }

    /**
     * Forme binaire de l'IP ; une IPv4 « mappée » en IPv6 (::ffff:1.2.3.4)
     * est ramenée à l'IPv4 correspondante.
     */
    private static function toBytes(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bytes = inet_pton($ip);
        if ($bytes === false) {
            return null;
        }

        if (strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10) . "\xFF\xFF")) {
            return substr($bytes, 12);
        }

        return $bytes;
    }
}
