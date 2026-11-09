<?php
/**
 * @var list<array{id: int, name: string, hint: string, created_at: DateTimeImmutable, last_used_at: ?DateTimeImmutable, addresses: int}> $tokens
 * @var string $csrf
 * @var callable $e
 * @var callable $url
 * @var callable $date
 */
?>
<h1>Tokens</h1>
<p class="muted">Un jeton permet à un développeur de lire et supprimer, via l'API, les mails des adresses qui lui sont associées.</p>

<div class="card">
    <form method="post" action="<?= $e($url('/v1/app/tokens')) ?>" class="row">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <div class="grow">
            <label for="name">Nouveau jeton</label>
            <input type="text" id="name" name="name" maxlength="255" placeholder="Nom : projet, développeur…" required>
        </div>
        <button type="submit">Créer</button>
    </form>
</div>

<h2>Jetons existants</h2>
<?php if ($tokens === []): ?>
    <div class="card empty">Aucun jeton pour l'instant.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Nom</th><th>Jeton</th><th>Adresses</th><th>Créé le</th><th>Dernière utilisation</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $token): ?>
            <tr>
                <td><a href="<?= $e($url('/v1/app/tokens/' . $token['id'])) ?>"><?= $e($token['name']) ?></a></td>
                <td><code><?= $e($token['hint']) ?>…</code></td>
                <td><?= $e($token['addresses']) ?></td>
                <td class="nowrap"><?= $e($date($token['created_at'])) ?></td>
                <td class="nowrap"><?= $e($date($token['last_used_at'])) ?></td>
                <td class="nowrap">
                    <form class="inline" method="post" action="<?= $e($url('/v1/app/tokens/' . $token['id'] . '/delete')) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="danger">Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
