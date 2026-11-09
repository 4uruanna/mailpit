<?php
/**
 * @var string $username
 * @var array{type: string, message: string}|null $flash
 * @var string $csrf
 * @var callable $e
 * @var callable $url
 */
?>
<div class="card narrow">
    <h1>Connexion</h1>
    <?php if ($flash !== null): ?>
        <div class="flash <?= $e($flash['type']) ?>" role="alert"><?= $e($flash['message']) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= $e($url('/v1/app/signin')) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label for="username">Identifiant</label>
        <input type="text" id="username" name="username" value="<?= $e($username) ?>" autocomplete="username" maxlength="255" required autofocus>
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <p><button type="submit">Se connecter</button></p>
    </form>
    <p class="muted">Toutes les 30 minutes : 3 essais par identifiant, et 10 au total depuis votre adresse.</p>
</div>
