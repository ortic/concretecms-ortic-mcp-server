<?php

namespace Concrete\Package\OrticMcpServer\Controller\SinglePage\Dashboard\System\Mcp;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Permission\Key\Key;
use Ortic\McpServer\Auth\Permissions;
use Ortic\McpServer\Http\Urls;
use Ortic\McpServer\Settings as McpSettings;

defined('C5_EXECUTE') or die('Access Denied.');

class Settings extends DashboardPageController
{
    private const GROUP_LABELS = [
        'system' => 'Site information and attribute lists',
        'pages' => 'Pages: search, read, create, edit, move, delete, versions',
        'blocks' => 'Blocks: add, edit and remove blocks in page areas',
        'files' => 'File manager: search, upload, edit, delete, folders',
        'users' => 'Users: search, create, edit, delete',
        'groups' => 'Groups: list, create, manage membership',
        'express' => 'Express objects: entries of custom data objects',
    ];

    public function view()
    {
        $settings = $this->app->make(McpSettings::class);
        $urls = $this->app->make(Urls::class);
        $this->set('settings', $settings);
        $this->set('groupLabels', self::GROUP_LABELS);
        $this->set('endpoint', $urls->endpoint());
        $this->set('resourceMetadataUrl', $urls->protectedResourceMetadata());
        $this->set('authServerMetadataUrl', $urls->authorizationServerMetadata());
        $this->set('expressEntities', $this->app->make('express')->getEntities());
        $this->set('isHttps', str_starts_with($urls->endpoint(), 'https://'));
        $this->set('prettyUrls', (bool) $this->app->make('config')->get('concrete.seo.url_rewriting'));
        $this->set('developerPermissionGranted', Permissions::canUseDeveloperTools());
        $key = Key::getByHandle(Permissions::DEVELOPER);
        $this->set('taskPermissionsUrl', (string) \URL::to('/dashboard/system/permissions/tasks'));
        $this->set('developerKeyExists', (bool) $key);
    }

    public function save()
    {
        if (!$this->token->validate('ortic_mcp_settings')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $post = $this->request->request;
        $all = $post->all();
        $settings = $this->app->make(McpSettings::class);

        $settings->set('enabled', (bool) $post->get('enabled'));
        $settings->set('read_only', (bool) $post->get('read_only'));
        $settings->set('audit_enabled', (bool) $post->get('audit_enabled'));
        $groups = (array) ($all['groups'] ?? []);
        foreach (array_keys(McpSettings::TOOL_GROUPS) as $group) {
            $settings->set('group_' . $group, in_array($group, $groups, true));
        }
        $settings->set('express_entities', array_values(array_filter((array) ($all['express_entities'] ?? []), 'is_string')));
        $settings->set('allowed_origins', $settings->stringList((string) $post->get('allowed_origins')));

        $settings->set('oauth_enabled', (bool) $post->get('oauth_enabled'));
        $settings->set('oauth_dynamic_registration', (bool) $post->get('oauth_dynamic_registration'));
        $settings->set('oauth_redirect_allowlist', $settings->stringList((string) $post->get('oauth_redirect_allowlist')));
        $settings->set('oauth_access_ttl', max(5, (int) $post->get('oauth_access_ttl_minutes')) * 60);
        $settings->set('oauth_refresh_ttl', max(1, (int) $post->get('oauth_refresh_ttl_days')) * 86400);

        $settings->set('developer_enabled', (bool) $post->get('developer_enabled'));
        $settings->set('developer_file_access', $post->get('developer_file_access') === 'read' ? 'read' : 'write');
        $roots = array_values(array_intersect((array) ($all['developer_roots'] ?? []), ['application', 'packages']));
        foreach ($settings->stringList((string) $post->get('developer_extra_roots')) as $extra) {
            $extra = trim(str_replace('\\', '/', $extra), '/');
            if ($extra === '' || str_contains($extra, '..') || str_contains($extra, '/') || in_array($extra, ['concrete', 'vendor', 'updates'], true)) {
                $this->error->add(t('"%s" cannot be used as an additional developer directory: use a single top-level directory name in the web root other than concrete, vendor or updates.', h($extra)));
                continue;
            }
            $roots[] = $extra;
        }
        $settings->set('developer_roots', array_values(array_unique($roots)));
        $settings->set('developer_read_core', (bool) $post->get('developer_read_core'));
        $settings->set('developer_extensions', $settings->stringList((string) $post->get('developer_extensions')));
        $settings->set('developer_php_lint', (bool) $post->get('developer_php_lint'));
        $settings->set('developer_max_file_size', max(1, (int) $post->get('developer_max_file_size_kb')) * 1024);
        $settings->set('developer_maintenance', (bool) $post->get('developer_maintenance'));

        if ($this->error->has()) {
            return $this->view();
        }
        $this->flash('success', t('Settings saved.'));

        return $this->buildRedirect($this->action());
    }

    public function reset_redirect_allowlist()
    {
        if (!$this->token->validate('ortic_mcp_reset_allowlist')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $this->app->make(McpSettings::class)->set('oauth_redirect_allowlist', McpSettings::DEFAULT_REDIRECT_ALLOWLIST);
        $this->flash('success', t('The redirect URI allowlist was reset to the defaults.'));

        return $this->buildRedirect($this->action());
    }
}
