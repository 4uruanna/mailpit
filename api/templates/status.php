<?php
/**
 * Page « Statut » : connexion du relais sur les dernières 24 heures.
 *
 * @var App\Dashboard\HealthReport $report
 * @var string $chart graphique SVG (LatencyChart, déjà échappé)
 * @var callable $e
 * @var callable $date
 */

use App\Dashboard\HealthReport;
use App\Dashboard\LatencyChart;

$since = $report->secondsSinceLastPing();
$count = static fn (?int $value): string => $value === null ? 'inconnu (Mailpit injoignable)' : (string) $value;
?>
<h1>Statut</h1>
<p class="muted">Le relais envoie un ping à l'API toutes les 30 secondes. Sans ping depuis plus de 90 secondes, il est considéré comme déconnecté. Page actualisée chaque minute.</p>

<div class="card status-card">
<?php if ($report->state === 'connected'): ?>
    <p class="status"><span class="badge ok">Connecté</span> <span class="muted">dernier ping il y a <?= $e(HealthReport::duration((int) $since)) ?></span></p>
<?php elseif ($report->state === 'disconnected'): ?>
    <p class="status"><span class="badge down">Déconnecté depuis <?= $e(HealthReport::duration((int) $since)) ?></span> <span class="muted">dernier ping le <?= $e($date($report->lastSeen)) ?></span></p>
<?php else: ?>
    <p class="status"><span class="badge none">Aucun ping reçu</span></p>
    <p class="muted">Le relais n'a envoyé aucun ping depuis 48 heures. Vérifiez qu'il tourne et qu'il est à jour (<code>journalctl -u mailpit-relay</code>), et que <code>RELAY_HEARTBEAT_INTERVAL</code> ne vaut pas <code>0</code>.</p>
<?php endif; ?>

<?php if ($report->last !== null): ?>
    <dl class="meta">
        <dt>Dernier ping</dt>
        <dd><?= $e($date($report->last['received_at'])) ?><?= $report->last['label'] !== null ? ' · instance « ' . $e($report->last['label']) . ' »' : '' ?></dd>
        <dt>Dernière latence</dt>
        <dd><?= $report->last['latency_ms'] === null ? 'inconnue' : $e(LatencyChart::ms($report->last['latency_ms'])) ?></dd>
        <dt>Latence sur 24 h</dt>
        <dd><?= $report->medianLatency === null ? '—' : 'médiane ' . $e(LatencyChart::ms($report->medianLatency)) . ', maximum ' . $e(LatencyChart::ms((int) $report->maxLatency)) ?></dd>
        <dt>Mails en attente</dt>
        <dd><?= $e($count($report->last['pending'])) ?></dd>
        <dt>Mails refusés</dt>
        <dd><?= $e($count($report->last['failed'])) ?><?= ($report->last['failed'] ?? 0) > 0 ? ' <span class="muted">(liste : <code>bin/status.php</code> du relais)</span>' : '' ?></dd>
        <dt>Disponibilité</dt>
        <dd>
            <?= $report->availability === null ? '—' : $e(number_format($report->availability, 1, ',', ' ') . ' %') ?>
            <span class="muted"><?= $report->monitoredSince !== null ? 'depuis le premier ping, le ' . $e($date($report->monitoredSince)) : 'sur 24 heures' ?> (créneaux de 5 minutes ayant reçu au moins un ping)</span>
        </dd>
        <dt>Pings reçus</dt>
        <dd><?= $e($report->pings) ?> <span class="muted">sur 24 heures</span></dd>
    </dl>
<?php elseif ($report->lastSeen !== null): ?>
    <p class="muted">Aucun ping sur les dernières 24 heures.</p>
<?php endif; ?>
</div>

<h2>Latence sur 24 heures</h2>
<div class="card">
    <?= $chart ?>
    <p class="legend muted">
        <span><i class="swatch bar"></i>médiane</span>
        <span><i class="swatch peak"></i>maximum</span>
        <span><i class="swatch down"></i>aucun ping (déconnexion)</span>
        <span><i class="swatch unknown"></i>connecté, latence inconnue</span>
        <span>par créneaux de 5 minutes, heure du Dashboard</span>
    </p>
</div>

<h2>Coupures</h2>
<?php if ($report->outages === []): ?>
    <div class="card empty">Aucune coupure sur les dernières 24 heures.</div>
<?php else: ?>
<p class="muted"><?= count($report->outages) ?> coupure(s) de plus de 90 secondes sans ping, <?= $e(HealthReport::duration($report->downtime)) ?> au total sur 24 heures.</p>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Début (dernier ping)</th><th>Fin (ping suivant)</th><th>Durée</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report->outages as $outage): ?>
            <tr>
                <td class="nowrap"><?= $e($date($outage['start'])) ?></td>
                <td class="nowrap"><?= $outage['end'] === null ? '<span class="badge down">en cours</span>' : $e($date($outage['end'])) ?></td>
                <td class="nowrap"><?= $e(HealthReport::duration($outage['seconds'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
