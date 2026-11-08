<?php

declare(strict_types=1);

namespace App\Persistence;

use PDO;
use Pdo\Mysql;

/**
 * Connexion PDO MySQL, ouverte uniquement au premier besoin
 * (une requête refusée par le filtre IP ne touche pas la base).
 */
final class Database
{
    private ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->config['host'], $this->config['port'], $this->config['name']),
            $this->config['user'],
            $this->config['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Toutes les dates sont stockées en UTC.
                Mysql::ATTR_INIT_COMMAND     => "SET time_zone = '+00:00'",
            ],
        );
    }
}
