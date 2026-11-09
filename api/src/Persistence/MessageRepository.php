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
            tags, size, attachments, smtp_username, mailpit_label, created_at, source_ip, payload, truncated
        ) VALUES (
            :mailpit_id, :message_id, :from_name, :from_address,
            :to_addresses, :cc_addresses, :bcc_addresses, :reply_to,
            :subject, :snippet, :text_body, :html_body, :headers, :return_path, :message_date,
            :tags, :size, :attachments, :smtp_username, :mailpit_label, :created_at, :source_ip, :payload, :truncated
        ) AS new
        ON DUPLICATE KEY UPDATE
            -- Version tronquée par le relais (mail trop volumineux) : une version complète la remplace
            -- toujours ; une version tronquée ne remplace jamais le corps d'une version complète déjà
            -- enregistrée ; un résumé seul (sans corps) ne change rien au drapeau. « truncated » est
            -- calculé EN PREMIER, sur l'ancienne ligne (MySQL applique les affectations dans l'ordre) :
            -- les colonnes suivantes lisent déjà sa nouvelle valeur.
            truncated    = CASE
                WHEN new.text_body IS NULL AND new.html_body IS NULL AND new.headers IS NULL
                    THEN mailpit_messages.truncated
                WHEN new.truncated = 1 AND mailpit_messages.truncated = 0 AND (mailpit_messages.text_body IS NOT NULL
                    OR mailpit_messages.html_body IS NOT NULL OR mailpit_messages.headers IS NOT NULL)
                    THEN 0
                ELSE new.truncated
            END,
            -- Le contenu d'un mail ne change pas dans Mailpit : on ne fait que compléter
            -- ce qui manquait (ex. résumé reçu par l'ancien webhook direct de Mailpit, puis mail complet).
            -- Seule exception : une version tronquée arrivée après la version complète est ignorée
            -- (new.truncated = 1 alors que la ligne est restée complète).
            text_body    = IF(new.truncated > mailpit_messages.truncated,
                COALESCE(mailpit_messages.text_body, new.text_body), COALESCE(new.text_body, mailpit_messages.text_body)),
            html_body    = IF(new.truncated > mailpit_messages.truncated,
                COALESCE(mailpit_messages.html_body, new.html_body), COALESCE(new.html_body, mailpit_messages.html_body)),
            headers      = IF(new.truncated > mailpit_messages.truncated,
                COALESCE(mailpit_messages.headers, new.headers), COALESCE(new.headers, mailpit_messages.headers)),
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
     * Version tronquée par le relais ($message->truncated) : une version complète reçue ensuite
     * remplace son corps et remet « truncated » à 0 ; l'inverse n'arrive jamais, et un résumé seul
     * (sans corps) garde le drapeau (voir UPSERT_MESSAGE).
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
                'truncated'     => $message->truncated ? 1 : 0,
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
     * @return array{complete: bool, missing: list<string>, too_large: list<string>}|null null si le
     *         mail (ou la pièce) n'existe plus : supprimé pendant l'envoi (purge, Dashboard, API).
     *         Le fichier n'est alors rattaché à rien : à effacer.
     */
    public function markPartStored(int $messagePk, string $partId, int $size, string $sha256, string $path): ?array
    {
        return $this->transaction(function (PDO $pdo) use ($messagePk, $partId, $size, $sha256, $path): ?array {
            // Verrouille le message : deux envois simultanés ne calculent pas l'état en même temps,
            // et une suppression attend la fin de cette transaction (elle effacera alors le fichier).
            $lock = $pdo->prepare('SELECT id FROM mailpit_messages WHERE id = ? FOR UPDATE');
            $lock->execute([$messagePk]);
            if ($lock->fetchColumn() === false) {
                return null;
            }

            $update = $pdo->prepare(<<<'SQL'
                UPDATE mailpit_parts
                SET status = 'stored', size = ?, sha256 = ?, storage_path = ?, stored_at = NOW(6)
                WHERE message_id = ? AND part_id = ?
                SQL);
            $update->execute([$size, $sha256, $path, $messagePk, $partId]);
            // stored_at change à chaque fois : 0 ligne modifiée = la pièce n'existe plus.
            if ($update->rowCount() === 0) {
                return null;
            }

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
     * Supprime les mails reçus par Mailpit avant $before (colonne created_at, indexée), des plus
     * anciens aux plus récents. Leurs pièces et destinataires partent avec eux (ON DELETE CASCADE).
     * Au plus $batch mails par appel, comme prune().
     *
     * @return list<string> ID Mailpit des mails supprimés, pour effacer leurs fichiers
     */
    public function deleteReceivedBefore(DateTimeImmutable $before, int $batch = 200): array
    {
        $limit = $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return $this->transaction(function (PDO $pdo) use ($limit, $batch): array {
            $select = $pdo->prepare('SELECT id, mailpit_id FROM mailpit_messages WHERE created_at < ? ORDER BY created_at, id LIMIT ? FOR UPDATE');
            $select->bindValue(1, $limit);
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
     * Fichiers enregistrés en base pour ces mails (mailpit_parts.storage_path).
     *
     * @param list<string> $mailpitIds
     * @return array<string, list<string>> ID Mailpit => chemins relatifs au dossier de stockage.
     *         Un mail absent de la base n'a pas d'entrée ; un mail sans fichier a une liste vide.
     */
    public function storedPaths(array $mailpitIds): array
    {
        $paths = [];
        foreach (array_chunk(array_values($mailpitIds), 500) as $chunk) {
            $statement = $this->database->pdo()->prepare(sprintf(
                <<<'SQL'
                    SELECT m.mailpit_id, p.storage_path
                    FROM mailpit_messages m
                    LEFT JOIN mailpit_parts p ON p.message_id = m.id AND p.storage_path IS NOT NULL
                    WHERE m.mailpit_id IN (%s)
                    SQL,
                implode(',', array_fill(0, count($chunk), '?')),
            ));
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $id = (string) $row['mailpit_id'];
                $paths[$id] ??= [];
                if ($row['storage_path'] !== null) {
                    $paths[$id][] = (string) $row['storage_path'];
                }
            }
        }

        return $paths;
    }

    private const string COLUMNS = <<<'SQL'
        id, mailpit_id, message_id, from_name, from_address, to_addresses, cc_addresses,
        bcc_addresses, reply_to, subject, snippet, return_path, message_date, tags, size,
        attachments, smtp_username, mailpit_label, created_at, received_at, completed_at, truncated
        SQL;

    /**
     * Liste des messages triés par date de réception par Mailpit (du plus récent au plus ancien,
     * ou l'inverse), avec la description de leurs pièces. Résumés seulement : ni corps ni en-têtes
     * (jusqu'à 16 Mo chacun), pour ne pas dépasser la mémoire de l'hébergement. Le mail complet : find().
     *
     * @param list<string>|null $addresses ne garde que les mails dont un destinataire (To, Cc, Bcc)
     *                                     est dans cette liste ; null = tous les mails
     * @param bool $oldestFirst du plus ancien au plus récent
     * @param DateTimeImmutable|null $since ne garde que les mails reçus par Mailpit strictement
     *                                      après cette date, à la milliseconde près (voir filter())
     * @param bool $completeOnly ne garde que les mails complets (toutes les pièces reçues)
     * @return array{total: int, messages: list<array<string, mixed>>}
     */
    public function list(
        int $limit,
        int $offset,
        ?array $addresses = null,
        bool $oldestFirst = false,
        ?DateTimeImmutable $since = null,
        bool $completeOnly = false,
    ): array {
        if ($addresses === []) {
            return ['total' => 0, 'messages' => []];
        }

        $pdo = $this->database->pdo();
        [$where, $params] = self::filter($addresses, $since, $completeOnly);

        $count = $pdo->prepare("SELECT COUNT(*) FROM mailpit_messages $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $columns = self::COLUMNS;
        $order = $oldestFirst ? 'created_at ASC, id ASC' : 'created_at DESC, id DESC';
        $statement = $pdo->prepare(<<<SQL
            SELECT $columns
            FROM mailpit_messages
            $where
            ORDER BY $order
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

        $parts = $this->parts($pdo, array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $messages = array_map(
            static fn (array $row): array => self::present($row, $parts[(int) $row['id']] ?? []),
            $rows,
        );

        return ['total' => $total, 'messages' => $messages];
    }

    /**
     * Au moins un mail correspond aux filtres de list() : requête légère (SELECT 1 … LIMIT 1),
     * répétée chaque seconde par GET /v1/messages?wait=…
     *
     * @param list<string>|null $addresses
     */
    public function exists(?array $addresses = null, ?DateTimeImmutable $since = null, bool $completeOnly = false): bool
    {
        if ($addresses === []) {
            return false;
        }

        [$where, $params] = self::filter($addresses, $since, $completeOnly);
        // Hors transaction (autocommit) : chaque requête voit les mails enregistrés depuis la précédente.
        $statement = $this->database->pdo()->prepare("SELECT 1 FROM mailpit_messages $where LIMIT 1");
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Clause WHERE commune à list() et exists().
     *
     * $since est comparé à la milliseconde près, comme les dates renvoyées par l'API
     * (« 2026-11-08T22:10:33.809Z ») : created_at doit être au moins 1 ms après $since tronqué à la
     * milliseconde. Un mail reçu à 22:10:33.809512 n'est donc pas « après » 22:10:33.809Z, sa propre
     * date telle que l'API l'affiche.
     *
     * @param list<string>|null $addresses
     * @return array{string, list<mixed>}
     */
    private static function filter(?array $addresses, ?DateTimeImmutable $since, bool $completeOnly): array
    {
        $conditions = [];
        $params = [];
        if ($addresses !== null) {
            $conditions[] = sprintf(
                'id IN (SELECT message_id FROM mailpit_message_recipients WHERE address IN (%s))',
                implode(',', array_fill(0, count($addresses), '?')),
            );
            $params = array_values($addresses);
        }
        if ($since !== null) {
            $since = $since->setTimezone(new DateTimeZone('UTC'));
            $millisecond = $since->setTime(
                (int) $since->format('G'),
                (int) $since->format('i'),
                (int) $since->format('s'),
                intdiv((int) $since->format('u'), 1000) * 1000,
            );
            $conditions[] = 'created_at >= ?';
            $params[] = $millisecond->modify('+1 msec')->format('Y-m-d H:i:s.u');
        }
        if ($completeOnly) {
            $conditions[] = 'completed_at IS NOT NULL';
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Un mail complet : champs de la liste, corps (« text », « html »), en-têtes (« headers »),
     * et pièces avec leur fichier (« storage_path », relatif au dossier de stockage).
     *
     * @param list<string>|null $addresses le mail n'est renvoyé que si l'un de ses destinataires
     *                                     (To, Cc, Bcc) est dans cette liste ; null = sans condition
     * @param bool $withPath ajoute « storage_path » aux pièces (Dashboard) ; false pour l'API
     * @return array<string, mixed>|null null si le mail est inconnu (ou hors des adresses autorisées)
     */
    public function find(string $mailpitId, ?array $addresses = null, bool $withPath = true): ?array
    {
        if ($addresses === []) {
            return null;
        }

        $sql = sprintf('SELECT %s, text_body, html_body, headers FROM mailpit_messages WHERE mailpit_id = ?', self::COLUMNS);
        $params = [$mailpitId];
        if ($addresses !== null) {
            $sql .= sprintf(
                ' AND id IN (SELECT message_id FROM mailpit_message_recipients WHERE address IN (%s))',
                implode(',', array_fill(0, count($addresses), '?')),
            );
            $params = [...$params, ...array_values($addresses)];
        }

        $pdo = $this->database->pdo();
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $parts = $this->parts($pdo, [(int) $row['id']], $withPath);

        return self::present($row, $parts[(int) $row['id']] ?? []) + [
            'text'    => $row['text_body'],
            'html'    => $row['html_body'],
            'headers' => self::decode($row['headers']),
        ];
    }

    /**
     * Adresses ayant reçu au moins un mail (To, Cc, Bcc), par ordre alphabétique.
     *
     * @return array<string, int> adresse => nombre de mails
     */
    public function recipients(): array
    {
        $rows = $this->database->pdo()->query(
            'SELECT address, COUNT(*) AS total FROM mailpit_message_recipients GROUP BY address ORDER BY address',
        )->fetchAll();

        $recipients = [];
        foreach ($rows as $row) {
            $recipients[(string) $row['address']] = (int) $row['total'];
        }

        return $recipients;
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
     * Pièces des messages demandés, par ID de message.
     *
     * @param list<int> $messageIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function parts(PDO $pdo, array $messageIds, bool $withPath = false): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $statement = $pdo->prepare(<<<SQL
            SELECT message_id, part_id, kind, file_name, content_type, content_id, size, status, storage_path
            FROM mailpit_parts
            WHERE message_id IN ($placeholders)
            ORDER BY id
            SQL);
        $statement->execute($messageIds);

        $parts = [];
        foreach ($statement->fetchAll() as $part) {
            $parts[(int) $part['message_id']][] = [
                'part_id'      => (string) $part['part_id'],
                'kind'         => (string) $part['kind'],
                'file_name'    => (string) $part['file_name'],
                'content_type' => (string) $part['content_type'],
                'content_id'   => (string) $part['content_id'],
                'size'         => (int) $part['size'],
                'status'       => (string) $part['status'],
            ] + ($withPath ? ['storage_path' => $part['storage_path']] : []);
        }

        return $parts;
    }

    /**
     * Résumé d'une ligne de mailpit_messages tel que renvoyé par l'API (sans corps ni en-têtes).
     *
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private static function present(array $row, array $parts): array
    {
        return [
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
            'truncated'   => (bool) $row['truncated'],
            'parts'       => $parts,
        ];
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
