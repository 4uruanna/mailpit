<?php
/**
 * @var array<string, mixed> $message mail complet (MessageRepository::find)
 * @var App\Dashboard\MessageFilter $filter filtres de la liste d'où l'on vient
 * @var bool $remoteImages afficher les images distantes du HTML
 * @var callable $e
 * @var callable $url
 * @var callable $date
 * @var callable $bytes
 * @var string $csrf
 */

$addresses = static fn (array $list): string => implode(', ', array_map(
    static fn (array $a): string => ($a['name'] ?? '') !== '' ? sprintf('%s <%s>', $a['name'], $a['address'] ?? '') : (string) ($a['address'] ?? ''),
    $list,
));
$base = '/v1/app/messages/' . rawurlencode($message['id']);
$back = $url('/v1/app/messages' . $filter->query());
$attachments = array_values(array_filter($message['parts'], static fn (array $p): bool => $p['kind'] === 'attachment'));
$inline = array_values(array_filter($message['parts'], static fn (array $p): bool => $p['kind'] === 'inline'));
$hasHtml = is_string($message['html']) && $message['html'] !== '';
$hasText = is_string($message['text']) && $message['text'] !== '';
$statusLabel = ['pending' => 'pas encore reçue', 'too_large' => 'trop volumineuse, non enregistrée'];
?>
<p><a href="<?= $e($back) ?>">← Messages</a></p>
<div class="title-row">
    <h1><?= $e($message['subject'] !== '' ? $message['subject'] : '(sans sujet)') ?></h1>
    <form method="post" action="<?= $e($url($base . '/delete')) ?>"
          data-confirm="Supprimer définitivement ce mail et ses pièces jointes ? (Il reste dans Mailpit.)">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="back" value="<?= $e($filter->query()) ?>">
        <button type="submit" class="danger">Supprimer ce mail</button>
    </form>
</div>

<div class="card">
    <dl class="meta">
        <dt>De</dt><dd><?= $message['from'] === null ? '—' : $e($addresses([$message['from']])) ?></dd>
        <dt>À</dt><dd><?= $e($addresses($message['to']) ?: '—') ?></dd>
        <?php if ($message['cc'] !== []): ?><dt>Cc</dt><dd><?= $e($addresses($message['cc'])) ?></dd><?php endif; ?>
        <?php if ($message['bcc'] !== []): ?><dt>Bcc</dt><dd><?= $e($addresses($message['bcc'])) ?></dd><?php endif; ?>
        <?php if ($message['reply_to'] !== []): ?><dt>Répondre à</dt><dd><?= $e($addresses($message['reply_to'])) ?></dd><?php endif; ?>
        <dt>Date du mail</dt><dd><?= $e($date($message['date'])) ?></dd>
        <dt>Reçu par Mailpit</dt><dd><?= $e($date($message['created_at'])) ?></dd>
        <dt>Reçu par l'API</dt><dd><?= $e($date($message['received_at'])) ?></dd>
        <dt>Taille</dt><dd><?= $e($bytes($message['size'])) ?></dd>
        <?php if ($message['tags'] !== []): ?>
            <dt>Tags</dt><dd><?php foreach ($message['tags'] as $tag): ?><span class="tag"><?= $e($tag) ?></span> <?php endforeach; ?></dd>
        <?php endif; ?>
        <dt>ID Mailpit</dt><dd><code><?= $e($message['id']) ?></code></dd>
        <?php if ($message['message_id'] !== ''): ?><dt>Message-ID</dt><dd><code><?= $e($message['message_id']) ?></code></dd><?php endif; ?>
    </dl>
</div>

<?php if ($attachments !== [] || $inline !== []): ?>
<h2>Pièces jointes</h2>
<div class="table-wrap">
    <table>
        <thead><tr><th>Fichier</th><th>Type</th><th>Taille</th><th></th></tr></thead>
        <tbody>
        <?php foreach ([...$attachments, ...$inline] as $part): ?>
            <?php $name = $part['file_name'] !== '' ? $part['file_name'] : 'piece-' . $part['part_id']; ?>
            <tr>
                <td>
                    <?php if ($part['status'] === 'stored'): ?>
                        <a href="<?= $e($url($base . '/parts/' . $part['part_id'])) ?>" download><?= $e($name) ?></a>
                    <?php else: ?>
                        <?= $e($name) ?>
                    <?php endif; ?>
                    <?php if ($part['kind'] === 'inline'): ?><span class="tag">image intégrée</span><?php endif; ?>
                </td>
                <td><span class="muted"><?= $e($part['content_type'] !== '' ? $part['content_type'] : '—') ?></span></td>
                <td class="nowrap"><?= $e($bytes($part['size'])) ?></td>
                <td class="nowrap">
                    <?php if ($part['status'] === 'stored'): ?>
                        <a class="button small" href="<?= $e($url($base . '/parts/' . $part['part_id'])) ?>" download>Télécharger</a>
                    <?php else: ?>
                        <span class="tag"><?= $e($statusLabel[$part['status']] ?? $part['status']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<h2>Contenu</h2>
<?php if ($hasHtml): ?>
    <p class="actions">
        <?php if ($remoteImages): ?>
            <a href="<?= $e($url($base . $filter->query())) ?>">Masquer les images distantes</a>
        <?php else: ?>
            <a href="<?= $e($url($base . ($filter->query() === '' ? '?' : $filter->query() . '&') . 'images=1')) ?>">Afficher les images distantes</a>
        <?php endif; ?>
        · <a href="<?= $e($url($base . '/html' . ($remoteImages ? '?images=1' : ''))) ?>" target="_blank" rel="noopener">Ouvrir dans un onglet</a>
    </p>
    <iframe class="mail-html" src="<?= $e($url($base . '/html' . ($remoteImages ? '?images=1' : ''))) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" title="Version HTML du mail"></iframe>
    <?php if ($hasText): ?>
        <details class="block">
            <summary>Version texte</summary>
            <pre class="mail-text"><?= $e($message['text']) ?></pre>
        </details>
    <?php endif; ?>
<?php elseif ($hasText): ?>
    <pre class="mail-text card"><?= $e($message['text']) ?></pre>
<?php else: ?>
    <div class="card empty">
        <?= $e($message['snippet'] !== '' ? $message['snippet'] : 'Aucun contenu.') ?>
        <?php if ($message['text'] === null && $message['html'] === null): ?>
            <br><span class="muted">Corps non disponible : ce mail est arrivé par le webhook direct de Mailpit (résumé seulement).</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($message['headers'] !== []): ?>
<details class="block">
    <summary>En-têtes (<?= $e(count($message['headers'])) ?>)</summary>
    <div class="table-wrap">
        <table class="headers">
            <tbody>
            <?php foreach ($message['headers'] as $name => $values): ?>
                <?php foreach ((array) $values as $value): ?>
                    <tr><th><?= $e($name) ?></th><td><?= $e($value) ?></td></tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>
