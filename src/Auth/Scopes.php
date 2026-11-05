<?php

namespace Ortic\McpServer\Auth;

final class Scopes
{
    /** Standard tools (pages, files, users, ...), always granted. */
    public const STANDARD = 'mcp';

    /** Developer tools (code files, cache, packages). */
    public const DEVELOPER = 'mcp:developer';

    public const ALL = [self::STANDARD, self::DEVELOPER];

    /**
     * Keep only known scopes; the standard scope is always included.
     *
     * @param string|string[]|null $scopes
     *
     * @return string[]
     */
    public static function normalize($scopes): array
    {
        if (is_string($scopes)) {
            $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
        }
        $result = [self::STANDARD];
        foreach ((array) $scopes as $scope) {
            if ($scope === self::DEVELOPER) {
                $result[] = self::DEVELOPER;
            }
        }

        return array_values(array_unique($result));
    }

    public static function toString(array $scopes): string
    {
        return implode(' ', self::normalize($scopes));
    }
}
