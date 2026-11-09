<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\LoginThrottle;
use App\Persistence\AdminRepository;
use App\Persistence\LoginAttemptRepository;
use App\Tests\Support\DatabaseTestCase;

final class LoginThrottleTest extends DatabaseTestCase
{
    private const string PASSWORD = 'mot-de-passe-solide';

    private LoginThrottle $throttle;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new LoginThrottle(new AdminRepository($this->database), new LoginAttemptRepository($this->database));

        // Coût bcrypt minimal : tests rapides (le haché est mis à niveau à la première connexion).
        $this->pdo->prepare('INSERT INTO mailpit_admins (username, password) VALUES (?, ?)')
            ->execute(['admin', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    private function attempts(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM mailpit_login_attempts');
    }

    public function testSuccessfulLogin(): void
    {
        $result = $this->throttle->attempt(' Admin ', self::PASSWORD, '203.0.113.10');

        self::assertSame(['status' => 'ok', 'admin' => ['id' => $this->adminId, 'username' => 'admin']], $result);
        self::assertSame(0, $this->attempts(), 'compteur remis à zéro');
    }

    public function testThreeFailuresThenBlocked(): void
    {
        self::assertSame(['status' => 'invalid', 'remaining' => 2], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));
        self::assertSame(['status' => 'invalid', 'remaining' => 1], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));
        self::assertSame(['status' => 'invalid', 'remaining' => 0], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));

        // Même le bon mot de passe est refusé pendant le blocage.
        $blocked = $this->throttle->attempt('admin', self::PASSWORD, '198.51.100.1');
        self::assertSame('blocked', $blocked['status']);
        self::assertGreaterThan(LoginThrottle::WINDOW - 60, $blocked['retry_after']);
        self::assertLessThanOrEqual(LoginThrottle::WINDOW, $blocked['retry_after']);

        // Une tentative bloquée n'est pas comptée : elle ne prolonge pas le blocage.
        self::assertSame(LoginThrottle::MAX_ATTEMPTS, $this->attempts());
    }

    public function testBlockingEndsAfterTheWindow(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->throttle->attempt('admin', 'faux', '203.0.113.10');
        }
        self::assertSame('blocked', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);

        // Les échecs datent de plus de 30 minutes.
        $this->pdo->exec('UPDATE mailpit_login_attempts SET attempted_at = NOW(6) - INTERVAL 31 MINUTE');

        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);
    }

    public function testOldAttemptsArePurged(): void
    {
        $this->pdo->exec("INSERT INTO mailpit_login_attempts (username, ip, attempted_at) VALUES ('autre', '192.0.2.1', NOW(6) - INTERVAL 2 HOUR)");

        $this->throttle->attempt('admin', 'faux', '203.0.113.10');

        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM mailpit_login_attempts WHERE username = 'autre'"));
    }

    public function testUnknownUserIsThrottledToo(): void
    {
        self::assertSame(['status' => 'invalid', 'remaining' => 2], $this->throttle->attempt('inconnu', self::PASSWORD, '203.0.113.10'));
        $this->throttle->attempt('inconnu', 'x', '203.0.113.10');
        $this->throttle->attempt('inconnu', 'x', '203.0.113.10');

        self::assertSame('blocked', $this->throttle->attempt('inconnu', 'x', '203.0.113.10')['status']);
        // Le blocage d'un identifiant ne touche pas les autres.
        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);
    }

    public function testOutdatedHashIsUpgraded(): void
    {
        self::assertTrue(password_needs_rehash((string) $this->scalar('SELECT password FROM mailpit_admins'), PASSWORD_DEFAULT));

        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);

        $hash = (string) $this->scalar('SELECT password FROM mailpit_admins WHERE id = ?', [$this->adminId]);
        self::assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
        self::assertTrue(password_verify(self::PASSWORD, $hash));
    }
}
