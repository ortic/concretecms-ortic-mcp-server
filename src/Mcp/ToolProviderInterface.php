<?php

namespace Ortic\McpServer\Mcp;

interface ToolProviderInterface
{
    /** @return Tool[] */
    public function getTools(): array;
}
