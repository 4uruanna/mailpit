<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\HealthReport;
use App\Dashboard\LatencyChart;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Graphique SVG de la page « Statut » : barres, déconnexions, axes, accessibilité.
 */
final class LatencyChartTest extends TestCase
{
    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private static function xpath(string $svg): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($svg), 'SVG bien formé');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        return $xpath;
    }

    public function testBarsGapsAndAxes(): void
    {
        // Pings de 10:00 à 11:00 (UTC), avec une latence de 120 ms puis un pic à 450 ms ; plus rien ensuite.
        $pings = [];
        for ($t = self::at('2026-11-09T10:00:00Z'); $t <= self::at('2026-11-09T11:00:00Z'); $t = $t->modify('+30 seconds')) {
            $pings[] = ['received_at' => $t, 'latency_ms' => $t->format('H:i:s') === '10:30:00' ? 450 : 120, 'pending' => 0, 'failed' => 0, 'label' => null];
        }
        $report = new HealthReport($pings, null, self::at('2026-11-09T12:02:30Z'));
        $chart = new LatencyChart(new DateTimeZone('Europe/Paris'));
        $svg = $chart->svg($report);
        $xpath = self::xpath($svg);

        // Accessible : role="img", titre et résumé.
        self::assertSame('img', $xpath->evaluate('string(/s:svg/@role)'));
        self::assertSame('latency-title latency-desc', $xpath->evaluate('string(/s:svg/@aria-labelledby)'));
        self::assertStringContainsString('Latence du relais sur 24 heures', $xpath->evaluate('string(/s:svg/s:title)'));
        self::assertSame(
            '13 créneaux de 5 minutes avec au moins un ping, 12 sans aucun ping (déconnexion). Latence médiane 120 ms, maximum 450 ms.',
            $xpath->evaluate('string(/s:svg/s:desc)'),
        );

        // 13 créneaux avec latence (médiane + maximum), 12 déconnexions, rien avant le premier ping.
        self::assertSame(13, $xpath->query('//s:rect[@class="bar"]')->length);
        self::assertSame(13, $xpath->query('//s:rect[@class="peak"]')->length);
        self::assertSame(12, $xpath->query('//s:rect[@class="down"]')->length);
        self::assertSame(0, $xpath->query('//s:rect[@class="unknown"]')->length);

        // Infobulles à l'heure du Dashboard (UTC+1 en novembre).
        $titles = array_map(static fn (\DOMNode $node): string => $node->textContent, iterator_to_array($xpath->query('//s:g/s:title')));
        self::assertContains('11:30–11:35 : médiane 120 ms, max 450 ms (10 ping(s))', $titles);
        self::assertContains('12:05–12:10 : aucun ping (déconnexion)', $titles);
        self::assertContains('13:00–13:02 : aucun ping (déconnexion)', $titles, 'créneau en cours, jusqu\'à maintenant');

        // Axe des latences jusqu'à 500 ms ; graduations du temps toutes les 3 heures (heure de Paris).
        $labels = array_map(static fn (\DOMNode $node): string => $node->textContent, iterator_to_array($xpath->query('//s:text')));
        self::assertSame(['0 ms', '250 ms', '500 ms', '15:00', '18:00', '21:00', '00:00', '03:00', '06:00', '09:00', '12:00'], $labels);
    }

    public function testPingsWithoutLatencyAndEmptyChart(): void
    {
        $chart = new LatencyChart(new DateTimeZone('UTC'));
        $ping = ['received_at' => self::at('2026-11-09T12:00:10Z'), 'latency_ms' => null, 'pending' => null, 'failed' => null, 'label' => null];

        $xpath = self::xpath($chart->svg(new HealthReport([$ping], null, self::at('2026-11-09T12:02:30Z'))));
        self::assertSame(1, $xpath->query('//s:rect[@class="unknown"]')->length);
        self::assertSame(0, $xpath->query('//s:rect[@class="bar"]')->length);
        self::assertSame('12:00–12:02 : 1 ping(s), latence inconnue', $xpath->evaluate('string(//s:g/s:title)'));

        $xpath = self::xpath($chart->svg(new HealthReport([], null, self::at('2026-11-09T12:02:30Z'))));
        self::assertSame(0, $xpath->query('//s:g')->length);
        self::assertSame('Aucun ping reçu sur les dernières 24 heures.', $xpath->evaluate('string(/s:svg/s:desc)'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function latencies(): iterable
    {
        yield 'millisecondes'  => [85, '85 ms'];
        yield 'secondes'       => [1234, '1,2 s'];
        yield 'au-delà de 10 s' => [20400, '20 s'];
    }

    #[DataProvider('latencies')]
    public function testMs(int $ms, string $expected): void
    {
        self::assertSame($expected, LatencyChart::ms($ms));
    }
}
