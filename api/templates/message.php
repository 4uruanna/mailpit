<?php
/**
 * Page d'un mail, en trois onglets (?tab=) :
 *   mail         informations (de, à, cc, dates…) et contenu (texte par défaut, HTML avec ?view=html)
 *   attachments  pièces jointes et images intégrées, avec lien de téléchargement
 *   headers      tous les en-têtes
 *
 * @var array<string, mixed> $message mail complet (MessageRepository::find)
 * @var App\Dashboard\MessageFilter $filter filtres de la liste d'où l'on vient
 * @var string $tab onglet affiché
 * @var string $content contenu affiché dans l'onglet Mail : 'text', 'html' ou 'none'
 * @var bool $hasText
 * @var bool $hasHtml
 * @var bool $remoteImages afficher les images distantes du HTML
 * @var string $csrf
 * @var callable $e
 * @var callable $url
 * @var callable $date
 * @var callable $bytes
 */

$addresses = static fn (array $list): string => implode(', ', array_map(
    static fn (array $a): string => ($a['name'] ?? '') !== '' ? sprintf('%s <%s>', $a['name'], $a['address'] ?? '') : (string) ($a['address'] ?? ''),
    $list,
));
$base = '/v1/app/messages/' . rawurlencode($message['id']);
// Lien vers cette page (filtres de la liste conservés) avec d'autres paramètres d'affichage.
$self = static fn (array $params): string => $url($base . $filter->query(extra: $params + ['tab' => null, 'view' => null, 'images' => null]));
$tabParam = static fn (string $name): ?string => $name === 'mail' ? null : $name;
$parts = [
    ...array_filter($message['parts'], static fn (array $p): bool => $p['kind'] === 'attachment'),
    ...array_filter($message['parts'], static fn (array $p): bool => $p['kind'] === 'inline'),
];
$headerCount = array_sum(array_map(static fn (mixed $values): int => count((array) $values), $message['headers']));
$tabs = [
    'mail'        => 'Mail',
    'attachments' => sprintf('Pièces jointes (%d)', count($parts)),
    'headers'     => sprintf('En-têtes (%d)', $headerCount),
];
$statusLabel = ['pending' => 'pas encore reçue', 'too_large' => 'trop volumineuse, non enregistrée'];
?>
<p><a href="<?= $e($url('/v1/app/messages' . $filter->query())) ?>">← Messages</a></p>
<div class="title-row">
    <h1><?= $e($message['subject'] !== '' ? $message['subject'] : '(sans sujet)') ?></h1>
    <form method="post" action="<?= $e($url($base . '/delete')) ?>"
          data-confirm="Supprimer définitivement ce mail et ses pièces jointes ? (Il reste dans Mailpit.)">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="back" value="<?= $e($filter->query()) ?>">
        <button type="submit" class="danger">Supprimer ce mail</button>
    </form>
</div>

<nav class="tabs" aria-label="Sections du mail">
    <?php foreach ($tabs as $name => $label): ?>
        <a href="<?= $e($self(['tab' => $tabParam($name)])) ?>" <?= $tab === $name ? 'class="active" aria-current="page"' : '' ?>><?= $e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'mail'): ?>
<div class="card">
    <dl class="meta">
        <dt>De</dt><dd><?= $message['from'] === null ? '—' : $e($addresses([$message['from']])) ?></dd>
        <dt>À</dt><dd><?= $e($addresses($message['to']) ?: '—') ?></dd>
        <?php if ($message['cc'] !== []): ?><dt>Cc</dt><dd><?= $e($addresses($message['cc'])) ?></dd><?php endif; ?>
        <?php if ($message['bcc'] !== []): ?><dt>Bcc</dt><dd><?= $e($addresses($message['bcc'])) ?></dd><?php endif; ?>
        <?php if ($message['reply_to'] !== []): ?><dt>Répondre à</dt><dd><?= $e($addresses($message['reply_to'])) ?></dd><?php endif; ?>
        <?php if ($message['return_path'] !== ''): ?><dt>Return-Path</dt><dd><?= $e($message['return_path']) ?></dd><?php endif; ?>
        <dt>Date du mail</dt><dd><?= $e($date($message['date'])) ?></dd>
        <dt>Reçu par Mailpit</dt><dd><?= $e($date($message['created_at'])) ?></dd>
        <dt>Reçu par l'API</dt><dd><?= $e($date($message['received_at'])) ?></dd>
        <dt>Taille</dt><dd><?= $e($bytes($message['size'])) ?></dd>
        <?php if ($parts !== []): ?>
            <dt>Pièces jointes</dt><dd><a href="<?= $e($self(['tab' => 'attachments'])) ?>"><?= $e(count($parts)) ?> fichier(s)</a></dd>
        <?php endif; ?>
        <?php if ($message['tags'] !== []): ?>
            <dt>Tags</dt><dd><?php foreach ($message['tags'] as $tag): ?><span class="tag"><?= $e($tag) ?></span> <?php endforeach; ?></dd>
        <?php endif; ?>
        <dt>ID Mailpit</dt><dd><code><?= $e($message['id']) ?></code></dd>
        <?php if ($message['message_id'] !== ''): ?><dt>Message-ID</dt><dd><code><?= $e($message['message_id']) ?></code></dd><?php endif; ?>
    </dl>
</div>

<div class="content-head">
    <h2>Contenu</h2>
    <?php if ($hasText && $hasHtml): ?>
        <nav class="switch" aria-label="Version du contenu">
            <a href="<?= $e($self([])) ?>" <?= $content === 'text' ? 'class="active" aria-current="true"' : '' ?>>Texte</a>
            <a href="<?= $e($self(['view' => 'html'])) ?>" <?= $content === 'html' ? 'class="active" aria-current="true"' : '' ?>>HTML</a>
        </nav>
    <?php endif; ?>
</div>

<?php if ($content === 'text'): ?>
    <pre class="mail-text card"><?= $e($message['text']) ?></pre>
<?php elseif ($content === 'html'): ?>
    <p class="actions">
        <?php if ($remoteImages): ?>
            <a href="<?= $e($self(['view' => 'html'])) ?>">Masquer les images distantes</a>
        <?php else: ?>
            <a href="<?= $e($self(['view' => 'html', 'images' => '1'])) ?>">Afficher les images distantes</a>
        <?php endif; ?>
        · <a href="<?= $e($url($base . '/html' . ($remoteImages ? '?images=1' : ''))) ?>" target="_blank" rel="noopener">Ouvrir dans un onglet</a>
    </p>
    <iframe class="mail-html" src="<?= $e($url($base . '/html' . ($remoteImages ? '?images=1' : ''))) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox" title="Version HTML du mail"></iframe>
<?php else: ?>
    <div class="card empty">
        <?= $e($message['snippet'] !== '' ? $message['snippet'] : 'Aucun contenu.') ?>
        <?php if ($message['text'] === null && $message['html'] === null): ?>
            <br><span class="muted">Corps non disponible : ce mail est arrivé par l'ancien webhook direct de Mailpit (résumé seulement).</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php elseif ($tab === 'attachments'): ?>
<?php if ($parts === []): ?>
    <div class="card empty">Aucune pièce jointe.</div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Fichier</th><th>Type</th><th>Taille</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($parts as $part): ?>
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

<?php else: ?>
<?php if ($message['headers'] === []): ?>
    <div class="card empty">
        Aucun en-tête enregistré.
        <br><span class="muted">Les en-têtes ne sont transmis que par le relais, pas par l'ancien webhook direct de Mailpit.</span>
    </div>
<?php else: ?>
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
<?php endif; ?>
<?php endif; ?>
