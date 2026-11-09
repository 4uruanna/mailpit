<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailpit;

use App\Mailpit\InvalidPayloadException;
use App\Mailpit\Message;
use App\Mailpit\Part;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    /** Résumé de Mailpit (ce qu'envoyait son webhook direct, qui n'est plus accepté). */
    public static function summary(): array
    {
        return [
            'ID'          => 'VnV7f5bZqzoCs2LCh3yGNN',
            'MessageID'   => 'abc@example.com',
            'Read'        => false,
            'From'        => ['Name' => 'Alice', 'Address' => 'alice@example.com'],
            'To'          => [['Name' => '', 'Address' => 'test@test.mondomaine.fr']],
            'Cc'          => [],
            'Bcc'         => [],
            'ReplyTo'     => [],
            'Subject'     => 'Bonjour',
            'Created'     => '2026-11-08T21:15:03.123456789+01:00',
            'Username'    => '',
            'Tags'        => [],
            'Size'        => 1234,
            'Attachments' => 0,
            'Snippet'     => 'Texte…',
        ];
    }

    /** Mail complet envoyé par le relais. */
    public static function full(): array
    {
        return self::summary() + [
            'ReturnPath' => 'bounce@example.com',
            'Date'       => '2026-11-08T21:15:01+01:00',
            'Text'       => 'corps texte',
            'HTML'       => '<p>corps HTML</p>',
            'Headers'    => ['Received' => ['a', 'b'], 'X-Mailer' => ['test']],
            'Parts'      => [
                ['PartID' => '1.2', 'Kind' => 'inline', 'FileName' => 'logo.png', 'ContentType' => 'image/png', 'ContentID' => 'logo', 'Size' => 10],
                ['PartID' => '2', 'Kind' => 'attachment', 'FileName' => 'devis.pdf', 'ContentType' => 'application/pdf', 'ContentID' => '', 'Size' => 20],
            ],
        ];
    }

    public function testSummary(): void
    {
        $message = Message::fromArray(self::summary());

        self::assertSame('VnV7f5bZqzoCs2LCh3yGNN', $message->id);
        self::assertSame('abc@example.com', $message->messageId);
        self::assertSame(['name' => 'Alice', 'address' => 'alice@example.com'], $message->from);
        self::assertSame([['name' => '', 'address' => 'test@test.mondomaine.fr']], $message->to);
        self::assertSame([], $message->cc);
        self::assertSame('Bonjour', $message->subject);
        // Converti en UTC (la précision de PHP s'arrête à la microseconde).
        self::assertSame('2026-11-08 20:15:03.123456', $message->created->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $message->created->getTimezone()->getName());
        self::assertSame(1234, $message->size);
        self::assertSame(0, $message->attachments);
        self::assertSame('Texte…', $message->snippet);
        // Absents du résumé : rien n'écrase ce qui serait déjà en base.
        self::assertNull($message->text);
        self::assertNull($message->html);
        self::assertNull($message->headers);
        self::assertNull($message->date);
        self::assertSame('', $message->returnPath);
        self::assertSame([], $message->parts);
    }

    public function testFullMessage(): void
    {
        $message = Message::fromArray(self::full());

        self::assertSame('corps texte', $message->text);
        self::assertSame('<p>corps HTML</p>', $message->html);
        self::assertSame('bounce@example.com', $message->returnPath);
        self::assertSame('2026-11-08 20:15:01', $message->date?->format('Y-m-d H:i:s'));
        self::assertSame(['Received' => ['a', 'b'], 'X-Mailer' => ['test']], $message->headers);
        self::assertCount(2, $message->parts);
        self::assertContainsOnlyInstancesOf(Part::class, $message->parts);
        self::assertSame('devis.pdf', $message->part('2')?->fileName);
        self::assertNull($message->part('3'));
    }

    public function testMinimalMessage(): void
    {
        $message = Message::fromArray(['ID' => 'a', 'Created' => '2026-01-01T12:00:00Z']);

        self::assertNull($message->from);
        self::assertSame([], $message->to);
        self::assertSame([], $message->tags);
        self::assertSame('', $message->subject);
        self::assertSame(0, $message->size);
    }

    public function testNullListsAreEmpty(): void
    {
        $message = Message::fromArray(['To' => null, 'Cc' => null, 'Tags' => null, 'Parts' => null, 'From' => null] + self::summary());

        self::assertNull($message->from);
        self::assertSame([], $message->to);
        self::assertSame([], $message->cc);
        self::assertSame([], $message->tags);
        self::assertSame([], $message->parts);
    }

    public function testEmptyHeadersObject(): void
    {
        self::assertSame([], Message::fromArray(['Headers' => []] + self::summary())->headers);
    }

    public function testGoZeroDateIsIgnored(): void
    {
        self::assertNull(Message::fromArray(['Date' => '0001-01-01T00:00:00Z'] + self::summary())->date);
        self::assertNull(Message::fromArray(['Date' => ''] + self::summary())->date);
    }

    public function testTruncatedFlag(): void
    {
        // Absent (ou null) : version complète.
        self::assertFalse(Message::fromArray(self::full())->truncated);
        self::assertFalse(Message::fromArray(['Truncated' => null] + self::full())->truncated);
        self::assertFalse(Message::fromArray(['Truncated' => false] + self::full())->truncated);
        // Version raccourcie par le relais (mail trop volumineux pour l'API).
        self::assertTrue(Message::fromArray(['Truncated' => true] + self::full())->truncated);
    }

    public function testSizeAsFloatIsAccepted(): void
    {
        // json_decode renvoie un float au-delà de PHP_INT_MAX, ou pour « 1234.0 ».
        self::assertSame(1234, Message::fromArray(['Size' => 1234.0] + self::summary())->size);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidMessages(): iterable
    {
        $summary = self::summary();
        $part = ['PartID' => '2', 'Kind' => 'attachment', 'Size' => 1];

        yield 'liste JSON'                => [[$summary], 'le corps doit être un objet JSON'];
        yield 'texte'                     => ['bonjour', 'le corps doit être un objet JSON'];
        yield 'null'                      => [null, 'le corps doit être un objet JSON'];
        yield 'ID absent'                 => [array_diff_key($summary, ['ID' => 0]), 'champ « ID » manquant ou invalide'];
        yield 'ID numérique'              => [['ID' => 12] + $summary, 'champ « ID » manquant ou invalide'];
        yield 'ID avec « / »'             => [['ID' => '../etc'] + $summary, 'champ « ID » manquant ou invalide'];
        yield 'ID trop long'              => [['ID' => str_repeat('a', 65)] + $summary, 'champ « ID » manquant ou invalide'];
        yield 'Created absent'            => [array_diff_key($summary, ['Created' => 0]), 'champ « Created » manquant'];
        yield 'Created vide'              => [['Created' => ''] + $summary, 'champ « Created » manquant'];
        yield 'Created invalide'          => [['Created' => 'hier'] + $summary, 'champ « Created » : date invalide'];
        yield 'Date invalide'             => [['Date' => 'pas une date'] + $summary, 'champ « Date » : date invalide'];
        yield 'Subject pas un texte'      => [['Subject' => ['a']] + $summary, 'champ « Subject » : texte attendu'];
        yield 'Text pas un texte'         => [['Text' => 12] + $summary, 'champ « Text » : texte attendu'];
        yield 'Truncated en texte'        => [['Truncated' => 'true'] + $summary, 'champ « Truncated » : booléen attendu'];
        yield 'Truncated en nombre'       => [['Truncated' => 1] + $summary, 'champ « Truncated » : booléen attendu'];
        yield 'Size négative'             => [['Size' => -1] + $summary, 'champ « Size » : entier positif attendu'];
        yield 'Attachments en texte'      => [['Attachments' => '2'] + $summary, 'champ « Attachments » : entier positif attendu'];
        yield 'From pas un objet'         => [['From' => 'alice@example.com'] + $summary, 'champ « From » : adresse invalide'];
        yield 'From.Address pas un texte' => [['From' => ['Address' => 12]] + $summary, 'champ « From » : adresse invalide'];
        yield 'To pas une liste'          => [['To' => ['Address' => 'a@b.fr']] + $summary, 'champ « To » : liste attendue'];
        yield 'Cc avec un texte'          => [['Cc' => ['a@b.fr']] + $summary, 'champ « Cc » : adresse invalide'];
        yield 'Tags pas une liste'        => [['Tags' => 'a'] + $summary, 'champ « Tags » : liste attendue'];
        yield 'Tags avec un nombre'       => [['Tags' => ['a', 1]] + $summary, 'champ « Tags » : liste de textes attendue'];
        yield 'Headers liste'             => [['Headers' => ['a']] + $summary, 'champ « Headers » : objet attendu'];
        yield 'Headers valeur texte'      => [['Headers' => ['X-A' => 'b']] + $summary, 'champ « Headers.X-A » : liste attendue'];
        yield 'Headers valeur nombre'     => [['Headers' => ['X-A' => [1]]] + $summary, 'champ « Headers.X-A » : liste de textes attendue'];
        yield 'Parts pas une liste'       => [['Parts' => ['a' => $part]] + $summary, 'champ « Parts » : liste attendue'];
        yield 'Parts invalide'            => [['Parts' => [$part, ['Kind' => 'x']]] + $summary, 'champ « Parts[1] » : « PartID » manquant ou invalide'];
        yield 'Parts en double'           => [['Parts' => [$part, $part]] + $summary, 'champ « Parts » : PartID « 2 » en double'];
        yield 'Parts trop nombreuses'     => [
            ['Parts' => array_map(static fn (int $i): array => ['PartID' => (string) $i] + $part, range(1, 1001))] + $summary,
            'champ « Parts » : 1000 pièces au maximum',
        ];
    }

    #[DataProvider('invalidMessages')]
    public function testInvalidMessage(mixed $data, string $message): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage($message);

        Message::fromArray($data);
    }
}
