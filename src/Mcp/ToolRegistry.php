<?php

namespace Ortic\McpServer\Mcp;

use Concrete\Core\Application\Application;
use Ortic\McpServer\Mcp\Tools;
use Ortic\McpServer\Settings;

/**
 * Collects the tools and decides which ones the current token may see.
 */
class ToolRegistry
{
    /** @var class-string<ToolProviderInterface>[] */
    private const PROVIDERS = [
        Tools\SystemTools::class,
        Tools\PageTools::class,
        Tools\BlockTools::class,
        Tools\FileTools::class,
        Tools\UserTools::class,
        Tools\GroupTools::class,
        Tools\ExpressTools::class,
        Tools\DeveloperFileTools::class,
        Tools\DeveloperMaintenanceTools::class,
    ];

    /** @var Tool[]|null */
    private ?array $tools = null;

    public function __construct(private Application $app, private Settings $settings)
    {
    }

    /** @return Tool[] all tools, regardless of settings */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach (self::PROVIDERS as $class) {
                /** @var ToolProviderInterface $provider */
                $provider = $this->app->make($class);
                foreach ($provider->getTools() as $tool) {
                    $this->tools[$tool->name] = $tool;
                }
            }
        }

        return $this->tools;
    }

    /** @return Tool[] tools exposed to this request */
    public function available(ToolContext $context): array
    {
        return array_filter($this->all(), fn (Tool $tool) => $this->isAvailable($tool, $context));
    }

    public function find(string $name, ToolContext $context): ?Tool
    {
        $tool = $this->all()[$name] ?? null;

        return $tool !== null && $this->isAvailable($tool, $context) ? $tool : null;
    }

    private function isAvailable(Tool $tool, ToolContext $context): bool
    {
        if ($this->settings->isReadOnly() && !$tool->readOnly) {
            return false;
        }
        if ($tool->group === 'developer_files') {
            return $context->developerAllowed && ($tool->readOnly || $this->settings->canDeveloperWrite());
        }
        if ($tool->group === 'developer_maintenance') {
            return $context->developerAllowed && $this->settings->isMaintenanceEnabled();
        }

        return $this->settings->isGroupEnabled($tool->group);
    }
}
