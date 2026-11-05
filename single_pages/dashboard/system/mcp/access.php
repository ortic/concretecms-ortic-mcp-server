<?php
defined('C5_EXECUTE') or die('Access Denied.');

use Ortic\McpServer\Auth\Scopes;

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var array $personalTokens
 * @var array $grants
 * @var array $clients
 * @var Concrete\Core\User\UserInfoRepository $users
 * @var bool $canUseMcp
 * @var bool $canGrantDeveloper
 * @var bool $developerEnabled
 * @var string $endpoint
 * @var array|null $secret
 */
$dh = app('helper/date');
$userName = function ($uID) use ($users) {
    $ui = $uID ? $users->getByID((int) $uID) : null;

    return $ui ? $ui->getUserName() : t('(deleted user)');
};
$formatDate = function ($value) use ($dh) {
    return $value ? $dh->formatDateTime($value, true) : '–';
};
$scopeBadges = function (string $scopes) {
    return in_array(Scopes::DEVELOPER, Scopes::normalize($scopes), true)
        ? '<span class="badge bg-danger">' . t('developer') . '</span>'
        : '<span class="badge bg-secondary">' . t('standard') . '</span>';
};
?>

<?php if ($secret) { ?>
    <div class="alert alert-success">
        <h5><?= h($secret['title']) ?></h5>
        <p><?= t('Copy it now: it will not be shown again.') ?></p>
        <?php foreach ($secret['values'] as $label => $value) { ?>
            <label class="form-label mb-1"><?= h(t($label)) ?></label>
            <div class="input-group mb-2">
                <input type="text" class="form-control font-monospace" value="<?= h($value) ?>" readonly onclick="this.select()">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><?= t('Copy') ?></button>
            </div>
        <?php } ?>
        <?php if (isset($secret['values']['Token'])) { ?>
            <p class="mb-1 mt-3"><?= t('Example for Claude Code:') ?></p>
            <pre class="mb-0"><code>claude mcp add --transport http concrete <?= h($endpoint) ?> --header "Authorization: Bearer <?= h($secret['values']['Token']) ?>"</code></pre>
        <?php } ?>
    </div>
<?php } ?>

<h3><?= t('Connected apps') ?></h3>
<p class="text-muted"><?= t('Apps connected with OAuth (for example Claude Desktop). Disconnecting revokes their access immediately.') ?></p>
<?php if ($grants) { ?>
    <table class="table table-sm align-middle">
        <thead><tr><th><?= t('App') ?></th><th><?= t('User') ?></th><th><?= t('Access') ?></th><th><?= t('Connected') ?></th><th><?= t('Last used') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($grants as $grant) { ?>
            <tr>
                <td><?= h($grant['clientName'] ?: $grant['clientID']) ?></td>
                <td><?= h($userName($grant['uID'])) ?></td>
                <td><?= $scopeBadges((string) $grant['scopes']) ?></td>
                <td><?= h($formatDate($grant['firstIssuedAt'])) ?></td>
                <td><?= h($formatDate($grant['lastUsedAt'])) ?></td>
                <td class="text-end">
                    <form method="post" action="<?= $view->action('revoke_grant') ?>" onsubmit="return confirm(<?= h(json_encode(t('Disconnect this app?'))) ?>)">
                        <?php $token->output('ortic_mcp_revoke'); ?>
                        <input type="hidden" name="grant" value="<?= h($grant['grantID']) ?>">
                        <button class="btn btn-sm btn-outline-danger"><?= t('Disconnect') ?></button>
                    </form>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } else { ?>
    <p><?= t('No app is connected.') ?></p>
<?php } ?>

<h3 class="mt-5"><?= t('Personal access tokens') ?></h3>
<p class="text-muted"><?= t('For clients that send a fixed token, like Claude Code, Cursor or scripts. A token acts as the user who created it.') ?></p>
<?php if ($canUseMcp) { ?>
    <form method="post" action="<?= $view->action('create_token') ?>" class="row g-2 align-items-end mb-3">
        <?php $token->output('ortic_mcp_create_token'); ?>
        <div class="col-md-4">
            <label class="form-label" for="name"><?= t('Name') ?></label>
            <input type="text" class="form-control" id="name" name="name" maxlength="255" placeholder="<?= h(t('e.g. Claude Code on my laptop')) ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="expires_days"><?= t('Expires') ?></label>
            <select class="form-select" id="expires_days" name="expires_days">
                <option value="30"><?= t('in 30 days') ?></option>
                <option value="90" selected><?= t('in 90 days') ?></option>
                <option value="365"><?= t('in 1 year') ?></option>
                <option value="0"><?= t('never') ?></option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="developer" name="developer" value="1"<?= $canGrantDeveloper ? '' : ' disabled' ?>>
                <label class="form-check-label" for="developer"><?= t('Developer tools') ?></label>
            </div>
            <?php if (!$canGrantDeveloper) { ?>
                <div class="form-text"><?= $developerEnabled ? t('You lack the "Use MCP developer tools" permission.') : t('Developer mode is off.') ?></div>
            <?php } ?>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><?= t('Create token') ?></button>
        </div>
    </form>
<?php } else { ?>
    <div class="alert alert-info"><?= t('You need the "Use the MCP server" task permission to create tokens.') ?></div>
<?php } ?>
<?php if ($personalTokens) { ?>
    <table class="table table-sm align-middle">
        <thead><tr><th><?= t('Name') ?></th><th><?= t('Token') ?></th><th><?= t('User') ?></th><th><?= t('Access') ?></th><th><?= t('Expires') ?></th><th><?= t('Last used') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($personalTokens as $row) {
            $revoked = $row['revokedAt'] !== null;
            $expired = $row['expiresAt'] !== null && strtotime($row['expiresAt']) <= time(); ?>
            <tr class="<?= $revoked || $expired ? 'text-muted' : '' ?>">
                <td><?= h($row['name']) ?></td>
                <td><code><?= h($row['tokenHint']) ?>…</code></td>
                <td><?= h($userName($row['uID'])) ?></td>
                <td><?= $scopeBadges((string) $row['scopes']) ?></td>
                <td><?= $revoked ? t('revoked') : ($row['expiresAt'] ? h($formatDate($row['expiresAt'])) . ($expired ? ' (' . t('expired') . ')' : '') : t('never')) ?></td>
                <td><?= h($formatDate($row['lastUsedAt'])) ?><?= $row['lastUsedIp'] ? ' <span class="text-muted small">' . h($row['lastUsedIp']) . '</span>' : '' ?></td>
                <td class="text-end">
                    <?php if (!$revoked && !$expired) { ?>
                        <form method="post" action="<?= $view->action('revoke_token') ?>" onsubmit="return confirm(<?= h(json_encode(t('Revoke this token?'))) ?>)">
                            <?php $token->output('ortic_mcp_revoke'); ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><?= t('Revoke') ?></button>
                        </form>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<h3 class="mt-5"><?= t('OAuth clients') ?></h3>
<p class="text-muted"><?= t('Apps registered to use OAuth. Claude registers itself automatically; you can register other apps manually.') ?></p>
<?php if ($clients) { ?>
    <table class="table table-sm align-middle">
        <thead><tr><th><?= t('Name') ?></th><th><?= t('Client ID') ?></th><th><?= t('Redirect URIs') ?></th><th><?= t('Registered') ?></th><th><?= t('Last used') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($clients as $client) { ?>
            <tr>
                <td><?= h($client['name']) ?> <?= $client['isDynamic'] ? '<span class="badge bg-light text-dark">' . t('self-registered') . '</span>' : '' ?></td>
                <td><code class="small"><?= h($client['clientID']) ?></code></td>
                <td class="small"><?= implode('<br>', array_map('h', $client['redirectUris'])) ?></td>
                <td><?= h($formatDate($client['createdAt'])) ?></td>
                <td><?= h($formatDate($client['lastUsedAt'])) ?></td>
                <td class="text-end">
                    <form method="post" action="<?= $view->action('delete_client') ?>" onsubmit="return confirm(<?= h(json_encode(t('Remove this client and disconnect everybody using it?'))) ?>)">
                        <?php $token->output('ortic_mcp_revoke'); ?>
                        <input type="hidden" name="client_id" value="<?= h($client['clientID']) ?>">
                        <button class="btn btn-sm btn-outline-danger"><?= t('Remove') ?></button>
                    </form>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } else { ?>
    <p><?= t('No client registered yet.') ?></p>
<?php } ?>

<details class="mb-5">
    <summary><?= t('Register a client manually') ?></summary>
    <form method="post" action="<?= $view->action('create_client') ?>" class="mt-3" style="max-width: 640px">
        <?php $token->output('ortic_mcp_create_client'); ?>
        <div class="mb-3">
            <label class="form-label" for="client_name"><?= t('Name') ?></label>
            <input type="text" class="form-control" id="client_name" name="client_name" required>
        </div>
        <div class="mb-3">
            <label class="form-label" for="redirect_uris"><?= t('Redirect URIs (one per line)') ?></label>
            <textarea class="form-control font-monospace" id="redirect_uris" name="redirect_uris" rows="3" required></textarea>
            <div class="form-text"><?= t('They must also match the allowlist on the Settings page.') ?></div>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="confidential" name="confidential" value="1">
            <label class="form-check-label" for="confidential"><?= t('Confidential client (gets a client secret)') ?></label>
        </div>
        <button type="submit" class="btn btn-secondary"><?= t('Register client') ?></button>
    </form>
</details>
