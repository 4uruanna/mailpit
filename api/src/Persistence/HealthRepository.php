<?php

declare(strict_types=1);

namespace App\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Accès à la table mailpit_health_pings : pings de santé du relais (POST /v1/health), lus par la
 * page « Statut » du Dashboard.
 *
 * Toutes les dates viennent de l'horloge de PHP (en UTC), jamais de NOW() de MySQL : la page
 * Statut compare l'heure des pings à l'heure de PHP, et la base peut être sur un autre serveur.
 */
final readonly class HealthRepository
{
    /** Durée de conservation des pings : 48 heures (la page Statut en montre 24). */
    public const int RETENTION = 48 * 60 * 60;

    public function __construct(private Database $database)
    {
    }

    /** Enregistre un ping reçu à $receivedAt. */
    public function record(
        DateTimeImmutable $receivedAt,
        ?int $latencyMs,
        ?int $pending,
        ?int $failed,
        string $sourceIp,
        ?string $label,
    ): void {
        $this->database->pdo()
            ->prepare('INSERT INTO mailpit_health_pings (received_at, latency_ms, pending, failed, source_ip, label) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                self::sqlDate($receivedAt),
                $latencyMs,
                $pending,
                $failed,
                mb_substr($sourceIp, 0, 45),
                $label === null ? null : mb_substr($label, 0, 255),
            ]);
    }

    /** Supprime les pings reçus avant $before. Renvoie le nombre de lignes supprimées. */
    public function purgeBefore(DateTimeImmutable $before): int
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM mailpit_health_pings WHERE received_at < ?');
        $statement->execute([self::sqlDate($before)]);

        return $statement->rowCount();
    }

    /**
     * Pings reçus depuis $from (inclus), du plus ancien au plus récent.
     *
     * @return list<array{received_at: DateTimeImmutable, latency_ms: ?int, pending: ?int, failed: ?int, label: ?string}>
     */
    public function since(DateTimeImmutable $from): array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT received_at, latency_ms, pending, failed, label
            FROM mailpit_health_pings
            WHERE received_at >= ?
            ORDER BY received_at, id
            SQL);
        $statement->execute([self::sqlDate($from)]);

        return array_map(static fn (array $row): array => [
            'received_at' => self::date($row['received_at']),
            'latency_ms'  => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
            'pending'     => $row['pending'] === null ? null : (int) $row['pending'],
            'failed'      => $row['failed'] === null ? null : (int) $row['failed'],
            'label'       => $row['label'] === null ? null : (string) $row['label'],
        ], $statement->fetchAll());
    }

    /** Dernier ping reçu avant $before, ou null : l'historique remonte-t-il plus loin ? */
    public function lastBefore(DateTimeImmutable $before): ?DateTimeImmutable
    {
        $statement = $this->database->pdo()->prepare('SELECT MAX(received_at) FROM mailpit_health_pings WHERE received_at < ?');
        $statement->execute([self::sqlDate($before)]);
        $value = $statement->fetchColumn();

        return $value === null || $value === false ? null : self::date($value);
    }

    private static function sqlDate(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
