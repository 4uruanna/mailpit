<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relay\MessageTruncator;

final class MessageTruncatorTest extends TestCase
{
    private const string TEXT_NOTICE = "\n\n[… contenu tronqué par le relais : mail trop volumineux pour l'API]";
    private const string HTML_NOTICE = "\n" . '<p style="margin-top:2em;color:#666;font-style:italic">[… contenu tronqué par le relais : mail trop volumineux pour l&apos;API]</p>';

    /** @return iterable<string, array{int, ?int, int}> */
    public static function budgets(): iterable
    {
        yield 'limite annoncée'                => [100_000, 40_000, 40_000 - 1024];
        yield 'sans limite : la moitié'        => [100_000, null, 50_000 - 1024];
        yield 'limite pas plus petite'         => [100_000, 100_000, 50_000 - 1024];
        yield 'limite plus grande (incohérent)' => [100_000, 200_000, 50_000 - 1024];
        yield 'jamais négatif'                 => [1000, 500, 0];
    }

    #[DataProvider('budgets')]
    public function testBudget(int $sent, ?int $limit, int $expected): void
    {
        self::assertSame($expected, MessageTruncator::budget($sent, $limit));
    }

    public function testReportedLimit(): void
    {
        self::assertSame(1000, MessageTruncator::reportedLimit('{"error":"trop gros","max_body_size":1000}'));
        self::assertNull(MessageTruncator::reportedLimit('{"error":"trop gros"}'));
        self::assertNull(MessageTruncator::reportedLimit('{"max_body_size":"1000"}'));
        self::assertNull(MessageTruncator::reportedLimit('{"max_body_size":0}'));
        self::assertNull(MessageTruncator::reportedLimit('<html>413 Request Entity Too Large</html>'));
    }

    public function testHtmlIsCutFirst(): void
    {
        $message = self::message(str_repeat('<p>Été 🌍</p>', 1000), 'Bonjour');
        $budget = 5000;

        $truncated = MessageTruncator::truncate($message, $budget);

        self::assertNotNull($truncated);
        self::assertLessThanOrEqual($budget, strlen(MessageTruncator::encode($truncated)));
        self::assertTrue($truncated['Truncated']);
        self::assertSame('Bonjour' . self::TEXT_NOTICE, $truncated['Text']);
        self::assertStringEndsWith(self::HTML_NOTICE, $truncated['HTML']);
        self::assertTrue(mb_check_encoding($truncated['HTML'], 'UTF-8'));
        // Coupé entre deux balises : jamais « <p>Ét » suivi de la note dans une balise ouverte.
        self::assertMatchesRegularExpression('#^(<p>Été 🌍</p>)+(<p>[^<]*)?' . preg_quote(self::HTML_NOTICE, '#') . '$#u', $truncated['HTML']);
        self::assertSame($message['Headers'], $truncated['Headers']);
        self::assertSame($message['Subject'], $truncated['Subject']);
        // Le mail d'origine n'est pas modifié.
        self::assertArrayNotHasKey('Truncated', $message);
    }

    public function testUnfinishedTagIsRemoved(): void
    {
        $html = '<p>début</p><a href="https://example.com/' . str_repeat('x', 5000) . '">lien</a>';
        $budget = strlen(MessageTruncator::encode(self::message($html, ''))) - 2000;

        $truncated = MessageTruncator::truncate(self::message($html, ''), $budget);

        self::assertSame('<p>début</p>' . self::HTML_NOTICE, $truncated['HTML']);
    }

    public function testTextIsCutWhenHtmlIsNotEnough(): void
    {
        $message = self::message(str_repeat('<b>x</b>', 100), str_repeat("Ligne d'été ☀️\n", 1000));

        $truncated = MessageTruncator::truncate($message, 3000);

        self::assertLessThanOrEqual(3000, strlen(MessageTruncator::encode($truncated)));
        self::assertSame(self::HTML_NOTICE, $truncated['HTML']);
        self::assertStringStartsWith("Ligne d'été ☀️\nLigne", $truncated['Text']);
        self::assertStringEndsWith(self::TEXT_NOTICE, $truncated['Text']);
        self::assertTrue(mb_check_encoding($truncated['Text'], 'UTF-8'));
        self::assertSame($message['Headers'], $truncated['Headers']);
    }

    public function testTextOnlyMessageGetsNoHtml(): void
    {
        $truncated = MessageTruncator::truncate(self::message('', str_repeat('a', 5000)), 2000);

        self::assertSame('', $truncated['HTML']);
        self::assertStringEndsWith(self::TEXT_NOTICE, $truncated['Text']);
    }

    public function testLongestHeadersAreCutLast(): void
    {
        $message = self::message('<p>court</p>', 'court');
        $message['Headers'] = [
            'Subject'          => ['Sujet'],
            'X-Long'           => [str_repeat('é', 3000)],
            'X-Moins-Long'     => [str_repeat('b', 1000)],
            'DKIM-Signature'   => ['v=1; a=rsa-sha256'],
        ];

        $truncated = MessageTruncator::truncate($message, 2500);

        self::assertNotNull($truncated);
        self::assertLessThanOrEqual(2500, strlen(MessageTruncator::encode($truncated)));
        // Corps vides : il ne reste que la note.
        self::assertSame(ltrim(self::TEXT_NOTICE), $truncated['Text']);
        self::assertSame(self::HTML_NOTICE, $truncated['HTML']);
        self::assertSame(['Sujet'], $truncated['Headers']['Subject']);
        self::assertSame(['v=1; a=rsa-sha256'], $truncated['Headers']['DKIM-Signature']);
        $long = $truncated['Headers']['X-Long'][0];
        self::assertTrue(mb_check_encoding($long, 'UTF-8'));
        self::assertStringEndsWith('é…', $long);
        self::assertLessThan(6000, strlen($long));
    }

    public function testImpossibleWhenOnlyTheSummaryIsTooLarge(): void
    {
        $message = self::message('<p>HTML</p>', 'texte');
        $message['To'] = array_fill(0, 200, ['Name' => 'Destinataire', 'Address' => 'quelqu-un@example.com']);

        self::assertNull(MessageTruncator::truncate($message, 2000));
    }

    /** @return array<string, mixed> */
    private static function message(string $html, string $text): array
    {
        return [
            'ID'      => 'm1',
            'Subject' => 'Sujet',
            'To'      => [['Name' => '', 'Address' => 'test@example.com']],
            'Text'    => $text,
            'HTML'    => $html,
            'Headers' => ['Subject' => ['Sujet'], 'Received' => ['from a', 'from b']],
            'Parts'   => [],
        ];
    }
}
