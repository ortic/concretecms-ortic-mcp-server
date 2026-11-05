<?php

namespace Ortic\McpServer\Auth;

use Concrete\Core\Permission\Key\Key;

/**
 * Task permissions installed by the package (Dashboard > System > Permissions > Task Permissions).
 * They are evaluated for the current Concrete user, which for MCP requests is the token owner.
 */
final class Permissions
{
    public const ACCESS = 'ortic_mcp_access';

    public const DEVELOPER = 'ortic_mcp_developer';

    public static function canUseMcp(): bool
    {
        return self::validate(self::ACCESS);
    }

    public static function canUseDeveloperTools(): bool
    {
        return self::validate(self::DEVELOPER);
    }

    private static function validate(string $handle): bool
    {
        $key = Key::getByHandle($handle);

        return $key ? (bool) $key->validate() : false;
    }
}
