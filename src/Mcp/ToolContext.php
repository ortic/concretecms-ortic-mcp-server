<?php

namespace Ortic\McpServer\Mcp;

use Concrete\Core\User\UserInfo;
use Ortic\McpServer\Auth\AccessContext;
use Ortic\McpServer\Settings;

/**
 * Everything a tool needs to know about the current MCP request.
 */
final class ToolContext
{
    public function __construct(
        public readonly UserInfo $user,
        public readonly AccessContext $access,
        public readonly Settings $settings,
        public readonly bool $developerAllowed,
        public readonly string $ip
    ) {
    }
}
