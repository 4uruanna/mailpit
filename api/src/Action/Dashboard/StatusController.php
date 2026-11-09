<?php

declare(strict_types=1);

namespace App\Action\Dashboard;

use App\Dashboard\HealthReport;
use App\Dashboard\LatencyChart;
use App\Dashboard\View;
use App\Persistence\HealthRepository;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/app/status : connexion du relais sur les dernières 24 heures, d'après ses pings de santé
 * (état, dernier ping, latence, mails en attente et refusés, disponibilité, graphique, coupures).
 * La page se recharge toute seule chaque minute (balise meta, sans JavaScript), avec ?auto=1 :
 * ces rechargements ne prolongent pas la session (DashboardMiddleware).
 */
final readonly class StatusController
{
    public function __construct(
        private HealthRepository $health,
        private View $view,
        private LatencyChart $chart,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = HealthReport::windowStart($now);
        $report = new HealthReport($this->health->since($from), $this->health->lastBefore($from), $now);

        return $this->view->render('status', [
            'report' => $report,
            'chart'  => $this->chart->svg($report),
        ], title: 'Statut');
    }
}
