<?php

declare(strict_types=1);

namespace App\Dashboard;

/**
 * Session PHP du Dashboard (cookie « mailpit_admin », limité au chemin /v1/app).
 *
 * Contient l'administrateur connecté, le jeton anti-CSRF des formulaires et les messages
 * « flash » affichés une seule fois après une redirection. La connexion expire après
 * $lifetime secondes d'inactivité.
 */
final class Session
{
    public const string COOKIE = 'mailpit_admin';

    public function __construct(
        private readonly string $cookiePath,
        private readonly int $lifetime,
    ) {
    }

    public function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start([
            'name'             => self::COOKIE,
            'cookie_path'      => $this->cookiePath,
            'cookie_lifetime'  => 0,
            'cookie_httponly'  => true,
            'cookie_secure'    => $secure,
            'cookie_samesite'  => 'Strict',
            'use_strict_mode'  => true,
            'use_only_cookies' => true,
            'use_trans_sid'    => false,
            'gc_maxlifetime'   => max($this->lifetime, 1440),
            'cache_limiter'    => 'nocache',
        ]);

        $now = time();
        if (isset($_SESSION['admin_id']) && ($now - (int) ($_SESSION['last_activity'] ?? 0)) > $this->lifetime) {
            $_SESSION = [];
            $this->flash('message', ['type' => 'error', 'message' => 'Session expirée : reconnectez-vous.']);
        }
        $_SESSION['last_activity'] = $now;
    }

    public function adminId(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public function username(): ?string
    {
        return isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : null;
    }

    /** Connecte l'administrateur. Nouvel identifiant de session : protège contre la fixation de session. */
    public function login(int $id, string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_username'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function checkCsrf(mixed $given): bool
    {
        return is_string($given) && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $given);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['flash'][$key] = $value;
    }

    public function takeFlash(string $key): mixed
    {
        $value = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);

        return $value;
    }
}
