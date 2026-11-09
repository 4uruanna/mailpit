<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Accès à la table mailpit_admins (administrateurs du Dashboard).
 */
final readonly class AdminRepository
{
    public function __construct(private Database $database)
    {
    }

    /** @return array{id: int, username: string, password: string}|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, username, password FROM mailpit_admins WHERE username = ?');
        $statement->execute([$username]);
        $row = $statement->fetch();

        return $row === false ? null : self::row($row);
    }

    /** @return array{id: int, username: string, password: string}|null */
    public function find(int $id): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, username, password FROM mailpit_admins WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : self::row($row);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->database->pdo()->prepare('UPDATE mailpit_admins SET password = ? WHERE id = ?')->execute([$hash, $id]);
    }

    /** @return array{id: int, username: string, password: string} */
    private static function row(array $row): array
    {
        return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'password' => (string) $row['password']];
    }
}
