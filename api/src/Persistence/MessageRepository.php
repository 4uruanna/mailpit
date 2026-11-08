<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Mailpit\MessageSummary;

final readonly class MessageRepository
{
    private const string SQL = <<<'SQL'
        INSERT INTO mailpit_messages (
            mailpit_id, message_id, from_name, from_address,
            to_addresses, cc_addresses, bcc_addresses, reply_to,
            subject, snippet, text_body, html_body, raw_file, tags, size, attachments,
            smtp_username, mailpit_label, created_at, source_ip, payload
        ) VALUES (
            :mailpit_id, :message_id, :from_name, :from_address,
            :to_addresses, :cc_addresses, :bcc_addresses, :reply_to,
            :subject, :snippet, :text_body, :html_body, :raw_file, :tags, :size, :attachments,
            :smtp_username, :mailpit_label, :created_at, :source_ip, :payload
        )
        ON DUPLICATE KEY UPDATE id = id
        SQL;

    public function __construct(private Database $database)
    {
    }

    /**
     * Enregistre le message. Un même ID Mailpit n'est stocké qu'une fois.
     *
     * @param ?string $rawFile nom du fichier .eml enregistré (dossier raw_dir), ou null
     * @param string $payload JSON du webhook, sans le corps (Text, HTML, Raw)
     * @return bool true si le message a été ajouté, false s'il existait déjà
     */
    public function save(MessageSummary $message, ?string $label, string $sourceIp, string $payload, ?string $rawFile = null): bool
    {
        $statement = $this->database->pdo()->prepare(self::SQL);
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
            'raw_file'      => $rawFile,
            'tags'          => self::json($message->tags),
            'size'          => $message->size,
            'attachments'   => $message->attachments,
            'smtp_username' => $message->username === '' ? null : self::cut($message->username, 255),
            'mailpit_label' => $label === null || $label === '' ? null : self::cut($label, 255),
            'created_at'    => $message->created->format('Y-m-d H:i:s.u'),
            'source_ip'     => $sourceIp,
            'payload'       => $payload,
        ]);

        return $statement->rowCount() === 1;
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
