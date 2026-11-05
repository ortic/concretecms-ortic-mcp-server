<?php

namespace Ortic\McpServer\Mcp;

/**
 * An MCP tool: its definition (as sent in tools/list) and its handler.
 */
final class Tool
{
    /**
     * @param string $group tool group (see Settings::TOOL_GROUPS, or developer_files / developer_maintenance)
     * @param array<string, array> $properties JSON schema of each argument
     * @param string[] $required
     * @param \Closure(Args, ToolContext): mixed $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly string $title,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $required,
        public readonly bool $readOnly,
        public readonly bool $destructive,
        public readonly \Closure $handler,
        public readonly bool $idempotent = false
    ) {
    }

    public function isDeveloperTool(): bool
    {
        return str_starts_with($this->group, 'developer');
    }

    public function definition(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $this->properties === [] ? new \stdClass() : $this->properties,
        ];
        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }
        $annotations = [
            'title' => $this->title,
            'readOnlyHint' => $this->readOnly,
            'openWorldHint' => false,
        ];
        if (!$this->readOnly) {
            $annotations['destructiveHint'] = $this->destructive;
            $annotations['idempotentHint'] = $this->idempotent;
        }

        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => $annotations,
        ];
    }
}
