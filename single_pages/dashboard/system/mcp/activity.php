<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var array $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $filterTool
 * @var string $filterStatus
 * @var Concrete\Core\User\UserInfoRepository $users
 */
$dh = app('helper/date');
$pageUrl = function (int $p) use ($view, $filterTool, $filterStatus) {
    return h($view->action('view') . '?' . http_build_query(['tool' => $filterTool, 'status' => $filterStatus, 'p' => $p]));
};
?>

<form method="get" action="<?= $view->action('view') ?>" class="row g-2 align-items-end mb-3">
    <div class="col-md-4">
        <label class="form-label" for="tool"><?= t('Tool') ?></label>
        <input type="text" class="form-control" id="tool" name="tool" value="<?= h($filterTool) ?>" placeholder="update_block">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="status"><?= t('Result') ?></label>
        <select class="form-select" id="status" name="status">
            <option value=""><?= t('All') ?></option>
            <option value="ok"<?= $filterStatus === 'ok' ? ' selected' : '' ?>><?= t('Succeeded') ?></option>
            <option value="failed"<?= $filterStatus === 'failed' ? ' selected' : '' ?>><?= t('Failed') ?></option>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-secondary w-100"><?= t('Filter') ?></button></div>
</form>

<p class="text-muted"><?= t2('%d entry', '%d entries', $total, $total) ?></p>

<table class="table table-sm align-top">
    <thead><tr><th><?= t('Date') ?></th><th><?= t('User') ?></th><th><?= t('Event') ?></th><th><?= t('Details') ?></th><th><?= t('Time') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row) {
        $ui = $row['uID'] ? $users->getByID((int) $row['uID']) : null; ?>
        <tr class="<?= $row['success'] ? '' : 'table-danger' ?>">
            <td class="text-nowrap"><?= h($dh->formatDateTime($row['createdAt'], true, true)) ?><br><span class="text-muted small"><?= h($row['ip']) ?></span></td>
            <td><?= h($ui ? $ui->getUserName() : '–') ?></td>
            <td><code><?= h($row['tool'] ?: $row['event']) ?></code></td>
            <td style="max-width: 560px">
                <?php if ($row['arguments']) { ?>
                    <details><summary class="small text-muted"><?= t('Arguments') ?></summary><pre class="small mb-1" style="white-space: pre-wrap; word-break: break-all"><?= h($row['arguments']) ?></pre></details>
                <?php } ?>
                <?php if ($row['message']) { ?>
                    <div class="small"><?= h($row['message']) ?></div>
                <?php } ?>
            </td>
            <td class="text-nowrap small"><?= $row['durationMs'] !== null ? (int) $row['durationMs'] . ' ms' : '' ?></td>
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

<form method="post" action="<?= $view->action('purge') ?>" class="row g-2 align-items-end mt-4 mb-5" onsubmit="return confirm(<?= h(json_encode(t('Delete old log entries?'))) ?>)">
    <?php $token->output('ortic_mcp_purge_log'); ?>
    <div class="col-auto"><label class="form-label" for="days"><?= t('Delete entries older than (days)') ?></label>
        <input type="number" min="1" class="form-control" id="days" name="days" value="90"></div>
    <div class="col-auto"><button class="btn btn-outline-danger"><?= t('Delete') ?></button></div>
</form>
