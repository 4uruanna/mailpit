<?php

declare(strict_types=1);

namespace Relay;

/**
 * Version raccourcie d'un mail que l'API refuse comme trop volumineux (HTTP 413 sur POST /v1/messages).
 *
 * Plutôt que de perdre tout le mail, le relais renvoie une version tronquée qui tient dans la limite
 * de l'API : d'abord le HTML, puis le texte, puis si besoin les valeurs d'en-têtes les plus longues,
 * sans jamais couper un caractère UTF-8. Une note est ajoutée à la fin du texte (et du HTML), et le
 * champ « Truncated »: true prévient l'API, qui l'affiche dans le Dashboard.
 *
 * Les pièces jointes ne sont pas concernées : elles sont envoyées à part, et l'API gère déjà leur
 * propre limite (« too_large »).
 */
final class MessageTruncator
{
    /** Note ajoutée à la fin du texte (et, en paragraphe, à la fin du HTML). */
    public const string NOTICE = '[… contenu tronqué par le relais : mail trop volumineux pour l\'API]';

    /** Envois tronqués au plus, après le refus de la version complète. */
    public const int MAX_ATTEMPTS = 3;

    /** Marge sous la limite de l'API (octets). */
    public const int MARGIN = 1024;

    /** Fin d'une valeur d'en-tête raccourcie. */
    private const string HEADER_ELLIPSIS = '…';

    /** Encodage JSON du mail, le même pour la mesure et pour l'envoi. */
    public static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Taille visée (octets) après un refus 413 : la limite annoncée par l'API (« max_body_size »)
     * si elle est plus petite que ce qui a été envoyé, sinon la moitié de l'envoi ; moins MARGIN.
     */
    public static function budget(int $sentSize, ?int $limit): int
    {
        $target = $limit !== null && $limit > 0 && $limit < $sentSize ? $limit : intdiv($sentSize, 2);

        return max(0, $target - self::MARGIN);
    }

    /**
     * Limite annoncée dans une réponse 413 de l'API : {"error": "…", "max_body_size": N}.
     * null si la réponse n'en donne pas (ex. 413 d'un proxy, en HTML).
     */
    public static function reportedLimit(string $body): ?int
    {
        $data = json_decode($body, true);
        $limit = is_array($data) ? ($data['max_body_size'] ?? null) : null;

        return is_int($limit) && $limit > 0 ? $limit : null;
    }

    /**
     * Version du mail dont le JSON encodé tient dans $budget octets, avec la note et « Truncated »: true.
     * Toujours construite à partir du mail d'origine (jamais d'une version déjà tronquée).
     *
     * @param array<string, mixed> $message mail tel qu'envoyé à l'API (Text, HTML, Headers…)
     * @return array<string, mixed>|null null si même sans corps et avec des en-têtes vides, le mail
     *                                   ne tient pas (liste de destinataires ou de pièces énorme…)
     */
    public static function truncate(array $message, int $budget): ?array
    {
        $html = is_string($message['HTML'] ?? null) ? $message['HTML'] : '';
        $text = is_string($message['Text'] ?? null) ? $message['Text'] : '';
        $hadHtml = $html !== '';

        $message['Truncated'] = true;
        $build = static function (array $message, string $text, string $html) use ($hadHtml): array {
            $message['Text'] = self::withTextNotice($text);
            $message['HTML'] = $hadHtml ? self::withHtmlNotice($html) : '';

            return $message;
        };

        $candidate = $build($message, $text, $html);
        $excess = strlen(self::encode($candidate)) - $budget;

        // 1. Le HTML, souvent le plus lourd (styles, images en base64…).
        while ($excess > 0 && $html !== '') {
            $html = self::cutHtml($html, strlen($html) - $excess);
            $candidate = $build($message, $text, $html);
            $excess = strlen(self::encode($candidate)) - $budget;
        }

        // 2. Le texte.
        while ($excess > 0 && $text !== '') {
            $text = self::cut($text, strlen($text) - $excess);
            $candidate = $build($message, $text, $html);
            $excess = strlen(self::encode($candidate)) - $budget;
        }

        // 3. Les valeurs d'en-têtes les plus longues, une à une.
        while ($excess > 0) {
            $longest = self::longestHeader($candidate['Headers'] ?? null);
            if ($longest === null) {
                return null;
            }
            [$name, $index, $value] = $longest;
            $keep = strlen($value) - $excess - strlen(self::HEADER_ELLIPSIS);
            $candidate['Headers'][$name][$index] = $keep > 0 ? self::cut($value, $keep) . self::HEADER_ELLIPSIS : '';
            $excess = strlen(self::encode($candidate)) - $budget;
        }

        return $candidate;
    }

    private static function withTextNotice(string $text): string
    {
        return $text === '' ? self::NOTICE : rtrim($text) . "\n\n" . self::NOTICE;
    }

    private static function withHtmlNotice(string $html): string
    {
        return $html . "\n" . '<p style="margin-top:2em;color:#666;font-style:italic">'
            . htmlspecialchars(self::NOTICE, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</p>';
    }

    /** Les $length premiers octets au plus, sans couper un caractère UTF-8. */
    private static function cut(string $value, int $length): string
    {
        return $length <= 0 ? '' : mb_strcut($value, 0, $length, 'UTF-8');
    }

    /** Comme cut(), sans laisser une balise HTML ouverte à la fin (« <a href="… »). */
    private static function cutHtml(string $html, int $length): string
    {
        $html = self::cut($html, $length);
        $open = strrpos($html, '<');
        if ($open !== false && strpos($html, '>', $open) === false) {
            $html = substr($html, 0, $open);
        }

        return $html;
    }

    /**
     * Valeur d'en-tête la plus longue (non vide).
     *
     * @return array{string, int, string}|null nom, position, valeur
     */
    private static function longestHeader(mixed $headers): ?array
    {
        if (!is_array($headers)) {
            return null;
        }

        $longest = null;
        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [] as $index => $value) {
                if (is_string($value) && $value !== '' && ($longest === null || strlen($value) > strlen($longest[2]))) {
                    $longest = [(string) $name, $index, $value];
                }
            }
        }

        return $longest;
    }
}
