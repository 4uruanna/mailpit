<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;
use PDOException;
use Pdo\Mysql;
use RuntimeException;

/**
 * Base MySQL des tests d'intégration et HTTP.
 *
 * Paramètres lus dans les variables d'environnement (valeurs par défaut dans phpunit.xml.dist) :
 *   TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASSWORD
 *
 * Au premier test qui en a besoin, TOUTES les tables de la base sont supprimées puis
 * sql/schema.sql est chargé : on part toujours d'une base propre, au schéma à jour.
 * Avant chaque test, les tables sont vidées.
 *
 * ⚠️ Par sécurité, le nom de la base doit contenir « test » : jamais une base réelle.
 * Si la base est injoignable, les tests qui en ont besoin sont marqués « skipped ».
 */
final class TestDatabase
{
    private static ?PDO $pdo = null;
    private static ?string $unavailable = null;

    /** @return array{host: string, port: int, name: string, user: string, password: string} */
    public static function config(): array
    {
        return [
            'host'     => self::env('TEST_DB_HOST', '127.0.0.1'),
            'port'     => (int) self::env('TEST_DB_PORT', '3306'),
            'name'     => self::env('TEST_DB_NAME', 'mailpit_test'),
            'user'     => self::env('TEST_DB_USER', 'mailpit'),
            'password' => self::env('TEST_DB_PASSWORD', 'mailpit'),
        ];
    }

    /**
     * Connexion à la base de test, schéma chargé ; null si la base est injoignable (voir unavailable()).
     */
    public static function pdo(): ?PDO
    {
        if (self::$pdo !== null || self::$unavailable !== null) {
            return self::$pdo;
        }

        $config = self::config();
        if (!str_contains(strtolower($config['name']), 'test')) {
            throw new RuntimeException(sprintf(
                'TEST_DB_NAME=%s : le nom de la base de test doit contenir « test » (ses tables sont supprimées)',
                $config['name'],
            ));
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']),
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 3,
                    // Comme App\Persistence\Database : NOW() en UTC, quel que soit le fuseau du serveur MySQL.
                    Mysql::ATTR_INIT_COMMAND     => "SET time_zone = '+00:00'",
                ],
            );
        } catch (PDOException $e) {
            self::$unavailable = sprintf(
                'base de test MySQL injoignable (%s@%s:%d/%s) : %s',
                $config['user'],
                $config['host'],
                $config['port'],
                $config['name'],
                $e->getMessage(),
            );

            return null;
        }

        self::loadSchema($pdo);

        return self::$pdo = $pdo;
    }

    /** Raison pour laquelle la base n'est pas disponible, ou null. */
    public static function unavailable(): ?string
    {
        return self::$unavailable;
    }

    /** Vide toutes les tables (et remet les compteurs AUTO_INCREMENT à 1). */
    public static function truncate(): void
    {
        $pdo = self::pdo() ?? throw new RuntimeException((string) self::$unavailable);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tables($pdo) as $table) {
            $pdo->exec(sprintf('TRUNCATE TABLE `%s`', $table));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Supprime toutes les tables de la base, puis exécute sql/schema.sql. */
    private static function loadSchema(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tables($pdo) as $table) {
            $pdo->exec(sprintf('DROP TABLE `%s`', $table));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/schema.sql');
        // Une requête par « ; » de fin de ligne (le schéma ne contient pas de « ; » ailleurs).
        foreach (preg_split('/;\s*$/m', $schema) ?: [] as $statement) {
            $sql = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));
            if ($sql !== '') {
                $pdo->exec($sql);
            }
        }
    }

    /** @return list<string> */
    private static function tables(PDO $pdo): array
    {
        return array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
