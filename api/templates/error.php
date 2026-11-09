<?php
/**
 * Page d'erreur du Dashboard (404, 405, 500…), rendue par App\Dashboard\ErrorPage.
 *
 * @var int $status code HTTP
 * @var string $heading titre (« Page introuvable », « Erreur interne »…)
 * @var string $message explication (« Mail inconnu. »…)
 * @var string|null $details détail de l'exception, seulement avec 'debug' => true
 * @var callable $e
 * @var callable $url
 */
?>
<div class="card narrow">
    <p class="muted">Erreur <?= $e($status) ?></p>
    <h1><?= $e($heading) ?></h1>
    <p><?= $e($message) ?></p>
    <p><a class="button" href="<?= $e($url('/v1/app/messages')) ?>">Retour aux mails</a></p>
</div>
<?php if ($details !== null): ?>
<details class="block" open>
    <summary>Détail de l'erreur (debug)</summary>
    <pre class="mail-text"><?= $e($details) ?></pre>
</details>
<?php endif; ?>
