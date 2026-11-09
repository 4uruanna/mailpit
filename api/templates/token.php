<?php
/**
 * @var array{id: int, name: string, hint: string, created_at: DateTimeImmutable, last_used_at: ?DateTimeImmutable} $token
 * @var array<string, int> $addresses adresse => nombre de mails
 * @var string|null $plain jeton en clair, seulement juste après sa création
 * @var string $origin https://api.mondomaine.fr
 * @var string $csrf
 * @var callable $e
 * @var callable $url
 * @var callable $date
 */
?>
<p><a href="<?= $e($url('/v1/app/tokens')) ?>">← Tokens</a></p>
<h1><?= $e($token['name']) ?></h1>

<?php if ($plain !== null): ?>
<div class="card">
    <strong>Copiez ce jeton maintenant : il ne sera plus jamais affiché.</strong>
    <code class="code secret"><?= $e($plain) ?></code>
    <span class="muted">À envoyer dans l'en-tête <code>Authorization: Bearer …</code> des appels à l'API.</span>
</div>
<?php endif; ?>

<h2>Informations</h2>
<div class="card">
    <dl class="meta">
        <dt>Jeton</dt><dd><code><?= $e($token['hint']) ?>…</code></dd>
        <dt>Créé le</dt><dd><?= $e($date($token['created_at'])) ?></dd>
        <dt>Dernière utilisation</dt><dd><?= $e($date($token['last_used_at'])) ?></dd>
    </dl>
</div>

<h2>Adresses associées</h2>
<div class="card">
    <form method="post" action="<?= $e($url('/v1/app/tokens/' . $token['id'] . '/addresses')) ?>" class="row">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <div class="grow">
            <label for="address">Ajouter une adresse</label>
            <input type="email" id="address" name="address" maxlength="320" placeholder="essai@test.mondomaine.fr" required>
        </div>
        <button type="submit">Associer</button>
    </form>
</div>

<?php if ($addresses === []): ?>
    <p class="muted">Aucune adresse : ce jeton ne voit encore aucun mail.</p>
<?php else: ?>
<div class="table-wrap" style="margin-top: 16px">
    <table>
        <thead><tr><th>Adresse</th><th>Mails reçus</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($addresses as $address => $count): ?>
            <tr>
                <td><code><?= $e($address) ?></code></td>
                <td><?= $e($count) ?></td>
                <td class="nowrap">
                    <form class="inline" method="post" action="<?= $e($url('/v1/app/tokens/' . $token['id'] . '/addresses/delete')) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="address" value="<?= $e($address) ?>">
                        <button type="submit" class="danger">Retirer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<h2>Utilisation</h2>
<div class="card">
    <p class="muted">Les 5 derniers mails d'une adresse : résumés seulement (sujet, extrait, pièces jointes…), sans le corps.</p>
    <code class="code secret">curl -s -H 'Authorization: Bearer <?= $e($plain ?? $token['hint'] . '…') ?>' '<?= $e($origin . $url('/v1/messages')) ?>?address=<?= $e(array_key_first($addresses) ?? 'adresse@exemple.fr') ?>'</code>
    <p class="muted">Un mail complet (texte, HTML, en-têtes), avec l'<code>id</code> lu dans la liste.</p>
    <code class="code secret">curl -s -H 'Authorization: Bearer <?= $e($plain ?? $token['hint'] . '…') ?>' '<?= $e($origin . $url('/v1/messages/')) ?>ID_DU_MAIL'</code>
</div>
