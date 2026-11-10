<?php

declare(strict_types=1);

namespace App\Auth;

use App\Persistence\AdminRepository;
use App\Persistence\LoginAttemptRepository;
use DateTimeImmutable;

/**
 * Connexion au Dashboard, limitée sur WINDOW secondes à MAX_ATTEMPTS échecs par identifiant
 * depuis une même adresse, et à MAX_ATTEMPTS_PER_IP échecs par adresse, tous identifiants confondus.
 *
 * Le blocage d'un identifiant ne vaut que pour l'adresse qui a échoué : depuis une autre adresse,
 * personne ne peut garder un compte bloqué. Le plafond par adresse limite celui qui essaie
 * beaucoup d'identifiants. Une IPv6 est comptée par préfixe /64 (un attaquant dispose en
 * général de tout son /64) ; une IPv4, à l'adresse exacte.
 *
 * La tentative est enregistrée AVANT de vérifier le mot de passe : des requêtes envoyées en
 * même temps ne peuvent donc pas dépasser la limite. Une tentative refusée parce qu'une limite
 * est atteinte n'est pas comptée (elle ne prolonge pas le blocage), et le mot de passe n'est
 * alors même pas vérifié. Une connexion réussie remet à zéro le compteur de cet identifiant
 * depuis cette adresse.
 *
 * Le blocage vaut aussi pour un identifiant qui n'existe pas : la réponse ne permet pas de
 * savoir si un compte existe. Seule exception, « no_admin » : la table mailpit_admins est vide,
 * il n'y a donc aucun compte à protéger, et la page de connexion peut dire d'en créer un.
 * La cause exacte d'un échec (identifiant inconnu, mot de passe incorrect, mot de passe
 * enregistré qui n'est pas un haché password_hash()) n'est écrite que dans le log.
 */
final readonly class LoginThrottle
{
    /** Échecs par identifiant depuis une même adresse. */
    public const int MAX_ATTEMPTS = 3;
    /** Échecs par adresse, tous identifiants confondus. */
    public const int MAX_ATTEMPTS_PER_IP = 10;
    public const int WINDOW = 30 * 60;

    /** Haché quelconque : vérifié quand l'identifiant n'existe pas, pour un temps de réponse identique. */
    private const string DUMMY_HASH = '$2y$12$AIQ8IZrSAt1kAkAo.3bA0Oh7exb4adJ5a3r7pnt616AuaTGW9DERG';

    public function __construct(
        private AdminRepository $admins,
        private LoginAttemptRepository $attempts,
    ) {
    }

    /**
     * « limit » dit quelle limite est atteinte (blocked) ou la plus proche (invalid) :
     * « username » pour cet identifiant depuis cette adresse, « ip » pour l'adresse.
     * « no_admin » (invalid) : aucun administrateur n'existe encore (table mailpit_admins vide).
     *
     * @return array{status: 'ok', admin: array{id: int, username: string}}
     *       | array{status: 'invalid', remaining: int, limit: 'username'|'ip', no_admin: bool}
     *       | array{status: 'blocked', retry_after: int, limit: 'username'|'ip'}
     */
    public function attempt(string $username, string $password, string $ip): array
    {
        $key = mb_strtolower(trim($username));
        $ipKey = self::ipKey($ip);
        // Pour le journal : l'adresse exacte, suivie de l'adresse comptée si elle diffère (IPv6).
        $from = $ipKey === $ip ? $ip : sprintf('%s (%s)', $ip, $ipKey);
        $this->attempts->purge(self::WINDOW * 2);

        $attemptId = $this->attempts->record($key, $ip, $ipKey);
        $recent = $this->attempts->recent($key, $ipKey, self::WINDOW);
        $pairBlocked = $recent['pair']['count'] > self::MAX_ATTEMPTS;
        $ipBlocked = $recent['ip']['count'] > self::MAX_ATTEMPTS_PER_IP;
        if ($pairBlocked || $ipBlocked) {
            $this->attempts->delete($attemptId);
            // Les deux limites peuvent être atteintes : il faut attendre la plus lointaine.
            $retryAfter = max(
                $pairBlocked ? self::retryAfter($recent['pair']['oldest']) : 0,
                $ipBlocked ? self::retryAfter($recent['ip']['oldest']) : 0,
            );
            $limit = $pairBlocked ? 'username' : 'ip';
            error_log($limit === 'username'
                ? sprintf('[signin] identifiant « %s » bloqué depuis %s (trop de tentatives)', $key, $from)
                : sprintf('[signin] adresse %s bloquée (trop de tentatives, identifiant « %s »)', $from, $key));

            return ['status' => 'blocked', 'retry_after' => $retryAfter, 'limit' => $limit];
        }

        $admin = $key === '' ? null : $this->admins->findByUsername($key);
        $valid = password_verify($password, $admin['password'] ?? self::DUMMY_HASH) && $admin !== null;

        if (!$valid) {
            $pairRemaining = self::MAX_ATTEMPTS - $recent['pair']['count'];
            $ipRemaining = self::MAX_ATTEMPTS_PER_IP - $recent['ip']['count'];
            // Requête faite à chaque échec, compte trouvé ou non : même temps de réponse dans les deux cas.
            $noAdmin = !$this->admins->hasAny();
            if ($noAdmin) {
                $reason = 'aucun administrateur dans mailpit_admins, créez-en un (php bin/create-admin.php)';
            } elseif ($admin === null) {
                $reason = 'identifiant inconnu';
            } elseif (password_get_info($admin['password'])['algo'] === null) {
                // Mot de passe saisi en clair dans phpMyAdmin, haché tronqué ou coupé par un retour à la ligne…
                $reason = 'mot de passe incorrect, et celui enregistré n\'est pas un haché password_hash() : recréez-le (php bin/create-admin.php)';
            } else {
                $reason = 'mot de passe incorrect';
            }
            error_log(sprintf(
                '[signin] échec pour « %s » depuis %s (%d/%d pour cet identifiant, %d/%d pour l\'adresse) : %s',
                $key,
                $from,
                $recent['pair']['count'],
                self::MAX_ATTEMPTS,
                $recent['ip']['count'],
                self::MAX_ATTEMPTS_PER_IP,
                $reason,
            ));

            return $ipRemaining < $pairRemaining
                ? ['status' => 'invalid', 'remaining' => $ipRemaining, 'limit' => 'ip', 'no_admin' => $noAdmin]
                : ['status' => 'invalid', 'remaining' => $pairRemaining, 'limit' => 'username', 'no_admin' => $noAdmin];
        }

        $this->attempts->clear($key, $ipKey);
        if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
            $this->admins->updatePassword($admin['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        return ['status' => 'ok', 'admin' => ['id' => $admin['id'], 'username' => $admin['username']]];
    }

    /**
     * Adresse comptée : l'IPv4 exacte, ou le préfixe /64 d'une IPv6 (ex. « 2001:db8:1:2::/64 »).
     * Une IPv4 « mappée » en IPv6 (::ffff:1.2.3.4) est ramenée à l'IPv4. Une valeur qui n'est
     * pas une IP (REMOTE_ADDR vide…) est gardée telle quelle.
     */
    public static function ipKey(string $ip): string
    {
        $bytes = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($bytes === false) {
            return mb_substr($ip, 0, 45);
        }
        if (strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10) . "\xFF\xFF")) {
            $bytes = substr($bytes, 12);
        }
        if (strlen($bytes) === 4) {
            return (string) inet_ntop($bytes);
        }

        return inet_ntop(substr($bytes, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Secondes avant que la plus ancienne tentative comptée sorte de la fenêtre. */
    private static function retryAfter(?DateTimeImmutable $oldest): int
    {
        return $oldest === null ? self::WINDOW : max(1, $oldest->getTimestamp() + self::WINDOW - time());
    }
}
