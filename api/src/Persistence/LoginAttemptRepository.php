<?php

declare(strict_types=1);

namespace App\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Accès à la table mailpit_login_attempts : tentatives de connexion au Dashboard, par identifiant
 * et par adresse (ip_key : IPv4 exacte, ou préfixe /64 d'une IPv6, voir LoginThrottle::ipKey()).
 */
final readonly class LoginAttemptRepository
{
    public function __construct(private Database $database)
    {
    }

    /** Enregistre une tentative et renvoie son ID. */
    public function record(string $username, string $ip, string $ipKey): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO mailpit_login_attempts (username, ip, ip_key) VALUES (?, ?, ?)')
            ->execute([$username, mb_substr($ip, 0, 45), mb_substr($ipKey, 0, 45)]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Tentatives enregistrées depuis $seconds secondes depuis cette adresse : pour cet identifiant
     * (« pair ») et tous identifiants confondus (« ip »). Une seule requête, sur l'index de l'adresse.
     *
     * @return array{pair: array{count: int, oldest: ?DateTimeImmutable}, ip: array{count: int, oldest: ?DateTimeImmutable}}
     */
    public function recent(string $username, string $ipKey, int $seconds): array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT COUNT(*) AS total,
                   MIN(attempted_at) AS oldest,
                   COALESCE(SUM(username = :username), 0) AS pair_total,
                   MIN(CASE WHEN username = :username2 THEN attempted_at END) AS pair_oldest
            FROM mailpit_login_attempts
            WHERE ip_key = :ip_key AND attempted_at > NOW(6) - INTERVAL :seconds SECOND
            SQL);
        $statement->bindValue('username', $username);
        $statement->bindValue('username2', $username);
        $statement->bindValue('ip_key', $ipKey);
        $statement->bindValue('seconds', $seconds, \PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return [
            'pair' => ['count' => (int) ($row['pair_total'] ?? 0), 'oldest' => self::date($row['pair_oldest'] ?? null)],
            'ip'   => ['count' => (int) ($row['total'] ?? 0), 'oldest' => self::date($row['oldest'] ?? null)],
        ];
    }

    public function delete(int $id): void
    {
        $this->database->pdo()->prepare('DELETE FROM mailpit_login_attempts WHERE id = ?')->execute([$id]);
    }

    /** Efface les tentatives de cet identifiant depuis cette adresse (après une connexion réussie). */
    public function clear(string $username, string $ipKey): void
    {
        $this->database->pdo()->prepare('DELETE FROM mailpit_login_attempts WHERE username = ? AND ip_key = ?')->execute([$username, $ipKey]);
    }

    /** Efface les tentatives plus vieilles que $seconds secondes. */
    public function purge(int $seconds): void
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM mailpit_login_attempts WHERE attempted_at < NOW(6) - INTERVAL ? SECOND');
        $statement->bindValue(1, $seconds, \PDO::PARAM_INT);
        $statement->execute();
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
