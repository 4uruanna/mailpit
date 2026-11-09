<?php

declare(strict_types=1);

namespace App\Dashboard;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Graphique de la latence sur 24 heures (page « Statut »), en SVG généré côté serveur : la CSP du
 * Dashboard interdit les scripts en ligne, et aucune librairie externe n'est chargée.
 *
 * Un créneau de 5 minutes = une colonne : barre pleine jusqu'à la médiane, barre claire jusqu'au
 * maximum, bande rouge sur toute la hauteur si aucun ping n'a été reçu (déconnexion). Les couleurs
 * sont des classes CSS (« chart » dans templates/layout.php) : le graphique suit le mode sombre.
 * Accessible : role="img", <title> et <desc> (résumé en texte), et une infobulle par créneau.
 */
final readonly class LatencyChart
{
    private const int WIDTH = 960;
    private const int HEIGHT = 240;
    private const int LEFT = 64;
    private const int RIGHT = 8;
    private const int TOP = 10;
    private const int BOTTOM = 26;

    /** Une graduation de l'axe du temps toutes les 3 heures. */
    private const int TICK_HOURS = 3;

    public function __construct(private DateTimeZone $timezone)
    {
    }

    public function svg(HealthReport $report): string
    {
        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $baseline = self::TOP + $plotHeight;
        $step = $plotWidth / HealthReport::SLOTS;
        $scale = $report->scale();
        $y = static fn (int $ms): float => $baseline - $plotHeight * min($ms, $scale) / $scale;

        $parts = [];
        $parts[] = sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-labelledby="latency-title latency-desc" xmlns="http://www.w3.org/2000/svg">',
            self::WIDTH,
            self::HEIGHT,
        );
        $parts[] = '<title id="latency-title">Latence du relais sur 24 heures, par créneaux de 5 minutes</title>';
        $parts[] = '<desc id="latency-desc">' . self::e($this->summary($report)) . '</desc>';

        // Axe des latences : 0, la moitié et le maximum.
        foreach ([0, intdiv($scale, 2), $scale] as $value) {
            $parts[] = sprintf(
                '<line class="grid" x1="%d" y1="%s" x2="%d" y2="%s"/><text class="axis" x="%d" y="%s" text-anchor="end" dominant-baseline="middle">%s</text>',
                self::LEFT,
                self::n($y($value)),
                self::WIDTH - self::RIGHT,
                self::n($y($value)),
                self::LEFT - 6,
                self::n($y($value)),
                self::e(self::ms($value)),
            );
        }

        foreach ($report->slots as $i => $slot) {
            $x = self::LEFT + $i * $step;
            $tooltip = self::e($this->tooltip($slot));
            $rects = match (true) {
                $slot['state'] === 'down' => sprintf(
                    '<rect class="down" x="%s" y="%d" width="%s" height="%d"/>',
                    self::n($x),
                    self::TOP,
                    self::n($step),
                    $plotHeight,
                ),
                $slot['state'] === 'up' && $slot['max'] !== null => sprintf(
                    '<rect class="peak" x="%1$s" y="%2$s" width="%3$s" height="%4$s"/><rect class="bar" x="%1$s" y="%5$s" width="%3$s" height="%6$s"/>',
                    self::n($x),
                    self::n($y($slot['max'])),
                    self::n($step),
                    self::n(max(1.0, $baseline - $y($slot['max']))),
                    self::n($y((int) $slot['median'])),
                    self::n(max(1.0, $baseline - $y((int) $slot['median']))),
                ),
                // Pings reçus, mais sans mesure de latence (premier ping, ou ping précédent en échec).
                $slot['state'] === 'up' => sprintf(
                    '<rect class="unknown" x="%s" y="%d" width="%s" height="3"/>',
                    self::n($x),
                    $baseline - 3,
                    self::n($step),
                ),
                default => null,
            };
            if ($rects !== null) {
                $parts[] = '<g><title>' . $tooltip . '</title>' . $rects . '</g>';
            }

            // Graduation toutes les 3 heures (heure du Dashboard).
            $local = $slot['start']->setTimezone($this->timezone);
            if ($local->format('i') === '00' && (int) $local->format('G') % self::TICK_HOURS === 0) {
                $parts[] = sprintf(
                    '<line class="grid" x1="%1$s" y1="%2$d" x2="%1$s" y2="%3$d"/><text class="axis" x="%1$s" y="%4$d" text-anchor="middle">%5$s</text>',
                    self::n($x),
                    $baseline,
                    $baseline + 4,
                    self::HEIGHT - 6,
                    $local->format('H:i'),
                );
            }
        }

        $parts[] = sprintf('<line class="baseline" x1="%d" y1="%d" x2="%d" y2="%d"/>', self::LEFT, $baseline, self::WIDTH - self::RIGHT, $baseline);
        $parts[] = '</svg>';

        return implode("\n", $parts);
    }

    /** Résumé en texte du graphique (<desc>, lu par les lecteurs d'écran). */
    public function summary(HealthReport $report): string
    {
        $counts = array_count_values(array_column($report->slots, 'state'));
        if (($counts['up'] ?? 0) + ($counts['down'] ?? 0) === 0) {
            return 'Aucun ping reçu sur les dernières 24 heures.';
        }

        $text = sprintf(
            '%d créneaux de 5 minutes avec au moins un ping, %d sans aucun ping (déconnexion).',
            $counts['up'] ?? 0,
            $counts['down'] ?? 0,
        );
        if ($report->medianLatency !== null) {
            $text .= sprintf(' Latence médiane %s, maximum %s.', self::ms($report->medianLatency), self::ms((int) $report->maxLatency));
        }

        return $text;
    }

    /** @param array{start: DateTimeImmutable, end: DateTimeImmutable, state: string, pings: int, median: ?int, max: ?int} $slot */
    private function tooltip(array $slot): string
    {
        $period = sprintf('%s–%s', $slot['start']->setTimezone($this->timezone)->format('H:i'), $slot['end']->setTimezone($this->timezone)->format('H:i'));

        return match (true) {
            $slot['state'] === 'down' => $period . ' : aucun ping (déconnexion)',
            $slot['max'] !== null     => sprintf('%s : médiane %s, max %s (%d ping(s))', $period, self::ms((int) $slot['median']), self::ms($slot['max']), $slot['pings']),
            default                   => sprintf('%s : %d ping(s), latence inconnue', $period, $slot['pings']),
        };
    }

    /** « 85 ms », « 1,2 s », « 20 s ». */
    public static function ms(int $ms): string
    {
        return match (true) {
            $ms < 1000   => $ms . ' ms',
            $ms < 10_000 => number_format($ms / 1000, 1, ',', ' ') . ' s',
            default      => number_format($ms / 1000, 0, ',', ' ') . ' s',
        };
    }

    /** Nombre pour un attribut SVG (2 décimales au plus, point décimal). */
    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
