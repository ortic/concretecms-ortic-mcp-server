<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var Concrete\Core\Validation\CSRF\Token $token
 * @var Ortic\McpServer\Settings $settings
 * @var array $groupLabels
 * @var string $endpoint
 * @var string $resourceMetadataUrl
 * @var string $authServerMetadataUrl
 * @var Concrete\Core\Entity\Express\Entity[] $expressEntities
 * @var bool $isHttps
 * @var bool $prettyUrls
 * @var bool $developerPermissionGranted
 * @var string $taskPermissionsUrl
 */
$roots = $settings->getDeveloperRoots();
$extraRoots = array_diff($roots, ['application', 'packages']);
$selectedExpress = $settings->getExpressEntities();
?>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title"><?= t('Connect an AI assistant') ?></h5>
        <label class="form-label" for="mcp-endpoint"><?= t('MCP server URL') ?></label>
        <div class="input-group mb-3">
            <input type="text" class="form-control font-monospace" id="mcp-endpoint" value="<?= h($endpoint) ?>" readonly onclick="this.select()">
            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mcp-endpoint').value)"><?= t('Copy') ?></button>
        </div>
        <p class="mb-1"><strong><?= t('Claude Desktop / claude.ai') ?>:</strong>
            <?= t('Settings › Connectors › Add custom connector, paste the URL above and click Connect. You will be asked to sign in here and approve access.') ?></p>
        <p class="mb-1"><strong><?= t('Claude Code, Cursor and other clients') ?>:</strong>
            <?= t('use OAuth with the same URL, or create a personal access token on the %s page and send it as an "Authorization: Bearer" header.', '<a href="' . h((string) URL::to('/dashboard/system/mcp/access')) . '">' . t('Access') . '</a>') ?></p>
        <p class="text-muted small mb-3"><?= t('Claude Desktop and claude.ai connect from Anthropic\'s servers, so this site must be reachable from the internet over HTTPS.') ?></p>

        <?php if (!$isHttps) { ?>
            <div class="alert alert-warning"><?= t('This site does not use HTTPS. OAuth clients such as Claude require HTTPS.') ?></div>
        <?php } ?>
        <?php if (!$prettyUrls) { ?>
            <div class="alert alert-warning"><?= t('Pretty URLs are disabled. OAuth discovery needs /.well-known/ URLs to reach Concrete; enable pretty URLs in System & Settings › SEO › URLs and Redirection.') ?></div>
        <?php } ?>

        <button type="button" class="btn btn-sm btn-secondary" id="mcp-diagnostics"><?= t('Run connection check') ?></button>
        <ul class="list-unstyled small mt-2 mb-0" id="mcp-diagnostics-result"></ul>
    </div>
</div>

<form method="post" action="<?= $view->action('save') ?>">
    <?php $token->output('ortic_mcp_settings'); ?>

    <fieldset class="mb-4">
        <legend><?= t('General') ?></legend>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1"<?= $settings->isEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="enabled"><?= t('Enable the MCP server') ?></label>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="read_only" name="read_only" value="1"<?= $settings->isReadOnly() ? ' checked' : '' ?>>
            <label class="form-check-label" for="read_only"><?= t('Read-only mode') ?></label>
            <div class="form-text"><?= t('Only tools that read data are offered, for everybody, including developer tools.') ?></div>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="audit_enabled" name="audit_enabled" value="1"<?= $settings->isAuditEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="audit_enabled"><?= t('Record every tool call in the activity log') ?></label>
        </div>
    </fieldset>

    <fieldset class="mb-4">
        <legend><?= t('Standard tools') ?></legend>
        <p class="text-muted"><?= t('Tools act with the permissions of the user who connected. Users need the "Use the MCP server" task permission (Administrators have it by default).') ?></p>
        <?php foreach ($groupLabels as $group => $label) { ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="group_<?= $group ?>" name="groups[]" value="<?= $group ?>"<?= $settings->isGroupEnabled($group) ? ' checked' : '' ?>>
                <label class="form-check-label" for="group_<?= $group ?>"><?= h(t($label)) ?></label>
            </div>
        <?php } ?>
        <?php if ($expressEntities) { ?>
            <div class="ms-4 mt-2">
                <label class="form-label"><?= t('Express objects to expose') ?></label>
                <?php foreach ($expressEntities as $entity) { ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="express_<?= h($entity->getHandle()) ?>" name="express_entities[]" value="<?= h($entity->getHandle()) ?>"<?= in_array($entity->getHandle(), $selectedExpress, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="express_<?= h($entity->getHandle()) ?>"><?= h($entity->getEntityDisplayName('text')) ?> <code><?= h($entity->getHandle()) ?></code></label>
                    </div>
                <?php } ?>
                <div class="form-text"><?= t('If none is selected, the objects marked "Include this entity in REST API integrations" are exposed.') ?></div>
            </div>
        <?php } ?>
    </fieldset>

    <fieldset class="mb-4">
        <legend><?= t('Developer mode') ?></legend>
        <div class="alert alert-danger">
            <strong><?= t('Developer mode gives the AI assistant control of the server.') ?></strong>
            <?= t('An assistant that can write PHP files can run any code, read the database credentials and change everything on this site. Text it reads on the site (form submissions, comments) could try to trick it. Enable this only on sites you can restore, preferably not in production, and only for trusted users. Every file change is backed up and can be reverted on the File History page.') ?>
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="developer_enabled" name="developer_enabled" value="1"<?= $settings->isDeveloperEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="developer_enabled"><?= t('Enable developer tools') ?></label>
            <div class="form-text">
                <?= t('Developer tools are also limited to users with the "Use MCP developer tools" task permission (by default only the super administrator), and each token or connection has to be granted developer access explicitly.') ?>
                <a href="<?= h($taskPermissionsUrl) ?>"><?= t('Task permissions') ?></a>
            </div>
        </div>
        <div class="row g-3 mb-2">
            <div class="col-md-6">
                <label class="form-label" for="developer_file_access"><?= t('File access') ?></label>
                <select class="form-select" id="developer_file_access" name="developer_file_access">
                    <option value="write"<?= $settings->get('developer_file_access') !== 'read' ? ' selected' : '' ?>><?= t('Read and write') ?></option>
                    <option value="read"<?= $settings->get('developer_file_access') === 'read' ? ' selected' : '' ?>><?= t('Read only') ?></option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="developer_max_file_size_kb"><?= t('Maximum file size (KB)') ?></label>
                <input type="number" min="1" class="form-control" id="developer_max_file_size_kb" name="developer_max_file_size_kb" value="<?= (int) ($settings->getMaxFileSize() / 1024) ?>">
            </div>
        </div>
        <label class="form-label"><?= t('Directories') ?></label>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="root_application" name="developer_roots[]" value="application"<?= in_array('application', $roots, true) ? ' checked' : '' ?>>
            <label class="form-check-label" for="root_application"><code>application/</code> <?= t('(themes, block templates, overrides, config, custom classes)') ?></label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="root_packages" name="developer_roots[]" value="packages"<?= in_array('packages', $roots, true) ? ' checked' : '' ?>>
            <label class="form-check-label" for="root_packages"><code>packages/</code></label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="developer_read_core" name="developer_read_core" value="1"<?= $settings->canDeveloperReadCore() ? ' checked' : '' ?>>
            <label class="form-check-label" for="developer_read_core"><?= t('Allow reading the Concrete core (concrete/) to look up APIs. The core is never writable.') ?></label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="developer_extra_roots"><?= t('Additional top-level directories (one per line, optional)') ?></label>
            <textarea class="form-control font-monospace" rows="2" id="developer_extra_roots" name="developer_extra_roots"><?= h(implode("\n", $extraRoots)) ?></textarea>
            <div class="form-text"><?= t('Always protected: application/config/database.php, application/config/generated_overrides/, application/files/, vendor/ and .git directories, .env files and this package.') ?></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="developer_extensions"><?= t('File extensions that may be changed') ?></label>
            <input type="text" class="form-control font-monospace" id="developer_extensions" name="developer_extensions" value="<?= h(implode(', ', $settings->getDeveloperExtensions())) ?>">
        </div>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="developer_php_lint" name="developer_php_lint" value="1"<?= $settings->isPhpLintEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="developer_php_lint"><?= t('Refuse to save PHP files with syntax errors') ?></label>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="developer_maintenance" name="developer_maintenance" value="1"<?= $settings->get('developer_maintenance') ? ' checked' : '' ?>>
            <label class="form-check-label" for="developer_maintenance"><?= t('Maintenance tools: clear cache, read logs, install and upgrade packages and block types, refresh entities') ?></label>
        </div>
    </fieldset>

    <fieldset class="mb-4">
        <legend><?= t('OAuth') ?></legend>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="oauth_enabled" name="oauth_enabled" value="1"<?= $settings->isOAuthEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="oauth_enabled"><?= t('Allow apps to connect with OAuth (sign in and approve)') ?></label>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="oauth_dynamic_registration" name="oauth_dynamic_registration" value="1"<?= $settings->get('oauth_dynamic_registration') ? ' checked' : '' ?>>
            <label class="form-check-label" for="oauth_dynamic_registration"><?= t('Allow apps to register themselves (dynamic client registration, needed by Claude)') ?></label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="oauth_redirect_allowlist"><?= t('Allowed redirect URIs (one per line, * matches anything)') ?></label>
            <textarea class="form-control font-monospace" rows="4" id="oauth_redirect_allowlist" name="oauth_redirect_allowlist"><?= h(implode("\n", $settings->getRedirectAllowlist())) ?></textarea>
            <div class="form-text"><?= t('Apps can only be connected if they send users back to one of these addresses. This prevents phishing with fake apps. Leave empty to allow any HTTPS or localhost address.') ?></div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="oauth_access_ttl_minutes"><?= t('Access token lifetime (minutes)') ?></label>
                <input type="number" min="5" class="form-control" id="oauth_access_ttl_minutes" name="oauth_access_ttl_minutes" value="<?= (int) ($settings->getAccessTokenTtl() / 60) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="oauth_refresh_ttl_days"><?= t('Stay connected for (days without use)') ?></label>
                <input type="number" min="1" class="form-control" id="oauth_refresh_ttl_days" name="oauth_refresh_ttl_days" value="<?= (int) ($settings->getRefreshTokenTtl() / 86400) ?>">
            </div>
        </div>
    </fieldset>

    <fieldset class="mb-4">
        <legend><?= t('Browser access') ?></legend>
        <label class="form-label" for="allowed_origins"><?= t('Allowed browser origins (one per line)') ?></label>
        <textarea class="form-control font-monospace" rows="2" id="allowed_origins" name="allowed_origins" placeholder="http://localhost:6274"><?= h(implode("\n", $settings->getAllowedOrigins())) ?></textarea>
        <div class="form-text"><?= t('Only needed for MCP clients running in a web browser, such as the MCP Inspector. Desktop apps and Claude do not send an Origin.') ?></div>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <button class="float-end btn btn-primary" type="submit"><?= t('Save') ?></button>
        </div>
    </div>
</form>

<form method="post" action="<?= $view->action('reset_redirect_allowlist') ?>" class="mb-5">
    <?php $token->output('ortic_mcp_reset_allowlist'); ?>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><?= t('Reset redirect URI allowlist to defaults') ?></button>
</form>

<script>
(function () {
    var button = document.getElementById('mcp-diagnostics');
    var list = document.getElementById('mcp-diagnostics-result');
    function add(ok, text) {
        var li = document.createElement('li');
        var badge = document.createElement('span');
        badge.className = 'badge me-2 ' + (ok ? 'bg-success' : 'bg-danger');
        badge.textContent = ok ? <?= json_encode(t('OK')) ?> : <?= json_encode(t('Problem')) ?>;
        li.className = 'mb-1';
        li.appendChild(badge);
        li.appendChild(document.createTextNode(text));
        list.appendChild(li);
    }
    button.addEventListener('click', function () {
        list.innerHTML = '';
        button.disabled = true;
        var endpoint = <?= json_encode($endpoint) ?>;
        fetch(endpoint, {
            method: 'POST',
            credentials: 'omit',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream', 'Authorization': 'Bearer connection-check'},
            body: JSON.stringify({jsonrpc: '2.0', id: 1, method: 'ping'})
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (r.status === 503) {
                    add(false, <?= json_encode(t('The MCP server is disabled.')) ?>);
                } else if (r.status === 401 && data.error === 'invalid_token') {
                    add(true, <?= json_encode(t('The endpoint answers and receives the Authorization header.')) ?>);
                } else if (r.status === 401) {
                    add(false, <?= json_encode(t('The endpoint answers, but the web server removes the Authorization header. On Apache add to .htaccess: RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] or CGIPassAuth On.')) ?>);
                } else {
                    add(false, <?= json_encode(t('Unexpected response from the endpoint: HTTP')) ?> + ' ' + r.status);
                }
            });
        }).catch(function () {
            add(false, <?= json_encode(t('The endpoint could not be reached.')) ?>);
        }).then(function () {
            return fetch(<?= json_encode($resourceMetadataUrl) ?>, {credentials: 'omit'}).then(function (r) {
                add(r.ok, (r.ok ? <?= json_encode(t('OAuth resource metadata is reachable:')) ?> : <?= json_encode(t('OAuth resource metadata is not reachable (OAuth disabled, or /.well-known/ is blocked by the web server):')) ?>) + ' ' + <?= json_encode($resourceMetadataUrl) ?>);
            });
        }).then(function () {
            return fetch(<?= json_encode($authServerMetadataUrl) ?>, {credentials: 'omit'}).then(function (r) {
                add(r.ok, (r.ok ? <?= json_encode(t('OAuth server metadata is reachable:')) ?> : <?= json_encode(t('OAuth server metadata is not reachable:')) ?>) + ' ' + <?= json_encode($authServerMetadataUrl) ?>);
            });
        }).catch(function () {}).then(function () {
            button.disabled = false;
        });
    });
})();
</script>
