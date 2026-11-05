<?php

namespace Ortic\McpServer;

use Concrete\Core\Config\Repository\Repository;

/**
 * Package settings, stored in the database configuration so that developer-mode
 * file writes (which can touch application/config) cannot change them.
 */
class Settings
{
    private const PREFIX = 'ortic_mcp_server::mcp.';

    /** Tool groups that can be toggled in the dashboard, with their default state. */
    public const TOOL_GROUPS = [
        'system' => true,
        'pages' => true,
        'blocks' => true,
        'files' => true,
        'users' => true,
        'groups' => true,
        'express' => false,
    ];

    public const DEFAULT_REDIRECT_ALLOWLIST = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
        'http://localhost:*',
        'http://127.0.0.1:*',
    ];

    public const DEFAULT_EXTENSIONS = [
        'php', 'css', 'scss', 'less', 'js', 'mjs', 'json', 'xml', 'html', 'htm', 'twig', 'svg', 'txt', 'md', 'yml', 'yaml',
    ];

    private const DEFAULTS = [
        'enabled' => true,
        'read_only' => false,
        'express_entities' => [],
        'audit_enabled' => true,
        'allowed_origins' => [],
        'oauth_enabled' => true,
        'oauth_dynamic_registration' => true,
        'oauth_redirect_allowlist' => self::DEFAULT_REDIRECT_ALLOWLIST,
        'oauth_access_ttl' => 3600,
        'oauth_refresh_ttl' => 2592000,
        'developer_enabled' => false,
        'developer_file_access' => 'write',
        'developer_roots' => ['application', 'packages'],
        'developer_read_core' => true,
        'developer_extensions' => self::DEFAULT_EXTENSIONS,
        'developer_php_lint' => true,
        'developer_max_file_size' => 1048576,
        'developer_maintenance' => true,
    ];

    private Repository $config;

    public function __construct()
    {
        $this->config = app('config/database');
    }

    public function get(string $key)
    {
        if (str_starts_with($key, 'group_')) {
            $group = substr($key, 6);

            return (bool) $this->config->get(self::PREFIX . $key, self::TOOL_GROUPS[$group] ?? false);
        }

        return $this->config->get(self::PREFIX . $key, self::DEFAULTS[$key] ?? null);
    }

    public function set(string $key, $value): void
    {
        $this->config->save(self::PREFIX . $key, $value);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->get('read_only');
    }

    public function isGroupEnabled(string $group): bool
    {
        return (bool) $this->get('group_' . $group);
    }

    /** @return string[] */
    public function getExpressEntities(): array
    {
        return array_values(array_filter((array) $this->get('express_entities'), 'is_string'));
    }

    public function isAuditEnabled(): bool
    {
        return (bool) $this->get('audit_enabled');
    }

    /** @return string[] */
    public function getAllowedOrigins(): array
    {
        return $this->stringList($this->get('allowed_origins'));
    }

    public function isOAuthEnabled(): bool
    {
        return (bool) $this->get('oauth_enabled');
    }

    public function isDynamicRegistrationEnabled(): bool
    {
        return $this->isOAuthEnabled() && (bool) $this->get('oauth_dynamic_registration');
    }

    /** @return string[] */
    public function getRedirectAllowlist(): array
    {
        return $this->stringList($this->get('oauth_redirect_allowlist'));
    }

    public function getAccessTokenTtl(): int
    {
        return max(300, (int) $this->get('oauth_access_ttl'));
    }

    public function getRefreshTokenTtl(): int
    {
        return max(3600, (int) $this->get('oauth_refresh_ttl'));
    }

    public function isDeveloperEnabled(): bool
    {
        return (bool) $this->get('developer_enabled');
    }

    public function canDeveloperWrite(): bool
    {
        return $this->isDeveloperEnabled() && $this->get('developer_file_access') === 'write';
    }

    /** @return string[] directories relative to the web root */
    public function getDeveloperRoots(): array
    {
        $roots = [];
        foreach ($this->stringList($this->get('developer_roots')) as $root) {
            $root = trim(str_replace('\\', '/', $root), '/');
            if ($root !== '') {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    public function canDeveloperReadCore(): bool
    {
        return (bool) $this->get('developer_read_core');
    }

    /** @return string[] lower-case extensions without dot */
    public function getDeveloperExtensions(): array
    {
        $list = [];
        foreach ($this->stringList($this->get('developer_extensions')) as $ext) {
            $ext = strtolower(ltrim(trim($ext), '.'));
            if ($ext !== '') {
                $list[] = $ext;
            }
        }

        return array_values(array_unique($list));
    }

    public function isPhpLintEnabled(): bool
    {
        return (bool) $this->get('developer_php_lint');
    }

    public function getMaxFileSize(): int
    {
        return max(1024, (int) $this->get('developer_max_file_size'));
    }

    public function isMaintenanceEnabled(): bool
    {
        return $this->isDeveloperEnabled() && (bool) $this->get('developer_maintenance');
    }

    /**
     * Accepts an array or a newline/comma separated string.
     *
     * @return string[]
     */
    public function stringList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }
        $result = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return $result;
    }
}
