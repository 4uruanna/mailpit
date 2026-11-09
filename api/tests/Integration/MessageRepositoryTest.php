<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\MessageRepository;
use App\Tests\Support\DatabaseTestCase;

final class MessageRepositoryTest extends DatabaseTestCase
{
    private const int MAX_PART_SIZE = 1000;

    private MessageRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MessageRepository($this->database);
    }

    /** @return array{status: string, complete: bool, missing: list<string>, too_large: list<string>} */
    private function save(array $payload, ?string $label = null): array
    {
        return $this->repository->save(self::message($payload), $label, '203.0.113.10', '{}', self::MAX_PART_SIZE);
    }

    private function pk(string $mailpitId): int
    {
        return (int) $this->scalar('SELECT id FROM mailpit_messages WHERE mailpit_id = ?', [$mailpitId]);
    }

    public function testSaveCreatesMessageWithoutParts(): void
    {
        $result = $this->save(self::payload('m1', ['Alice@Test.fr ', 'bob@test.fr']), 'mailpit-prod');

        self::assertSame(['status' => 'created', 'complete' => true, 'missing' => [], 'too_large' => []], $result);

        $row = $this->pdo->query("SELECT * FROM mailpit_messages WHERE mailpit_id = 'm1'")->fetch();
        self::assertSame('Sujet m1', $row['subject']);
        self::assertSame('Texte de m1', $row['text_body']);
        self::assertSame('mailpit-prod', $row['mailpit_label']);
        self::assertSame('203.0.113.10', $row['source_ip']);
        self::assertSame('2026-11-08 21:15:03.000000', $row['created_at']);
        self::assertNotNull($row['completed_at']);

        // Destinataires normalisés : c'est par eux que les jetons développeurs trouvent leurs mails.
        $recipients = $this->pdo->query('SELECT address FROM mailpit_message_recipients ORDER BY address')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['alice@test.fr', 'bob@test.fr'], $recipients);
    }

    public function testSameMessageTwiceIsDuplicate(): void
    {
        $this->save(self::payload('m1'));
        $result = $this->save(self::payload('m1'));

        self::assertSame('duplicate', $result['status']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_message_recipients'));
    }

    public function testSummaryThenFullMessageIsUpdated(): void
    {
        // Le webhook direct de Mailpit n'envoie qu'un résumé : le relais complète ensuite.
        $summary = array_diff_key(self::payload('m1'), ['Text' => 0, 'HTML' => 0, 'Headers' => 0]);
        self::assertSame('created', $this->save($summary)['status']);
        self::assertNull($this->scalar("SELECT text_body FROM mailpit_messages WHERE mailpit_id = 'm1'"));

        self::assertSame('updated', $this->save(self::payload('m1'))['status']);
        self::assertSame('Texte de m1', $this->scalar("SELECT text_body FROM mailpit_messages WHERE mailpit_id = 'm1'"));

        // Un résumé reçu après le mail complet n'efface rien.
        self::assertSame('duplicate', $this->save($summary)['status']);
        $message = $this->repository->find('m1');
        self::assertSame('Texte de m1', $message['text'] ?? null);
        self::assertSame(['Subject' => ['Sujet m1']], $message['headers'] ?? null);
    }

    public function testPartsPendingAndTooLarge(): void
    {
        $result = $this->save(self::payload('m1', parts: [['2', 10], ['3', self::MAX_PART_SIZE + 1], ['1.2', self::MAX_PART_SIZE]]));

        self::assertSame('created', $result['status']);
        self::assertFalse($result['complete']);
        self::assertSame(['2', '1.2'], $result['missing']);
        self::assertSame(['3'], $result['too_large']);
        self::assertNull($this->scalar("SELECT completed_at FROM mailpit_messages WHERE mailpit_id = 'm1'"));

        self::assertSame(['message_pk' => $this->pk('m1'), 'kind' => 'attachment', 'status' => 'too_large', 'sha256' => null], $this->repository->findPart('m1', '3'));
        self::assertNull($this->repository->findPart('m1', '9'));
        self::assertNull($this->repository->findPart('inconnu', '2'));
    }

    public function testMarkPartStoredCompletesTheMessage(): void
    {
        $this->save(self::payload('m1', parts: [['2', 10], ['3', 20], ['4', self::MAX_PART_SIZE + 1]]));
        $pk = $this->pk('m1');
        $sha = hash('sha256', 'contenu');

        $state = $this->repository->markPartStored($pk, '2', 7, $sha, 'm1/part-2');
        self::assertSame(['complete' => false, 'missing' => ['3'], 'too_large' => ['4']], $state);

        $state = $this->repository->markPartStored($pk, '3', 20, $sha, 'm1/part-3');
        self::assertSame(['complete' => true, 'missing' => [], 'too_large' => ['4']], $state);
        self::assertNotNull($this->scalar('SELECT completed_at FROM mailpit_messages WHERE id = ?', [$pk]));

        $part = $this->repository->findPart('m1', '2');
        self::assertSame('stored', $part['status'] ?? null);
        self::assertSame($sha, $part['sha256'] ?? null);
        self::assertSame(7, (int) $this->scalar("SELECT size FROM mailpit_parts WHERE part_id = '2'"));

        self::assertSame(['complete' => true, 'missing' => [], 'too_large' => ['4']], $this->repository->completion($pk));
    }

    public function testResendKeepsStoredParts(): void
    {
        $this->save(self::payload('m1', parts: [['2', 10]]));
        $this->repository->markPartStored($this->pk('m1'), '2', 7, hash('sha256', 'contenu'), 'm1/part-2');

        // Le relais renvoie le mail (redémarrage…) : la pièce reste enregistrée, avec sa taille réelle.
        $result = $this->save(self::payload('m1', parts: [['2', 10]]));

        self::assertSame(['status' => 'duplicate', 'complete' => true, 'missing' => [], 'too_large' => []], $result);
        self::assertSame(7, (int) $this->scalar("SELECT size FROM mailpit_parts WHERE part_id = '2'"));
    }

    public function testCompletionIsResetWhenANewPartIsAnnounced(): void
    {
        $this->save(self::payload('m1'));
        $result = $this->save(self::payload('m1', parts: [['2', 10]]));

        self::assertFalse($result['complete']);
        self::assertSame(['2'], $result['missing']);
        self::assertNull($this->scalar("SELECT completed_at FROM mailpit_messages WHERE mailpit_id = 'm1'"));
    }

    public function testPruneKeepsTheMostRecent(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->save(self::payload("m$i", parts: [['2', 10]]));
        }

        self::assertSame(['m1', 'm2'], $this->repository->prune(3));
        self::assertSame(['m3', 'm4', 'm5'], $this->pdo->query('SELECT mailpit_id FROM mailpit_messages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        // Pièces et destinataires supprimés avec leur mail (ON DELETE CASCADE).
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_parts'));
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_message_recipients'));

        self::assertSame([], $this->repository->prune(3));
        self::assertSame([], $this->repository->prune(10));
        self::assertSame([], $this->repository->prune(0), '0 = sans limite');
    }

    public function testPruneByBatches(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->save(self::payload("m$i"));
        }

        self::assertSame(['m1', 'm2'], $this->repository->prune(1, batch: 2));
        self::assertSame(['m3', 'm4'], $this->repository->prune(1, batch: 2));
        self::assertSame([], $this->repository->prune(1, batch: 2));
    }

    public function testListWithAddressFilter(): void
    {
        $this->save(self::payload('a1', ['alice@test.fr'], [['2', 10]], '2026-11-08T10:00:00Z'));
        $this->save(self::payload('b1', ['bob@test.fr'], created: '2026-11-08T11:00:00Z'));
        $this->save(self::payload('ab', ['alice@test.fr', 'bob@test.fr'], created: '2026-11-08T12:00:00Z'));
        $this->save(self::payload('a2', ['alice@test.fr'], created: '2026-11-08T09:00:00Z'));

        $all = $this->repository->list(10, 0);
        self::assertSame(4, $all['total']);
        // Du plus récent au plus ancien (date de réception par Mailpit, pas ordre d'arrivée).
        self::assertSame(['ab', 'b1', 'a1', 'a2'], array_column($all['messages'], 'id'));
        self::assertArrayNotHasKey('text', $all['messages'][0]);

        $alice = $this->repository->list(10, 0, ['alice@test.fr']);
        self::assertSame(3, $alice['total']);
        self::assertSame(['ab', 'a1', 'a2'], array_column($alice['messages'], 'id'));

        $page = $this->repository->list(1, 1, ['alice@test.fr'], withBodies: true, oldestFirst: true);
        self::assertSame(3, $page['total']);
        self::assertCount(1, $page['messages']);
        $message = $page['messages'][0];
        self::assertSame('a1', $message['id']);
        self::assertSame('Texte de a1', $message['text']);
        self::assertSame('<p>HTML de a1</p>', $message['html']);
        self::assertSame(['name' => 'Expéditeur', 'address' => 'sender@example.com'], $message['from']);
        self::assertSame([['name' => '', 'address' => 'alice@test.fr']], $message['to']);
        self::assertSame('2026-11-08T10:00:00.000Z', $message['created_at']);
        self::assertFalse($message['complete']);
        self::assertSame('2', $message['parts'][0]['part_id']);
        self::assertSame('pending', $message['parts'][0]['status']);
        self::assertArrayNotHasKey('storage_path', $message['parts'][0]);

        self::assertSame(['total' => 0, 'messages' => []], $this->repository->list(10, 0, []));
        self::assertSame(0, $this->repository->list(10, 0, ['inconnu@test.fr'])['total']);
    }

    public function testRecipientsAndCountByAddress(): void
    {
        $this->save(self::payload('a1', ['alice@test.fr']));
        $this->save(self::payload('ab', ['alice@test.fr', 'bob@test.fr']));

        self::assertSame(['alice@test.fr' => 2, 'bob@test.fr' => 1], $this->repository->recipients());
        self::assertSame(
            ['bob@test.fr' => 1, 'carol@test.fr' => 0],
            $this->repository->countByAddress(['bob@test.fr', 'carol@test.fr']),
        );
        self::assertSame([], $this->repository->countByAddress([]));
    }

    public function testDeleteWithScope(): void
    {
        $this->save(self::payload('m1', ['alice@test.fr'], [['2', 10]]));

        self::assertFalse($this->repository->delete('m1', ['bob@test.fr']), 'hors des adresses autorisées');
        self::assertFalse($this->repository->delete('m1', []), 'aucune adresse autorisée');
        self::assertFalse($this->repository->delete('inconnu'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));

        self::assertTrue($this->repository->delete('m1', ['bob@test.fr', 'alice@test.fr']));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_parts'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_message_recipients'));

        $this->save(self::payload('m2', ['carol@test.fr']));
        self::assertTrue($this->repository->delete('m2'), 'sans condition (Dashboard)');
    }

    public function testDeleteByAddress(): void
    {
        $this->save(self::payload('a1', ['alice@test.fr']));
        $this->save(self::payload('ab', ['alice@test.fr', 'bob@test.fr']));
        $this->save(self::payload('b1', ['bob@test.fr']));

        $deleted = $this->repository->deleteByAddress('alice@test.fr');
        sort($deleted);

        self::assertSame(['a1', 'ab'], $deleted);
        self::assertSame(['b1'], $this->pdo->query('SELECT mailpit_id FROM mailpit_messages')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([], $this->repository->deleteByAddress('alice@test.fr'));
    }

    public function testFindIncludesStoragePath(): void
    {
        $this->save(self::payload('m1', parts: [['2', 10]]));
        $this->repository->markPartStored($this->pk('m1'), '2', 10, hash('sha256', 'x'), 'm1/part-2');

        $message = $this->repository->find('m1');

        self::assertNotNull($message);
        self::assertTrue($message['complete']);
        self::assertSame('m1/part-2', $message['parts'][0]['storage_path']);
        self::assertNull($this->repository->find('inconnu'));
    }
}
