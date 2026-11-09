<?php
/**
 * @var list<array<string, mixed>> $messages
 * @var int $total
 * @var int $pages
 * @var int $perPage
 * @var App\Dashboard\MessageFilter $filter
 * @var array<string, int> $recipients adresse => nombre de mails
 * @var callable $e
 * @var callable $url
 * @var callable $date
 */

$recipientList = static function (array $message): string {
    $list = [];
    foreach (['to' => '', 'cc' => 'Cc : ', 'bcc' => 'Bcc : '] as $key => $prefix) {
        foreach ($message[$key] as $recipient) {
            $list[] = $prefix . ($recipient['address'] ?? '');
        }
    }

    return implode(', ', $list);
};
$page = $filter->page;
$first = ($page - 1) * $perPage + 1;
$last = min($total, $page * $perPage);
$selected = array_flip($filter->addresses);
$detailQuery = $filter->query();
?>
<h1>Messages</h1>

<form method="get" action="<?= $e($url('/v1/app/messages')) ?>" class="filters">
    <details class="dropdown">
        <summary>
            <?php if ($filter->addresses === []): ?>
                Toutes les adresses
            <?php elseif (count($filter->addresses) === 1): ?>
                <?= $e($filter->addresses[0]) ?>
            <?php else: ?>
                <?= $e(count($filter->addresses)) ?> adresses
            <?php endif; ?>
        </summary>
        <div class="menu">
            <?php if ($recipients === []): ?>
                <p class="muted">Aucune adresse.</p>
            <?php endif; ?>
            <?php foreach ($recipients as $address => $count): ?>
                <label class="option">
                    <input type="checkbox" name="address[]" value="<?= $e($address) ?>" <?= isset($selected[(string) $address]) ? 'checked' : '' ?>>
                    <span class="grow"><?= $e($address) ?></span>
                    <span class="muted"><?= $e($count) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </details>
    <select name="sort" aria-label="Ordre">
        <option value="desc" <?= $filter->oldestFirst ? '' : 'selected' ?>>Plus récents d'abord</option>
        <option value="asc" <?= $filter->oldestFirst ? 'selected' : '' ?>>Plus anciens d'abord</option>
    </select>
    <button type="submit">Filtrer</button>
    <?php if ($filter->isActive()): ?>
        <a href="<?= $e($url('/v1/app/messages')) ?>">Réinitialiser</a>
    <?php endif; ?>
</form>

<p class="muted">
    <?php if ($total === 0): ?>
        <?= $filter->addresses === [] ? 'Aucun mail.' : 'Aucun mail pour ces adresses.' ?>
    <?php elseif ($messages === []): ?>
        <?= $e(sprintf('%d mail(s).', $total)) ?>
    <?php else: ?>
        <?= $e(sprintf(
            '%d à %d sur %d mail(s), du plus %s au plus %s.',
            $first,
            $last,
            $total,
            $filter->oldestFirst ? 'ancien' : 'récent',
            $filter->oldestFirst ? 'récent' : 'ancien',
        )) ?>
    <?php endif; ?>
</p>

<?php if ($messages !== []): ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>
                    <a href="<?= $e($url('/v1/app/messages' . $filter->query(['page' => 1, 'oldestFirst' => !$filter->oldestFirst]))) ?>" title="Inverser l'ordre">
                        Reçu le <?= $filter->oldestFirst ? '↑' : '↓' ?>
                    </a>
                </th>
                <th>De</th><th>À</th><th>Sujet</th><th>Pièces</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($messages as $message): ?>
            <?php $link = $url('/v1/app/messages/' . rawurlencode($message['id']) . $detailQuery); ?>
            <tr>
                <td class="nowrap"><a href="<?= $e($link) ?>"><?= $e($date($message['created_at'])) ?></a></td>
                <td>
                    <?php if ($message['from'] !== null): ?>
                        <?php if ($message['from']['name'] !== ''): ?><?= $e($message['from']['name']) ?><br><?php endif; ?>
                        <span class="muted"><?= $e($message['from']['address']) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $e($recipientList($message)) ?></td>
                <td>
                    <a class="subject" href="<?= $e($link) ?>"><?= $e($message['subject'] !== '' ? $message['subject'] : '(sans sujet)') ?></a>
                    <?php if ($message['snippet'] !== ''): ?><div class="snippet"><?= $e($message['snippet']) ?></div><?php endif; ?>
                    <?php if (!$message['complete']): ?><span class="tag">incomplet</span><?php endif; ?>
                </td>
                <td class="nowrap"><?= $message['attachments'] > 0 ? $e($message['attachments']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($total > 0): ?>
<div class="card empty">Cette page est vide. <a href="<?= $e($url('/v1/app/messages' . $filter->query(['page' => 1]))) ?>">Retour à la première page</a></div>
<?php endif; ?>

<?php if ($pages > 1 && $page <= $pages): ?>
<nav class="pager" aria-label="Pagination">
    <span>
        <?php if ($page > 1): ?><a class="button" href="<?= $e($url('/v1/app/messages' . $filter->query(['page' => $page - 1]))) ?>">← Précédents</a><?php endif; ?>
    </span>
    <span class="muted">Page <?= $e($page) ?> / <?= $e($pages) ?></span>
    <span>
        <?php if ($page < $pages): ?><a class="button" href="<?= $e($url('/v1/app/messages' . $filter->query(['page' => $page + 1]))) ?>">Suivants →</a><?php endif; ?>
    </span>
</nav>
<?php endif; ?>
