<?php

namespace Ortic\McpServer\Mcp;

/**
 * A failure the model should see and can react to (returned as a tool result with isError=true).
 */
class ToolError extends \RuntimeException
{
}
