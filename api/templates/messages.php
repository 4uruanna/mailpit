<?php
/**
 * @var list<array<string, mixed>> $messages
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var int $perPage
 * @var callable $e
 * @var callable $url
 * @var callable $date
 */

$recipients = static function (array $message): string {
    $list = [];
    foreach (['to' => '', 'cc' => 'Cc : ', 'bcc' => 'Bcc : '] as $key => $prefix) {
        foreach ($message[$key] as $recipient) {
            $list[] = $prefix . ($recipient['address'] ?? '');
        }
    }

    return implode(', ', $list);
};
$toDate = static fn (?string $iso): ?DateTimeImmutable => $iso === null ? null : new DateTimeImmutable($iso);
$first = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$last = min($total, $page * $perPage);
?>
<h1>Messages</h1>
<p class="muted">
    <?php if ($total === 0): ?>
        Aucun mail.
    <?php elseif ($messages === []): ?>
        <?= $e(sprintf('%d mail(s).', $total)) ?>
    <?php else: ?>
        <?= $e(sprintf('%d à %d sur %d mail(s), du plus récent au plus ancien.', $first, $last, $total)) ?>
    <?php endif; ?>
</p>

<?php if ($messages !== []): ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Reçu le</th><th>De</th><th>À</th><th>Sujet</th><th>Pièces</th></tr>
        </thead>
        <tbody>
        <?php foreach ($messages as $message): ?>
            <tr>
                <td class="nowrap"><?= $e($date($toDate($message['created_at']))) ?></td>
                <td>
                    <?php if ($message['from'] !== null): ?>
                        <?php if ($message['from']['name'] !== ''): ?><?= $e($message['from']['name']) ?><br><?php endif; ?>
                        <span class="muted"><?= $e($message['from']['address']) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $e($recipients($message)) ?></td>
                <td>
                    <div class="subject"><?= $e($message['subject'] !== '' ? $message['subject'] : '(sans sujet)') ?></div>
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
<div class="card empty">Cette page est vide. <a href="<?= $e($url('/v1/app/messages')) ?>">Retour à la première page</a></div>
<?php endif; ?>

<?php if ($pages > 1 && $page <= $pages): ?>
<nav class="pager" aria-label="Pagination">
    <span>
        <?php if ($page > 1): ?><a class="button" href="<?= $e($url('/v1/app/messages?page=' . ($page - 1))) ?>">← Plus récents</a><?php endif; ?>
    </span>
    <span class="muted">Page <?= $e($page) ?> / <?= $e($pages) ?></span>
    <span>
        <?php if ($page < $pages): ?><a class="button" href="<?= $e($url('/v1/app/messages?page=' . ($page + 1))) ?>">Plus anciens →</a><?php endif; ?>
    </span>
</nav>
<?php endif; ?>
