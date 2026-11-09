<?php

declare(strict_types=1);

namespace App\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * Accès aux tables mailpit_tokens et mailpit_token_addresses (jetons des développeurs).
 *
 * Un jeton ressemble à « mpt_3f9c…» (52 caractères). Seule son empreinte SHA-256 est en base :
 * il n'est visible qu'au moment de sa création.
 */
final readonly class TokenRepository
{
    public const string PREFIX = 'mpt_';

    public function __construct(private Database $database)
    {
    }

    /**
     * Crée un jeton.
     *
     * @return array{id: int, token: string} le jeton en clair, à afficher une seule fois
     */
    public function create(string $name): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(24));

        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO mailpit_tokens (name, token_hash, token_hint) VALUES (?, ?, ?)')
            ->execute([mb_substr($name, 0, 255), hash('sha256', $token), substr($token, 0, 12)]);

        return ['id' => (int) $pdo->lastInsertId(), 'token' => $token];
    }

    /**
     * Jeton correspondant à la valeur présentée, ou null. Met à jour sa date de dernière utilisation
     * (au plus une fois par minute, pour ne pas écrire en base à chaque requête).
     *
     * @return array{id: int, name: string}|null
     */
    public function authenticate(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) > 128) {
            return null;
        }

        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('SELECT id, name FROM mailpit_tokens WHERE token_hash = ?');
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $pdo->prepare(<<<'SQL'
            UPDATE mailpit_tokens SET last_used_at = NOW(6)
            WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW(6) - INTERVAL 1 MINUTE)
            SQL)->execute([(int) $row['id']]);

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * Tous les jetons, du plus récent au plus ancien, avec leur nombre d'adresses.
     *
     * @return list<array{id: int, name: string, hint: string, created_at: DateTimeImmutable, last_used_at: ?DateTimeImmutable, addresses: int}>
     */
    public function all(): array
    {
        $rows = $this->database->pdo()->query(<<<'SQL'
            SELECT t.id, t.name, t.token_hint, t.created_at, t.last_used_at, COUNT(a.address) AS addresses
            FROM mailpit_tokens t
            LEFT JOIN mailpit_token_addresses a ON a.token_id = t.id
            GROUP BY t.id
            ORDER BY t.id DESC
            SQL)->fetchAll();

        return array_map(static fn (array $row): array => self::row($row) + ['addresses' => (int) $row['addresses']], $rows);
    }

    /** @return array{id: int, name: string, hint: string, created_at: DateTimeImmutable, last_used_at: ?DateTimeImmutable}|null */
    public function find(int $id): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, name, token_hint, created_at, last_used_at FROM mailpit_tokens WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : self::row($row);
    }

    /** Supprime le jeton et ses adresses. false s'il n'existait pas. */
    public function delete(int $id): bool
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM mailpit_tokens WHERE id = ?');
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /** @return list<string> adresses associées au jeton, par ordre alphabétique */
    public function addresses(int $tokenId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT address FROM mailpit_token_addresses WHERE token_id = ? ORDER BY address');
        $statement->execute([$tokenId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Associe une adresse (déjà normalisée) au jeton. false si elle l'était déjà. */
    public function addAddress(int $tokenId, string $address): bool
    {
        try {
            $this->database->pdo()->prepare('INSERT INTO mailpit_token_addresses (token_id, address) VALUES (?, ?)')
                ->execute([$tokenId, $address]);
        } catch (PDOException $e) {
            // 23000 : doublon (clé primaire token_id + address).
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** Retire une adresse du jeton. false si elle n'y était pas. */
    public function removeAddress(int $tokenId, string $address): bool
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM mailpit_token_addresses WHERE token_id = ? AND address = ?');
        $statement->execute([$tokenId, $address]);

        return $statement->rowCount() > 0;
    }

    /** @return array{id: int, name: string, hint: string, created_at: DateTimeImmutable, last_used_at: ?DateTimeImmutable} */
    private static function row(array $row): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'id'           => (int) $row['id'],
            'name'         => (string) $row['name'],
            'hint'         => (string) $row['token_hint'],
            'created_at'   => new DateTimeImmutable((string) $row['created_at'], $utc),
            'last_used_at' => $row['last_used_at'] === null ? null : new DateTimeImmutable((string) $row['last_used_at'], $utc),
        ];
    }
}
