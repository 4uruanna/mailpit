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
    /** Longueur minimale du jeton (même règle que l'API). */
    public const int MIN_TOKEN_LENGTH = 32;

    /** Intervalle par défaut entre deux pings de santé (secondes). */
    public const int HEARTBEAT_INTERVAL = 30;

    public function __construct(
        /** URL de Mailpit vue depuis le conteneur du relais. */
        public string $mailpitUrl,
        /** Identifiants de MP_UI_AUTH (« utilisateur:motdepasse »), null si pas d'authentification. */
        public ?string $mailpitAuth,
        /** Adresse de base de l'API, sans « / » final (ex. https://api.mondomaine.fr) ; http:// seulement en local. */
        public string $apiUrl,
        /** Jeton partagé avec l'API (webhook.token dans son config.php). */
        public string $apiToken,
        /** Délai maximal d'une requête HTTP (secondes). */
        public int $timeout,
        /** Remettre en « non lu » dans Mailpit les messages que le relais a lus. */
        public bool $keepUnread,
        /** Intervalle entre deux recherches de mails à relayer, en l'absence de webhook (secondes). */
        public int $pollInterval,
        /** Nombre de mails lus dans Mailpit à chaque recherche. */
        public int $batchSize,
        /** Tag posé dans Mailpit quand l'API a tout enregistré. */
        public string $tagDone,
        /** Tag posé dans Mailpit quand l'API refuse définitivement un mail. */
        public string $tagFailed,
        /** Filtre de recherche Mailpit supplémentaire (ex. « after:2026-11-01 »), ou ''. */
        public string $query,
        /** Adresse d'écoute du récepteur de webhook. */
        public string $listen,
        /** Nom de cette instance, envoyé à l'API dans l'en-tête Mailpit-Label (colonne mailpit_label), ou null. */
        public ?string $label = null,
        /** Intervalle entre deux pings de santé (POST /v1/health, secondes) ; 0 = pas de ping. */
        public int $heartbeatInterval = self::HEARTBEAT_INTERVAL,
    ) {
    }

    public static function fromEnv(): self
    {
        $api = self::env('RELAY_API_URL');
        if ($api === null || !preg_match('#^https?://#i', $api) || filter_var($api, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('RELAY_API_URL manquante ou invalide (ex. https://api.mondomaine.fr)');
        }
        if (parse_url($api, PHP_URL_QUERY) !== null || parse_url($api, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('RELAY_API_URL ne doit pas contenir de « ?… » : le jeton va dans RELAY_API_TOKEN');
        }
        // Le jeton et les mails ne circulent jamais en clair : http:// seulement vers cette machine (tests).
        if (strncasecmp($api, 'https://', 8) !== 0 && !self::isLocalHost((string) parse_url($api, PHP_URL_HOST))) {
            throw new InvalidArgumentException(
                'RELAY_API_URL doit commencer par https:// : le jeton et les mails ne doivent pas circuler en clair'
                . ' (http:// n\'est accepté que vers localhost, 127.0.0.1 ou ::1, pour des tests)',
            );
        }

        $token = self::env('RELAY_API_TOKEN');
        if ($token === null || strlen($token) < self::MIN_TOKEN_LENGTH || preg_match('/\s/', $token)) {
            throw new InvalidArgumentException(sprintf(
                'RELAY_API_TOKEN manquant ou trop court (%d caractères minimum, sans espace) : même valeur que webhook.token de l\'API',
                self::MIN_TOKEN_LENGTH,
            ));
        }

        $mailpit = rtrim(self::env('RELAY_MAILPIT_URL') ?? 'http://mailpit:8025', '/');
        if (!preg_match('#^https?://#i', $mailpit)) {
            throw new InvalidArgumentException('RELAY_MAILPIT_URL invalide (ex. http://mailpit:8025)');
        }

        $auth = self::env('RELAY_MAILPIT_AUTH');
        if ($auth !== null && !str_contains($auth, ':')) {
            throw new InvalidArgumentException('RELAY_MAILPIT_AUTH doit être au format utilisateur:motdepasse');
        }

        $tagDone = self::tag('RELAY_TAG_DONE', 'relayed');
        $tagFailed = self::tag('RELAY_TAG_FAILED', 'relay-failed');
        if (strcasecmp($tagDone, $tagFailed) === 0) {
            throw new InvalidArgumentException('RELAY_TAG_DONE et RELAY_TAG_FAILED doivent être différents');
        }

        return new self(
            mailpitUrl: $mailpit,
            mailpitAuth: $auth,
            apiUrl: rtrim($api, '/'),
            apiToken: $token,
            timeout: self::int('RELAY_TIMEOUT', 60),
            keepUnread: self::bool('RELAY_KEEP_UNREAD', true),
            pollInterval: self::int('RELAY_POLL_INTERVAL', 30),
            batchSize: min(500, self::int('RELAY_BATCH_SIZE', 50)),
            tagDone: $tagDone,
            tagFailed: $tagFailed,
            query: self::env('RELAY_QUERY') ?? '',
            listen: self::env('RELAY_LISTEN') ?? '0.0.0.0:8080',
            label: self::env('RELAY_LABEL'),
            heartbeatInterval: self::int('RELAY_HEARTBEAT_INTERVAL', self::HEARTBEAT_INTERVAL, 0),
        );
    }

    /** Recherche Mailpit des mails pas encore relayés (ni refusés). */
    public function pendingQuery(): string
    {
        return trim(sprintf('!tag:%s !tag:%s %s', $this->tagDone, $this->tagFailed, $this->query));
    }

    /** Même configuration, avec un autre délai maximal par requête (ex. pings de santé). */
    public function withTimeout(int $timeout): self
    {
        return new self(...[...get_object_vars($this), 'timeout' => $timeout]);
    }

    /** Valeur sans les identifiants, pour les logs. */
    public function describe(): string
    {
        return sprintf(
            'Mailpit=%s (auth %s), API=%s (jeton %s…), recherche « %s » toutes les %d s, %s',
            $this->mailpitUrl,
            $this->mailpitAuth === null ? 'non' : 'oui',
            $this->apiUrl,
            substr($this->apiToken, 0, 4),
            $this->pendingQuery(),
            $this->pollInterval,
            $this->heartbeatInterval === 0 ? 'ping de santé désactivé' : sprintf('ping de santé toutes les %d s', $this->heartbeatInterval),
        );
    }

    /** Hôte local, seul accepté en http:// pour RELAY_API_URL : localhost, 127.0.0.1 ou ::1. */
    private static function isLocalHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? null : trim($value);
    }

    private static function tag(string $name, string $default): string
    {
        $value = self::env($name) ?? $default;
        if (!preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $value)) {
            throw new InvalidArgumentException(sprintf('%s : lettres, chiffres, « - », « _ » et « . » uniquement', $name));
        }

        return $value;
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

    /** @param int $min 1, ou 0 quand 0 a un sens (ex. RELAY_HEARTBEAT_INTERVAL=0 : désactivé) */
    private static function int(string $name, int $default, int $min = 1): int
    {
        $value = self::env($name);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < $min) {
            throw new InvalidArgumentException(sprintf($min === 0 ? '%s doit être un entier positif ou nul' : '%s doit être un entier positif', $name));
        }

        return (int) $value;
    }
}
