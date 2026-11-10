<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\LoginThrottle;
use App\Persistence\AdminRepository;
use App\Persistence\LoginAttemptRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\TempDir;

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

    public function testThreeFailuresThenBlockedForThisAddress(): void
    {
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));
        self::assertSame(['status' => 'invalid', 'remaining' => 1, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));
        self::assertSame(['status' => 'invalid', 'remaining' => 0, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));

        // Même le bon mot de passe est refusé pendant le blocage.
        $blocked = $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10');
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('username', $blocked['limit']);
        self::assertGreaterThan(LoginThrottle::WINDOW - 60, $blocked['retry_after']);
        self::assertLessThanOrEqual(LoginThrottle::WINDOW, $blocked['retry_after']);

        // Une tentative bloquée n'est pas comptée : elle ne prolonge pas le blocage.
        self::assertSame(LoginThrottle::MAX_ATTEMPTS, $this->attempts());
    }

    public function testOtherAddressIsNotBlockedForTheSameUsername(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->throttle->attempt('admin', 'faux', '203.0.113.10');
        }
        self::assertSame('blocked', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);

        // Les échecs d'une autre adresse ne bloquent pas l'administrateur.
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('Admin', 'faux', '198.51.100.1'));
        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '198.51.100.1')['status']);

        // Une IPv4 compte à l'adresse exacte : sa voisine n'est pas bloquée non plus.
        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.11')['status']);

        // La connexion réussie ailleurs ne débloque pas l'adresse qui a échoué.
        self::assertSame('blocked', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);
    }

    public function testAddressIsCappedAcrossUsernames(): void
    {
        for ($i = 1; $i <= LoginThrottle::MAX_ATTEMPTS_PER_IP; $i++) {
            $result = $this->throttle->attempt('inconnu' . $i, 'faux', '203.0.113.10');
            // Un seul échec par identifiant : c'est le plafond de l'adresse qui se rapproche.
            $expected = LoginThrottle::MAX_ATTEMPTS_PER_IP - $i < LoginThrottle::MAX_ATTEMPTS - 1
                ? ['status' => 'invalid', 'remaining' => LoginThrottle::MAX_ATTEMPTS_PER_IP - $i, 'limit' => 'ip', 'no_admin' => false]
                : ['status' => 'invalid', 'remaining' => LoginThrottle::MAX_ATTEMPTS - 1, 'limit' => 'username', 'no_admin' => false];
            self::assertSame($expected, $result, 'tentative ' . $i);
        }

        // L'adresse est bloquée pour tous les identifiants, même avec le bon mot de passe.
        $blocked = $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10');
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('ip', $blocked['limit']);
        self::assertGreaterThan(LoginThrottle::WINDOW - 60, $blocked['retry_after']);
        self::assertSame(LoginThrottle::MAX_ATTEMPTS_PER_IP, $this->attempts());

        // Retry-After : jusqu'à ce que le plus ancien échec de l'adresse sorte de la fenêtre.
        $this->pdo->exec('UPDATE mailpit_login_attempts SET attempted_at = NOW(6) - INTERVAL 20 MINUTE ORDER BY id LIMIT 1');
        $blocked = $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10');
        self::assertSame('blocked', $blocked['status']);
        self::assertGreaterThan(LoginThrottle::WINDOW - 20 * 60 - 60, $blocked['retry_after']);
        self::assertLessThanOrEqual(LoginThrottle::WINDOW - 20 * 60, $blocked['retry_after']);

        // Les autres adresses ne sont pas touchées.
        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '198.51.100.1')['status']);
    }

    public function testBothLimitsReachedWaitsForTheLongest(): void
    {
        // 7 échecs anciens sur d'autres identifiants, puis 3 récents pour admin : les deux limites
        // sont atteintes ; l'adresse se libère dans 5 minutes, admin depuis elle dans 29.
        $insert = $this->pdo->prepare("INSERT INTO mailpit_login_attempts (username, ip, ip_key, attempted_at) VALUES (?, '203.0.113.10', '203.0.113.10', NOW(6) - INTERVAL ? MINUTE)");
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS_PER_IP - LoginThrottle::MAX_ATTEMPTS; $i++) {
            $insert->execute(['inconnu' . $i, 25]);
        }
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $insert->execute(['admin', 1]);
        }

        $blocked = $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10');
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('username', $blocked['limit']);
        self::assertGreaterThan(LoginThrottle::WINDOW - 2 * 60, $blocked['retry_after']);
        self::assertLessThanOrEqual(LoginThrottle::WINDOW - 60, $blocked['retry_after']);

        // Un autre identifiant depuis cette adresse ne bute que sur le plafond de l'adresse.
        $blocked = $this->throttle->attempt('autre', 'faux', '203.0.113.10');
        self::assertSame('ip', $blocked['limit']);
        self::assertLessThanOrEqual(5 * 60, $blocked['retry_after']);
        self::assertSame(LoginThrottle::MAX_ATTEMPTS_PER_IP, $this->attempts());
    }

    public function testIpv6IsCountedPerSlash64(): void
    {
        // Trois adresses différentes du même /64 : un seul compteur.
        $this->throttle->attempt('admin', 'faux', '2001:db8:1:2::1');
        $this->throttle->attempt('admin', 'faux', '2001:DB8:1:2::abcd');
        self::assertSame(['status' => 'invalid', 'remaining' => 0, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('admin', 'faux', '2001:db8:1:2:ffff:ffff:ffff:ffff'));

        self::assertSame('blocked', $this->throttle->attempt('admin', self::PASSWORD, '2001:db8:1:2:1234::9')['status']);
        // Le /64 voisin n'est pas bloqué.
        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '2001:db8:1:3::1')['status']);

        // L'adresse exacte est gardée, la clé comptée est le préfixe.
        self::assertSame(['2001:db8:1:2::/64'], $this->pdo->query('SELECT DISTINCT ip_key FROM mailpit_login_attempts')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('2001:db8:1:2::1', $this->scalar('SELECT ip FROM mailpit_login_attempts ORDER BY id LIMIT 1'));
    }

    public function testSuccessResetsOnlyThisUsernameFromThisAddress(): void
    {
        $this->throttle->attempt('admin', 'faux', '203.0.113.10');
        $this->throttle->attempt('admin', 'faux', '203.0.113.10');
        $this->throttle->attempt('admin', 'faux', '198.51.100.1');
        $this->throttle->attempt('autre', 'faux', '203.0.113.10');

        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);

        // Compteur de admin depuis 203.0.113.10 remis à zéro ; les autres échecs restent.
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('admin', 'faux', '203.0.113.10'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM mailpit_login_attempts WHERE username = 'admin' AND ip_key = '198.51.100.1'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM mailpit_login_attempts WHERE username = 'autre'"));
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
        $this->pdo->exec("INSERT INTO mailpit_login_attempts (username, ip, ip_key, attempted_at) VALUES ('autre', '192.0.2.1', '192.0.2.1', NOW(6) - INTERVAL 2 HOUR)");

        $this->throttle->attempt('admin', 'faux', '203.0.113.10');

        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM mailpit_login_attempts WHERE username = 'autre'"));
    }

    public function testUnknownUserIsThrottledToo(): void
    {
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('inconnu', self::PASSWORD, '203.0.113.10'));
        $this->throttle->attempt('inconnu', 'x', '203.0.113.10');
        $this->throttle->attempt('inconnu', 'x', '203.0.113.10');

        self::assertSame('blocked', $this->throttle->attempt('inconnu', 'x', '203.0.113.10')['status']);
        // Le blocage d'un identifiant ne touche pas les autres.
        self::assertSame('ok', $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10')['status']);
    }

    public function testNoAdminAtAllIsReported(): void
    {
        $this->pdo->exec('DELETE FROM mailpit_admins');
        $offset = self::logSize();

        // Table vide : aucun identifiant ne peut fonctionner, l'échec le dit (et reste compté).
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => true], $this->throttle->attempt('admin', self::PASSWORD, '203.0.113.10'));
        self::assertSame(1, $this->attempts());
        self::assertStringContainsString(
            '[signin] échec pour « admin » depuis 203.0.113.10 (1/3 pour cet identifiant, 1/10 pour l\'adresse) : aucun administrateur dans mailpit_admins, créez-en un (php bin/create-admin.php)',
            self::logSince($offset),
        );
    }

    public function testFailureReasonIsLoggedButNotReturned(): void
    {
        $offset = self::logSize();
        // Un compte existe : un identifiant inconnu ne se distingue pas d'un mauvais mot de passe.
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('inconnu', self::PASSWORD, '203.0.113.10'));
        self::assertSame(['status' => 'invalid', 'remaining' => 2, 'limit' => 'username', 'no_admin' => false], $this->throttle->attempt('admin', 'faux', '198.51.100.1'));

        // Mot de passe saisi en clair dans phpMyAdmin au lieu du haché de bin/create-admin.php.
        $this->pdo->prepare('UPDATE mailpit_admins SET password = ? WHERE id = ?')->execute([self::PASSWORD, $this->adminId]);
        self::assertSame('invalid', $this->throttle->attempt('admin', self::PASSWORD, '192.0.2.1')['status']);

        $log = self::logSince($offset);
        self::assertStringContainsString('« inconnu » depuis 203.0.113.10 (1/3 pour cet identifiant, 1/10 pour l\'adresse) : identifiant inconnu', $log);
        self::assertStringContainsString('« admin » depuis 198.51.100.1 (1/3 pour cet identifiant, 1/10 pour l\'adresse) : mot de passe incorrect' . "\n", $log);
        self::assertStringContainsString('« admin » depuis 192.0.2.1 (1/3 pour cet identifiant, 1/10 pour l\'adresse) : mot de passe incorrect, et celui enregistré n\'est pas un haché password_hash()', $log);
    }

    private static function logSize(): int
    {
        clearstatcache();
        $log = TempDir::logFile();

        return is_file($log) ? (int) filesize($log) : 0;
    }

    /** Lignes écrites dans le log (error_log) depuis $offset. */
    private static function logSince(int $offset): string
    {
        clearstatcache();

        return (string) file_get_contents(TempDir::logFile(), offset: $offset);
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
