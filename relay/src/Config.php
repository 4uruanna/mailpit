<?php

declare(strict_types=1);

namespace Relay;

use InvalidArgumentException;

/**
 * Configuration lue dans les variables d'environnement
 * (fichier /etc/mailpit/relay.env, chargé par Podman).
 */
final readonly class Config
{
    public function __construct(
        /** URL de Mailpit vue depuis le conteneur du relais. */
        public string $mailpitUrl,
        /** Identifiants de MP_UI_AUTH (« utilisateur:motdepasse »), null si pas d'authentification. */
        public ?string $mailpitAuth,
        /** URL de l'API qui reçoit les mails complets (avec ?token=… si besoin). */
        public string $targetUrl,
        /** Joindre aussi le mail brut (.eml, pièces jointes comprises) encodé en base64. */
        public bool $includeRaw,
        /** Au-delà de cette taille (octets), le mail brut n'est pas joint. */
        public int $maxRawSize,
        /** Nombre d'essais avant d'abandonner un mail (rangé dans failed/). */
        public int $maxAttempts,
        /** Délai maximal d'une requête HTTP (secondes). */
        public int $timeout,
        /** Remettre en « non lu » dans Mailpit les messages que le relais a lus. */
        public bool $keepUnread,
        /** Dossier des files d'attente (volume Podman). */
        public string $dataDir,
        /** Adresse d'écoute du récepteur de webhook. */
        public string $listen,
    ) {
    }

    public static function fromEnv(): self
    {
        $target = self::env('RELAY_TARGET_URL');
        if ($target === null || !preg_match('#^https?://#i', $target) || filter_var($target, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('RELAY_TARGET_URL manquante ou invalide (ex. https://api.amnesic.dev/hook/mailpit?token=…)');
        }

        $mailpit = rtrim(self::env('RELAY_MAILPIT_URL') ?? 'http://mailpit:8025', '/');
        if (!preg_match('#^https?://#i', $mailpit)) {
            throw new InvalidArgumentException('RELAY_MAILPIT_URL invalide (ex. http://mailpit:8025)');
        }

        $auth = self::env('RELAY_MAILPIT_AUTH');
        if ($auth !== null && !str_contains($auth, ':')) {
            throw new InvalidArgumentException('RELAY_MAILPIT_AUTH doit être au format utilisateur:motdepasse');
        }

        return new self(
            mailpitUrl: $mailpit,
            mailpitAuth: $auth,
            targetUrl: $target,
            includeRaw: self::bool('RELAY_INCLUDE_RAW', false),
            maxRawSize: self::int('RELAY_MAX_RAW_SIZE', 5 * 1024 * 1024),
            maxAttempts: self::int('RELAY_MAX_ATTEMPTS', 48),
            timeout: self::int('RELAY_TIMEOUT', 30),
            keepUnread: self::bool('RELAY_KEEP_UNREAD', true),
            dataDir: rtrim(self::env('RELAY_DATA_DIR') ?? '/data', '/'),
            listen: self::env('RELAY_LISTEN') ?? '0.0.0.0:8080',
        );
    }

    /** Valeur sans les identifiants, pour les logs. */
    public function describe(): string
    {
        $target = preg_replace('/([?&]token=)[^&]*/i', '$1***', $this->targetUrl);

        return sprintf(
            'Mailpit=%s (auth %s), API=%s, mail brut=%s, essais max=%d',
            $this->mailpitUrl,
            $this->mailpitAuth === null ? 'non' : 'oui',
            $target,
            $this->includeRaw ? sprintf('oui (≤ %d octets)', $this->maxRawSize) : 'non',
            $this->maxAttempts,
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? null : trim($value);
    }

    private static function bool(string $name, bool $default): bool
    {
        $value = self::env($name);
        if ($value === null) {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new InvalidArgumentException(sprintf('%s doit valoir true ou false', $name));
        }

        return $bool;
    }

    private static function int(string $name, int $default): int
    {
        $value = self::env($name);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('%s doit être un entier positif', $name));
        }

        return (int) $value;
    }
}
