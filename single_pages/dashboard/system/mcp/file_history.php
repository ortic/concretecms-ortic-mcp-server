<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var Concrete\Core\User\UserInfoRepository $users
 * @var array|null $entry
 * @var string|null $currentContent
 * @var array $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $filterPath
 */
$dh = app('helper/date');
$actionLabels = [
    'create' => t('created'),
    'update' => t('changed'),
    'delete' => t('deleted'),
    'restore' => t('restored'),
];
$restoreForm = function (int $id) use ($view, $token) {
    ob_start(); ?>
    <form method="post" action="<?= $view->action('restore') ?>" class="d-inline" onsubmit="return confirm(<?= h(json_encode(t('Undo this change? The file gets back the content it had before it.'))) ?>)">
        <?php $token->output('ortic_mcp_restore'); ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-sm btn-outline-warning"><?= t('Undo') ?></button>
    </form>
    <?php return ob_get_clean();
};

if (isset($entry)) {
    $ui = $entry['uID'] ? $users->getByID((int) $entry['uID']) : null; ?>
    <p><a href="<?= $view->action('view') ?>">&larr; <?= t('All changes') ?></a></p>
    <h3><code><?= h($entry['path']) ?></code></h3>
    <p>
        <?= h($actionLabels[$entry['action']] ?? $entry['action']) ?>
        · <?= h($dh->formatDateTime($entry['createdAt'], true, true)) ?>
        · <?= h($ui ? $ui->getUserName() : '–') ?>
        <?php if ($entry['restoredFromID']) { ?> · <?= t('undo of change #%s', (int) $entry['restoredFromID']) ?><?php } ?>
    </p>
    <p><?= $restoreForm((int) $entry['id']) ?></p>
    <div class="row">
        <div class="col-lg-6">
            <h5><?= t('Before this change') ?></h5>
            <?php if ($entry['previousContent'] === null) { ?>
                <p class="text-muted"><?= t('The file did not exist.') ?></p>
            <?php } else { ?>
                <pre class="border rounded p-2 small" style="max-height: 70vh; overflow: auto"><?= h(mb_strcut((string) $entry['previousContent'], 0, 200000)) ?></pre>
            <?php } ?>
        </div>
        <div class="col-lg-6">
            <h5><?= t('Current file') ?></h5>
            <?php if ($currentContent === null) { ?>
                <p class="text-muted"><?= t('The file does not exist.') ?></p>
            <?php } else { ?>
                <pre class="border rounded p-2 small" style="max-height: 70vh; overflow: auto"><?= h(mb_strcut($currentContent, 0, 200000)) ?></pre>
            <?php } ?>
        </div>
    </div>
    <?php
    return;
}
$pageUrl = function (int $p) use ($view, $filterPath) {
    return h($view->action('view') . '?' . http_build_query(['path' => $filterPath, 'p' => $p]));
};
?>

<p class="text-muted"><?= t('Every file created, changed or deleted with developer tools. "Undo" puts back the content the file had before that change. This works even when developer mode is turned off.') ?></p>

<form method="get" action="<?= $view->action('view') ?>" class="row g-2 align-items-end mb-3">
    <div class="col-md-6">
        <label class="form-label" for="path"><?= t('Path starts with') ?></label>
        <input type="text" class="form-control font-monospace" id="path" name="path" value="<?= h($filterPath) ?>" placeholder="application/themes/">
    </div>
    <div class="col-md-2"><button class="btn btn-secondary w-100"><?= t('Filter') ?></button></div>
</form>

<?php if ($rows) { ?>
    <table class="table table-sm align-middle">
        <thead><tr><th>#</th><th><?= t('Date') ?></th><th><?= t('User') ?></th><th><?= t('File') ?></th><th><?= t('Change') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row) {
            $ui = $row['uID'] ? $users->getByID((int) $row['uID']) : null; ?>
            <tr>
                <td><?= (int) $row['id'] ?></td>
                <td class="text-nowrap"><?= h($dh->formatDateTime($row['createdAt'], true, true)) ?></td>
                <td><?= h($ui ? $ui->getUserName() : '–') ?></td>
                <td><a href="<?= $view->action('show', (int) $row['id']) ?>"><code><?= h($row['path']) ?></code></a></td>
                <td><?= h($actionLabels[$row['action']] ?? $row['action']) ?><?= $row['restoredFromID'] ? ' (#' . (int) $row['restoredFromID'] . ')' : '' ?></td>
                <td class="text-end"><?= $restoreForm((int) $row['id']) ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if ($pages > 1) { ?>
        <nav><ul class="pagination">
            <?php if ($page > 1) { ?><li class="page-item"><a class="page-link" href="<?= $pageUrl($page - 1) ?>">&laquo;</a></li><?php } ?>
            <li class="page-item disabled"><span class="page-link"><?= t('Page %s of %s', $page, $pages) ?></span></li>
            <?php if ($page < $pages) { ?><li class="page-item"><a class="page-link" href="<?= $pageUrl($page + 1) ?>">&raquo;</a></li><?php } ?>
        </ul></nav>
    <?php } ?>
<?php } else { ?>
    <p><?= t('No file has been changed with developer tools yet.') ?></p>
<?php } ?>
