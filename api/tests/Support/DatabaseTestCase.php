<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mailpit\Message;
use App\Persistence\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test qui a besoin de la base MySQL de test (voir TestDatabase) : tables vidées avant chaque test,
 * test marqué « skipped » si la base est injoignable.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected Database $database;

    protected function setUp(): void
    {
        $pdo = TestDatabase::pdo();
        if ($pdo === null) {
            self::markTestSkipped((string) TestDatabase::unavailable());
        }

        TestDatabase::truncate();
        $this->pdo = $pdo;
        $this->database = new Database(TestDatabase::config());
    }

    /**
     * Mail tel qu'envoyé par le relais.
     *
     * @param list<string> $to destinataires
     * @param list<array{0: string, 1: int}> $parts [PartID, taille] des pièces jointes annoncées
     * @return array<string, mixed>
     */
    protected static function payload(string $id, array $to = ['alice@test.fr'], array $parts = [], string $created = '2026-11-08T21:15:03Z'): array
    {
        return [
            'ID'          => $id,
            'MessageID'   => $id . '@example.com',
            'From'        => ['Name' => 'Expéditeur', 'Address' => 'sender@example.com'],
            'To'          => array_map(static fn (string $address): array => ['Name' => '', 'Address' => $address], $to),
            'Cc'          => [],
            'Bcc'         => [],
            'ReplyTo'     => [],
            'Subject'     => 'Sujet ' . $id,
            'Created'     => $created,
            'Tags'        => [],
            'Size'        => 1234,
            'Attachments' => count($parts),
            'Snippet'     => 'Début du texte',
            'Text'        => 'Texte de ' . $id,
            'HTML'        => '<p>HTML de ' . $id . '</p>',
            'Headers'     => ['Subject' => ['Sujet ' . $id]],
            'Parts'       => array_map(static fn (array $part): array => [
                'PartID'      => $part[0],
                'Kind'        => 'attachment',
                'FileName'    => 'fichier-' . $part[0] . '.txt',
                'ContentType' => 'text/plain',
                'ContentID'   => '',
                'Size'        => $part[1],
            ], $parts),
        ];
    }

    /** @param array<string, mixed> $payload */
    protected static function message(array $payload): Message
    {
        return Message::fromArray($payload);
    }

    /** Valeur d'une colonne de la première ligne renvoyée. */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
