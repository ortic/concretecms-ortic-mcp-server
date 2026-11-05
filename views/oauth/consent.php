<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string $siteName
 * @var string $clientName
 * @var string $redirectHost
 * @var string $userName
 * @var bool $developerAvailable
 * @var bool $developerRequested
 * @var string $formAction
 * @var array $hidden
 * @var string $csrfToken
 * @var string $logoutUrl
 */
$pageTitle = t('Connect %s', $clientName);
include __DIR__ . '/layout_start.php';
?>
<h1><?= t('Allow %s to access this site?', '<span>' . h($clientName) . '</span>') ?></h1>
<p><?= t('The AI assistant will be able to do the following as %s:', '<strong>' . h($userName) . '</strong>') ?></p>
<ul>
    <li><?= t('Read and edit pages, blocks, files, users and groups that your account can access') ?></li>
    <li><?= t('Every action uses your Concrete permissions and is recorded in the MCP activity log') ?></li>
</ul>
<form method="post" action="<?= h($formAction) ?>">
    <?php foreach ($hidden as $name => $value) { ?>
        <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
    <?php } ?>
    <input type="hidden" name="ccm_token" value="<?= h($csrfToken) ?>">
    <?php if ($developerAvailable) { ?>
        <div class="warn">
            <label>
                <input type="checkbox" name="grant_developer" value="1"<?= $developerRequested ? ' checked' : '' ?>>
                <?= t('Also allow developer tools') ?>
            </label>
            <p><?= t('Lets the assistant read and change PHP, CSS and other files in application/ and packages/, clear caches and install packages. This is equivalent to giving it full control of the server. Only allow this for apps you fully trust.') ?></p>
        </div>
    <?php } ?>
    <div class="meta">
        <?= t('After you approve, you will be sent back to %s.', '<strong>' . h($redirectHost) . '</strong>') ?>
        <?= t('Not %s?', h($userName)) ?> <a href="<?= h($logoutUrl) ?>"><?= t('Log out') ?></a>
    </div>
    <div class="actions">
        <button type="submit" name="decision" value="deny"><?= t('Cancel') ?></button>
        <button type="submit" name="decision" value="approve" class="primary"><?= t('Allow access') ?></button>
    </div>
</form>
<?php include __DIR__ . '/layout_end.php'; ?>
