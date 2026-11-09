<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Mailpit\Address;
use App\Mailpit\Message;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Accès aux tables mailpit_messages, mailpit_parts et mailpit_message_recipients.
 *
 * Un mail est « complet » (colonne completed_at) quand toutes les pièces annoncées
 * sont enregistrées, ou ignorées parce qu'elles dépassent la limite de taille.
 * C'est ce que le relais attend avant de considérer le mail comme livré.
 */
final readonly class MessageRepository
{
    private const string UPSERT_MESSAGE = <<<'SQL'
        INSERT INTO mailpit_messages (
            mailpit_id, message_id, from_name, from_address,
            to_addresses, cc_addresses, bcc_addresses, reply_to,
            subject, snippet, text_body, html_body, headers, return_path, message_date,
            tags, size, attachments, smtp_username, mailpit_label, created_at, source_ip, payload
        ) VALUES (
            :mailpit_id, :message_id, :from_name, :from_address,
            :to_addresses, :cc_addresses, :bcc_addresses, :reply_to,
            :subject, :snippet, :text_body, :html_body, :headers, :return_path, :message_date,
            :tags, :size, :attachments, :smtp_username, :mailpit_label, :created_at, :source_ip, :payload
        ) AS new
        ON DUPLICATE KEY UPDATE
            -- Le contenu d'un mail ne change pas dans Mailpit : on ne fait que compléter
            -- ce qui manquait (ex. résumé reçu d'abord directement de Mailpit, puis mail complet).
            text_body    = COALESCE(new.text_body, mailpit_messages.text_body),
            html_body    = COALESCE(new.html_body, mailpit_messages.html_body),
            headers      = COALESCE(new.headers, mailpit_messages.headers),
            return_path  = IF(new.return_path <> '', new.return_path, mailpit_messages.return_path),
            message_date = COALESCE(new.message_date, mailpit_messages.message_date)
        SQL;

    private const string UPSERT_PART = <<<'SQL'
        INSERT INTO mailpit_parts (message_id, part_id, kind, file_name, content_type, content_id, size, status)
        VALUES (:message_id, :part_id, :kind, :file_name, :content_type, :content_id, :size, :status) AS new
        ON DUPLICATE KEY UPDATE
            kind         = new.kind,
            file_name    = new.file_name,
            content_type = new.content_type,
            content_id   = new.content_id,
            -- Une pièce déjà enregistrée garde sa taille réelle et son état (status en dernier :
            -- MySQL applique les affectations dans l'ordre).
            size         = IF(mailpit_parts.status = 'stored', mailpit_parts.size, new.size),
            status       = IF(mailpit_parts.status = 'stored', 'stored', new.status)
        SQL;

    public function __construct(private Database $database)
    {
    }

    /**
     * Enregistre le message et déclare ses pièces. Un même ID Mailpit n'est stocké qu'une fois :
     * un nouvel envoi complète les champs vides et renvoie la liste des pièces encore attendues.
     *
     * @param string $payload JSON reçu, sans le corps (Text, HTML, Headers)
     * @return array{status: 'created'|'updated'|'duplicate', complete: bool, missing: list<string>, too_large: list<string>}
     */
    public function save(
        Message $message,
        ?string $label,
        string $sourceIp,
        string $payload,
        int $maxPartSize,
    ): array {
        return $this->transaction(function (PDO $pdo) use ($message, $label, $sourceIp, $payload, $maxPartSize): array {
            $statement = $pdo->prepare(self::UPSERT_MESSAGE);
            $statement->execute([
                'mailpit_id'    => $message->id,
                'message_id'    => self::cut($message->messageId, 998),
                'from_name'     => self::cut($message->from['name'] ?? '', 255),
                'from_address'  => self::cut($message->from['address'] ?? '', 320),
                'to_addresses'  => self::json($message->to),
                'cc_addresses'  => self::json($message->cc),
                'bcc_addresses' => self::json($message->bcc),
                'reply_to'      => self::json($message->replyTo),
                'subject'       => self::cut($message->subject, 998),
                'snippet'       => self::cut($message->snippet, 1000),
                'text_body'     => self::cutBytes($message->text),
                'html_body'     => self::cutBytes($message->html),
                'headers'       => match ($message->headers) {
                    null    => null,
                    []      => '{}',
                    default => self::json($message->headers),
                },
                'return_path'   => self::cut($message->returnPath, 320),
                'message_date'  => $message->date?->format('Y-m-d H:i:s.u'),
                'tags'          => self::json($message->tags),
                'size'          => $message->size,
                'attachments'   => $message->attachments,
                'smtp_username' => $message->username === '' ? null : self::cut($message->username, 255),
                'mailpit_label' => $label === null || $label === '' ? null : self::cut($label, 255),
                'created_at'    => $message->created->format('Y-m-d H:i:s.u'),
                'source_ip'     => $sourceIp,
                'payload'       => $payload,
            ]);
            // MySQL : 1 = ligne ajoutée, 2 = ligne modifiée, 0 = rien n'a changé.
            $status = match ($statement->rowCount()) {
                1       => 'created',
                2       => 'updated',
                default => 'duplicate',
            };

            $lookup = $pdo->prepare('SELECT id FROM mailpit_messages WHERE mailpit_id = ? FOR UPDATE');
            $lookup->execute([$message->id]);
            $messagePk = (int) $lookup->fetchColumn();

            $this->saveRecipients($pdo, $messagePk, $message);

            $insertPart = $pdo->prepare(self::UPSERT_PART);
            foreach ($message->parts as $part) {
                $insertPart->execute([
                    'message_id'   => $messagePk,
                    'part_id'      => $part->partId,
                    'kind'         => $part->kind,
                    'file_name'    => $part->fileName,
                    'content_type' => $part->contentType,
                    'content_id'   => $part->contentId,
                    'size'         => $part->size,
                    'status'       => $part->size > $maxPartSize ? 'too_large' : 'pending',
                ]);
            }

            return ['status' => $status] + $this->refreshCompletion($pdo, $messagePk);
        });
    }

    /**
     * @return array{message_pk: int, kind: string, status: string, sha256: ?string}|null
     */
    public function findPart(string $mailpitId, string $partId): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT p.message_id AS message_pk, p.kind, p.status, p.sha256
            FROM mailpit_parts p
            JOIN mailpit_messages m ON m.id = p.message_id
            WHERE m.mailpit_id = ? AND p.part_id = ?
            SQL);
        $statement->execute([$mailpitId, $partId]);
        $row = $statement->fetch();

        return $row === false ? null : [
            'message_pk' => (int) $row['message_pk'],
            'kind'       => (string) $row['kind'],
            'status'     => (string) $row['status'],
            'sha256'     => $row['sha256'] === null ? null : (string) $row['sha256'],
        ];
    }

    /**
     * Marque une pièce comme enregistrée (le fichier est déjà sur le disque).
     *
     * @return array{complete: bool, missing: list<string>, too_large: list<string>}
     */
    public function markPartStored(int $messagePk, string $partId, int $size, string $sha256, string $path): array
    {
        return $this->transaction(function (PDO $pdo) use ($messagePk, $partId, $size, $sha256, $path): array {
            // Verrouille le message : deux envois simultanés ne calculent pas l'état en même temps.
            $pdo->prepare('SELECT id FROM mailpit_messages WHERE id = ? FOR UPDATE')->execute([$messagePk]);

            $pdo->prepare(<<<'SQL'
                UPDATE mailpit_parts
                SET status = 'stored', size = ?, sha256 = ?, storage_path = ?, stored_at = NOW(6)
                WHERE message_id = ? AND part_id = ?
                SQL)->execute([$size, $sha256, $path, $messagePk, $partId]);

            return $this->refreshCompletion($pdo, $messagePk);
        });
    }

    /**
     * Supprime les mails les plus anciens pour n'en garder que $keep (ordre d'arrivée dans l'API).
     * Leurs lignes de mailpit_parts partent avec eux (ON DELETE CASCADE).
     * Au plus $batch mails par appel : une grosse purge se fait en plusieurs fois.
     *
     * @return list<string> ID Mailpit des mails supprimés, pour effacer leurs fichiers
     */
    public function prune(int $keep, int $batch = 200): array
    {
        if ($keep < 1) {
            return [];
        }

        return $this->transaction(function (PDO $pdo) use ($keep, $batch): array {
            // ID du plus récent des mails à supprimer : celui qui suit les $keep plus récents.
            $threshold = $pdo->prepare('SELECT id FROM mailpit_messages ORDER BY id DESC LIMIT 1 OFFSET ?');
            $threshold->bindValue(1, $keep, PDO::PARAM_INT);
            $threshold->execute();
            $maxId = $threshold->fetchColumn();
            if ($maxId === false) {
                return [];
            }

            $select = $pdo->prepare('SELECT id, mailpit_id FROM mailpit_messages WHERE id <= ? ORDER BY id LIMIT ? FOR UPDATE');
            $select->bindValue(1, (int) $maxId, PDO::PARAM_INT);
            $select->bindValue(2, $batch, PDO::PARAM_INT);
            $select->execute();
            $rows = $select->fetchAll();
            if ($rows === []) {
                return [];
            }

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM mailpit_messages WHERE id IN ($placeholders)")->execute($ids);

            return array_map(static fn (array $row): string => (string) $row['mailpit_id'], $rows);
        });
    }

    /**
     * Liste des messages, du plus récent au plus ancien (date de réception par Mailpit),
     * avec la description de leurs pièces. Sans les en-têtes, pour rester léger.
     *
     * @param list<string>|null $addresses ne garde que les mails dont un destinataire (To, Cc, Bcc)
     *                                     est dans cette liste ; null = tous les mails
     * @param bool $withBodies ajoute le corps du mail (« text », « html »)
     * @return array{total: int, messages: list<array<string, mixed>>}
     */
    public function list(int $limit, int $offset, ?array $addresses = null, bool $withBodies = false): array
    {
        if ($addresses === []) {
            return ['total' => 0, 'messages' => []];
        }

        $pdo = $this->database->pdo();

        $where = '';
        $params = [];
        if ($addresses !== null) {
            $where = sprintf(
                'WHERE id IN (SELECT message_id FROM mailpit_message_recipients WHERE address IN (%s))',
                implode(',', array_fill(0, count($addresses), '?')),
            );
            $params = array_values($addresses);
        }

        $count = $pdo->prepare("SELECT COUNT(*) FROM mailpit_messages $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $bodies = $withBodies ? ', text_body, html_body' : '';
        $statement = $pdo->prepare(<<<SQL
            SELECT id, mailpit_id, message_id, from_name, from_address, to_addresses, cc_addresses,
                   bcc_addresses, reply_to, subject, snippet, return_path, message_date, tags, size,
                   attachments, smtp_username, mailpit_label, created_at, received_at, completed_at$bodies
            FROM mailpit_messages
            $where
            ORDER BY created_at DESC, id DESC
            LIMIT ? OFFSET ?
            SQL);
        $position = 1;
        foreach ($params as $param) {
            $statement->bindValue($position++, $param);
        }
        $statement->bindValue($position++, $limit, PDO::PARAM_INT);
        $statement->bindValue($position, $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        $parts = [];
        if ($rows !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $partStatement = $pdo->prepare(<<<SQL
                SELECT message_id, part_id, kind, file_name, content_type, content_id, size, status
                FROM mailpit_parts
                WHERE message_id IN ($placeholders)
                ORDER BY id
                SQL);
            $partStatement->execute($ids);
            foreach ($partStatement->fetchAll() as $part) {
                $parts[(int) $part['message_id']][] = [
                    'part_id'      => (string) $part['part_id'],
                    'kind'         => (string) $part['kind'],
                    'file_name'    => (string) $part['file_name'],
                    'content_type' => (string) $part['content_type'],
                    'content_id'   => (string) $part['content_id'],
                    'size'         => (int) $part['size'],
                    'status'       => (string) $part['status'],
                ];
            }
        }

        $messages = array_map(static fn (array $row): array => [
            'id'          => (string) $row['mailpit_id'],
            'message_id'  => (string) $row['message_id'],
            'from'        => $row['from_address'] === '' && $row['from_name'] === ''
                ? null
                : ['name' => (string) $row['from_name'], 'address' => (string) $row['from_address']],
            'to'          => self::decode($row['to_addresses']),
            'cc'          => self::decode($row['cc_addresses']),
            'bcc'         => self::decode($row['bcc_addresses']),
            'reply_to'    => self::decode($row['reply_to']),
            'subject'     => (string) $row['subject'],
            'snippet'     => (string) $row['snippet'],
            'return_path' => (string) $row['return_path'],
            'tags'        => self::decode($row['tags']),
            'size'        => (int) $row['size'],
            'attachments' => (int) $row['attachments'],
            'username'    => $row['smtp_username'],
            'label'       => $row['mailpit_label'],
            'date'        => self::isoDate($row['message_date']),
            'created_at'  => self::isoDate($row['created_at']),
            'received_at' => self::isoDate($row['received_at']),
            'complete'    => $row['completed_at'] !== null,
            'parts'       => $parts[(int) $row['id']] ?? [],
        ] + ($withBodies ? [
            'text' => $row['text_body'],
            'html' => $row['html_body'],
        ] : []), $rows);

        return ['total' => $total, 'messages' => $messages];
    }

    /**
     * Nombre de mails reçus par chacune des adresses.
     *
     * @param list<string> $addresses
     * @return array<string, int> adresse => nombre de mails
     */
    public function countByAddress(array $addresses): array
    {
        $counts = array_fill_keys($addresses, 0);
        if ($addresses === []) {
            return $counts;
        }

        $statement = $this->database->pdo()->prepare(sprintf(
            'SELECT address, COUNT(*) AS total FROM mailpit_message_recipients WHERE address IN (%s) GROUP BY address',
            implode(',', array_fill(0, count($addresses), '?')),
        ));
        $statement->execute(array_values($addresses));
        foreach ($statement->fetchAll() as $row) {
            $address = Address::normalize((string) $row['address']);
            if (array_key_exists($address, $counts)) {
                $counts[$address] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Supprime un mail (ses pièces et destinataires partent avec lui : ON DELETE CASCADE).
     *
     * @param list<string>|null $addresses le mail n'est supprimé que si l'un de ses destinataires
     *                                     est dans cette liste ; null = sans condition
     * @return bool false si le mail est inconnu (ou hors des adresses autorisées)
     */
    public function delete(string $mailpitId, ?array $addresses = null): bool
    {
        if ($addresses === []) {
            return false;
        }

        return $this->transaction(function (PDO $pdo) use ($mailpitId, $addresses): bool {
            $sql = 'SELECT id FROM mailpit_messages WHERE mailpit_id = ?';
            $params = [$mailpitId];
            if ($addresses !== null) {
                $sql .= sprintf(
                    ' AND id IN (SELECT message_id FROM mailpit_message_recipients WHERE address IN (%s))',
                    implode(',', array_fill(0, count($addresses), '?')),
                );
                $params = [...$params, ...array_values($addresses)];
            }
            $select = $pdo->prepare($sql . ' FOR UPDATE');
            $select->execute($params);
            $pk = $select->fetchColumn();
            if ($pk === false) {
                return false;
            }

            $pdo->prepare('DELETE FROM mailpit_messages WHERE id = ?')->execute([(int) $pk]);

            return true;
        });
    }

    /**
     * Supprime tous les mails reçus par une adresse (To, Cc ou Bcc).
     * Un mail envoyé à plusieurs adresses est supprimé pour toutes.
     *
     * @return list<string> ID Mailpit des mails supprimés, pour effacer leurs fichiers
     */
    public function deleteByAddress(string $address): array
    {
        return $this->transaction(function (PDO $pdo) use ($address): array {
            $select = $pdo->prepare(<<<'SQL'
                SELECT m.id, m.mailpit_id
                FROM mailpit_messages m
                JOIN mailpit_message_recipients r ON r.message_id = m.id
                WHERE r.address = ?
                FOR UPDATE
                SQL);
            $select->execute([$address]);
            $rows = $select->fetchAll();
            if ($rows === []) {
                return [];
            }

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("DELETE FROM mailpit_messages WHERE id IN ($placeholders)")->execute($chunk);
            }

            return array_map(static fn (array $row): string => (string) $row['mailpit_id'], $rows);
        });
    }

    /** @return array{complete: bool, missing: list<string>, too_large: list<string>} */
    public function completion(int $messagePk): array
    {
        return $this->transaction(fn (PDO $pdo): array => $this->refreshCompletion($pdo, $messagePk));
    }

    /** Enregistre les destinataires (To, Cc, Bcc) du message, en minuscules. Sans effet s'ils sont déjà connus. */
    private function saveRecipients(PDO $pdo, int $messagePk, Message $message): void
    {
        $addresses = [];
        foreach ([...$message->to, ...$message->cc, ...$message->bcc] as $recipient) {
            $address = Address::normalize($recipient['address']);
            if ($address !== '') {
                $addresses[mb_substr($address, 0, Address::MAX_LENGTH)] = true;
            }
        }
        if ($addresses === []) {
            return;
        }

        $values = implode(',', array_fill(0, count($addresses), '(?, ?)'));
        $params = [];
        foreach (array_keys($addresses) as $address) {
            $params[] = $messagePk;
            $params[] = (string) $address;
        }
        $pdo->prepare("INSERT IGNORE INTO mailpit_message_recipients (message_id, address) VALUES $values")->execute($params);
    }

    /**
     * Recalcule completed_at d'après l'état des pièces.
     *
     * @return array{complete: bool, missing: list<string>, too_large: list<string>}
     */
    private function refreshCompletion(PDO $pdo, int $messagePk): array
    {
        $statement = $pdo->prepare('SELECT part_id, status FROM mailpit_parts WHERE message_id = ? ORDER BY id');
        $statement->execute([$messagePk]);

        $missing = [];
        $tooLarge = [];
        foreach ($statement->fetchAll() as $row) {
            match ($row['status']) {
                'pending'   => $missing[] = (string) $row['part_id'],
                'too_large' => $tooLarge[] = (string) $row['part_id'],
                default     => null,
            };
        }

        $complete = $missing === [];
        $pdo->prepare($complete
            ? 'UPDATE mailpit_messages SET completed_at = COALESCE(completed_at, NOW(6)) WHERE id = ?'
            : 'UPDATE mailpit_messages SET completed_at = NULL WHERE id = ?')->execute([$messagePk]);

        return ['complete' => $complete, 'missing' => $missing, 'too_large' => $tooLarge];
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<mixed> */
    private static function decode(?string $json): array
    {
        $value = $json === null ? null : json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    /** Date UTC de la base (« 2026-11-08 22:10:33.809000 ») au format ISO 8601 (« 2026-11-08T22:10:33.809Z »). */
    private static function isoDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    /** Limite d'une colonne MEDIUMTEXT (16 Mo), sans couper un caractère UTF-8. */
    private static function cutBytes(?string $value): ?string
    {
        return $value === null ? null : mb_strcut($value, 0, 16_777_215);
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
