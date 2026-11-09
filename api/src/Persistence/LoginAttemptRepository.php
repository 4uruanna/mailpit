<?php

declare(strict_types=1);

namespace App\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Accès à la table mailpit_login_attempts : tentatives de connexion au Dashboard, par identifiant.
 */
final readonly class LoginAttemptRepository
{
    public function __construct(private Database $database)
    {
    }

    /** Enregistre une tentative et renvoie son ID. */
    public function record(string $username, string $ip): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO mailpit_login_attempts (username, ip) VALUES (?, ?)')->execute([$username, mb_substr($ip, 0, 45)]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Tentatives enregistrées pour cet identifiant depuis $seconds secondes.
     *
     * @return array{count: int, oldest: ?DateTimeImmutable}
     */
    public function recent(string $username, int $seconds): array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT COUNT(*) AS total, MIN(attempted_at) AS oldest
            FROM mailpit_login_attempts
            WHERE username = ? AND attempted_at > NOW(6) - INTERVAL ? SECOND
            SQL);
        $statement->bindValue(1, $username);
        $statement->bindValue(2, $seconds, \PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return [
            'count'  => (int) ($row['total'] ?? 0),
            'oldest' => ($row['oldest'] ?? null) === null ? null : new DateTimeImmutable((string) $row['oldest'], new DateTimeZone('UTC')),
        ];
    }

    public function delete(int $id): void
    {
        $this->database->pdo()->prepare('DELETE FROM mailpit_login_attempts WHERE id = ?')->execute([$id]);
    }

    /** Efface les tentatives de cet identifiant (après une connexion réussie). */
    public function clear(string $username): void
    {
        $this->database->pdo()->prepare('DELETE FROM mailpit_login_attempts WHERE username = ?')->execute([$username]);
    }

    /** Efface les tentatives plus vieilles que $seconds secondes. */
    public function purge(int $seconds): void
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM mailpit_login_attempts WHERE attempted_at < NOW(6) - INTERVAL ? SECOND');
        $statement->bindValue(1, $seconds, \PDO::PARAM_INT);
        $statement->execute();
    }
}
