<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Core\Attribute\Category\CategoryService;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\Attributes;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\Server;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolContext;
use Ortic\McpServer\Mcp\ToolProviderInterface;

class SystemTools implements ToolProviderInterface
{
    public function __construct(private Application $app, private Attributes $attributes)
    {
    }

    public function getTools(): array
    {
        return [
            new Tool(
                name: 'get_site_info',
                group: 'system',
                title: 'Get site information',
                description: 'Returns the site name, URL, Concrete version, locales, active theme, installed packages and the identity and capabilities of the connected user. Call this first to orient yourself.',
                properties: [],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $args, ToolContext $ctx) => $this->siteInfo($ctx),
                idempotent: true
            ),
            new Tool(
                name: 'list_attribute_keys',
                group: 'system',
                title: 'List attribute keys',
                description: 'List the custom attributes (handle, name, type) available for pages, files or users. Use the handles in the "attributes" argument of create/update tools.',
                properties: [
                    'category' => ['type' => 'string', 'enum' => ['page', 'file', 'user'], 'description' => 'Default: page.'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $args) => $this->attributeKeys($args),
                idempotent: true
            ),
        ];
    }

    private function attributeKeys(Args $args): array
    {
        $handle = $args->enum('category', ['page', 'file', 'user'], 'page');
        $category = $this->app->make(CategoryService::class)->getByHandle($handle === 'page' ? 'collection' : $handle);
        if (!$category) {
            throw new ToolError('Unknown attribute category.');
        }

        return ['category' => $handle, 'attributes' => $this->attributes->describe($category->getController())];
    }

    private function siteInfo(ToolContext $ctx): array
    {
        $site = $this->app->make('site')->getSite();
        $config = $this->app->make('config');
        $home = Page::getByID(Page::getHomePageID());
        $theme = $home && !$home->isError() ? $home->getCollectionThemeObject() : null;

        $locales = [];
        foreach ($site->getLocales() as $locale) {
            $locales[] = [
                'locale' => $locale->getLocale(),
                'is_default' => (bool) $locale->getIsDefault(),
                'home_page_id' => $locale->getSiteTreeObject() ? (int) $locale->getSiteTreeObject()->getSiteHomePageID() : null,
            ];
        }

        $packages = [];
        foreach ($this->app->make(PackageService::class)->getInstalledList() as $pkg) {
            $packages[] = [
                'handle' => $pkg->getPackageHandle(),
                'name' => $pkg->getPackageName(),
                'version' => $pkg->getPackageVersion(),
            ];
        }

        $user = $ctx->user;

        return [
            'site' => [
                'name' => (string) $site->getSiteName(),
                'url' => rtrim((string) $this->app->make('url/canonical'), '/'),
                'home_page_id' => (int) Page::getHomePageID(),
                'locales' => $locales,
                'active_theme' => $theme ? ['handle' => $theme->getThemeHandle(), 'name' => $theme->getThemeName()] : null,
                'multilingual' => count($locales) > 1,
            ],
            'concrete' => [
                'version' => (string) $config->get('concrete.version'),
                'php_version' => PHP_VERSION,
                'debug_display_errors' => (bool) $config->get('concrete.debug.display_errors'),
                'full_page_cache' => (string) $config->get('concrete.cache.pages'),
            ],
            'packages' => $packages,
            'connected_user' => [
                'id' => (int) $user->getUserID(),
                'username' => (string) $user->getUserName(),
                'is_super_admin' => (int) $user->getUserID() === USER_SUPER_ID,
                'developer_tools' => $ctx->developerAllowed,
                'read_only_mode' => $ctx->settings->isReadOnly(),
            ],
            'mcp_server' => [
                'version' => Server::SERVER_VERSION,
            ],
        ];
    }
}
