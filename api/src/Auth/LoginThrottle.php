<?php

declare(strict_types=1);

namespace App\Auth;

use App\Persistence\AdminRepository;
use App\Persistence\LoginAttemptRepository;

/**
 * Connexion au Dashboard, limitée à MAX_ATTEMPTS échecs par identifiant sur WINDOW secondes.
 *
 * La tentative est enregistrée AVANT de vérifier le mot de passe : des requêtes envoyées en
 * même temps ne peuvent donc pas dépasser la limite. Une tentative refusée parce que la limite
 * est atteinte n'est pas comptée (elle ne prolonge pas le blocage), et le mot de passe n'est
 * alors même pas vérifié. Une connexion réussie remet le compteur à zéro.
 *
 * Le blocage vaut aussi pour un identifiant qui n'existe pas : la réponse ne permet pas de
 * savoir si un compte existe.
 */
final readonly class LoginThrottle
{
    public const int MAX_ATTEMPTS = 3;
    public const int WINDOW = 30 * 60;

    /** Haché quelconque : vérifié quand l'identifiant n'existe pas, pour un temps de réponse identique. */
    private const string DUMMY_HASH = '$2y$12$AIQ8IZrSAt1kAkAo.3bA0Oh7exb4adJ5a3r7pnt616AuaTGW9DERG';

    public function __construct(
        private AdminRepository $admins,
        private LoginAttemptRepository $attempts,
    ) {
    }

    /**
     * @return array{status: 'ok', admin: array{id: int, username: string}}
     *       | array{status: 'invalid', remaining: int}
     *       | array{status: 'blocked', retry_after: int}
     */
    public function attempt(string $username, string $password, string $ip): array
    {
        $key = mb_strtolower(trim($username));
        $this->attempts->purge(self::WINDOW * 2);

        $attemptId = $this->attempts->record($key, $ip);
        $recent = $this->attempts->recent($key, self::WINDOW);
        if ($recent['count'] > self::MAX_ATTEMPTS) {
            $this->attempts->delete($attemptId);
            $retryAfter = $recent['oldest'] === null
                ? self::WINDOW
                : max(1, $recent['oldest']->getTimestamp() + self::WINDOW - time());
            error_log(sprintf('[signin] identifiant « %s » bloqué (trop de tentatives) depuis %s', $key, $ip));

            return ['status' => 'blocked', 'retry_after' => $retryAfter];
        }

        $admin = $key === '' ? null : $this->admins->findByUsername($key);
        $valid = password_verify($password, $admin['password'] ?? self::DUMMY_HASH) && $admin !== null;

        if (!$valid) {
            error_log(sprintf('[signin] échec pour « %s » depuis %s (%d/%d)', $key, $ip, $recent['count'], self::MAX_ATTEMPTS));

            return ['status' => 'invalid', 'remaining' => self::MAX_ATTEMPTS - $recent['count']];
        }

        $this->attempts->clear($key);
        if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
            $this->admins->updatePassword($admin['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        return ['status' => 'ok', 'admin' => ['id' => $admin['id'], 'username' => $admin['username']]];
    }
}
