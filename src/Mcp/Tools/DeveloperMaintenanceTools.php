<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Database\DatabaseStructureManager;
use Concrete\Core\Package\Event\PackageEntities;
use Concrete\Core\Package\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;
use Concrete\Core\Events\EventDispatcher;

class DeveloperMaintenanceTools implements ToolProviderInterface
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];

    public function __construct(private Application $app, private Connection $db, private PackageService $packages)
    {
    }

    public function getTools(): array
    {
        $handle = ['handle' => ['type' => 'string', 'description' => 'Package handle (directory name in packages/).']];

        return [
            new Tool(
                name: 'dev_clear_cache',
                group: 'developer_maintenance',
                title: 'Clear cache',
                description: 'Clear all Concrete caches (needed after changing templates, classes, config or language files).',
                properties: [],
                required: [],
                readOnly: false,
                destructive: false,
                handler: fn () => $this->clearCache(),
                idempotent: true
            ),
            new Tool(
                name: 'dev_get_logs',
                group: 'developer_maintenance',
                title: 'Read logs',
                description: 'Read recent entries of the Concrete log (Dashboard > Reports > Logs), newest first. Check this after changing code.',
                properties: [
                    'min_level' => ['type' => 'string', 'enum' => array_keys(self::LEVELS), 'description' => 'Minimum level (default: warning).'],
                    'channel' => ['type' => 'string', 'description' => 'e.g. "exceptions", "application".'],
                    'keywords' => ['type' => 'string'],
                    'since_minutes' => ['type' => 'integer', 'description' => 'Only entries from the last N minutes.'],
                    'limit' => ['type' => 'integer', 'description' => 'Default 20, max 100.'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getLogs($a),
                idempotent: true
            ),
            new Tool(
                name: 'dev_list_packages',
                group: 'developer_maintenance',
                title: 'List packages',
                description: 'List installed packages (with available upgrades) and packages in packages/ that are not installed yet.',
                properties: [],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn () => $this->listPackages(),
                idempotent: true
            ),
            new Tool(
                name: 'dev_install_package',
                group: 'developer_maintenance',
                title: 'Install package',
                description: 'Install a package that exists in packages/ but is not installed yet.',
                properties: $handle,
                required: ['handle'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->installPackage($a)
            ),
            new Tool(
                name: 'dev_upgrade_package',
                group: 'developer_maintenance',
                title: 'Upgrade package',
                description: 'Run a package\'s upgrade routine (applies db.xml / entity changes, refreshes its block types and runs its upgrade() method). Bump $pkgVersion in the controller first, or pass force=true.',
                properties: $handle + ['force' => ['type' => 'boolean', 'description' => 'Upgrade even if the version did not change.']],
                required: ['handle'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->upgradePackage($a)
            ),
            new Tool(
                name: 'dev_install_block_type',
                group: 'developer_maintenance',
                title: 'Install or refresh block type',
                description: 'Install a block type from application/blocks/<handle>, or refresh an installed one after changing its db.xml.',
                properties: ['handle' => ['type' => 'string', 'description' => 'Block type handle (directory name).']],
                required: ['handle'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->installBlockType($a)
            ),
            new Tool(
                name: 'dev_refresh_entities',
                group: 'developer_maintenance',
                title: 'Refresh Doctrine entities',
                description: 'Regenerate Doctrine proxies and update database tables of entities (application and package entities), like "concrete c5:entities:refresh".',
                properties: [],
                required: [],
                readOnly: false,
                destructive: true,
                handler: fn () => $this->refreshEntities()
            ),
        ];
    }

    private function clearCache(): array
    {
        $this->app->clearCaches();

        return ['status' => 'All caches cleared.'];
    }

    private function getLogs(Args $args): array
    {
        $level = self::LEVELS[$args->enum('min_level', array_keys(self::LEVELS), 'warning')];
        $query = $this->db->createQueryBuilder()
            ->select('logID', 'channel', 'time', 'message', 'uID', 'level')
            ->from('Logs')
            ->where('level >= :level')
            ->setParameter('level', $level)
            ->orderBy('logID', 'DESC')
            ->setMaxResults(max(1, min(100, (int) $args->int('limit', 20))));
        if (($channel = $args->string('channel')) !== null && $channel !== '') {
            $query->andWhere('channel = :channel')->setParameter('channel', $channel);
        }
        if (($keywords = $args->string('keywords')) !== null && $keywords !== '') {
            $query->andWhere('message LIKE :keywords')->setParameter('keywords', '%' . $keywords . '%');
        }
        if (($since = $args->int('since_minutes')) !== null) {
            $query->andWhere('time >= :since')->setParameter('since', time() - $since * 60);
        }
        $names = array_flip(self::LEVELS);
        $entries = [];
        foreach ($query->execute()->fetchAllAssociative() as $row) {
            $entries[] = [
                'id' => (int) $row['logID'],
                'time' => date(DATE_ATOM, (int) $row['time']),
                'level' => $names[(int) $row['level']] ?? (string) $row['level'],
                'channel' => $row['channel'],
                'message' => mb_strimwidth((string) $row['message'], 0, 4000, '…'),
            ];
        }

        return ['entries' => $entries];
    }

    private function listPackages(): array
    {
        $installed = [];
        foreach ($this->packages->getInstalledList() as $entity) {
            $controller = $entity->getController();
            $installed[] = [
                'handle' => $entity->getPackageHandle(),
                'name' => $entity->getPackageName(),
                'installed_version' => $entity->getPackageVersion(),
                'available_version' => $controller ? $controller->getPackageVersion() : null,
                'upgrade_available' => $controller && version_compare($controller->getPackageVersion(), $entity->getPackageVersion(), '>'),
            ];
        }
        $available = [];
        foreach ($this->packages->getAvailablePackages() as $pkg) {
            $available[] = ['handle' => $pkg->getPackageHandle(), 'name' => $pkg->getPackageName(), 'version' => $pkg->getPackageVersion()];
        }

        return ['installed' => $installed, 'not_installed' => $available];
    }

    private function installPackage(Args $args): array
    {
        $handle = $args->requireString('handle');
        $pkg = null;
        foreach ($this->packages->getAvailablePackages() as $available) {
            if ($available->getPackageHandle() === $handle) {
                $pkg = $available;
                break;
            }
        }
        if ($pkg === null) {
            throw new ToolError(sprintf('No uninstalled package "%s" found in packages/.', $handle));
        }
        $test = $pkg->testForInstall();
        if (is_object($test)) {
            throw new ToolError('The package cannot be installed: ' . implode(' ', array_map('strval', $test->getList())));
        }
        $result = $this->packages->install($pkg, []);
        if (is_object($result) && method_exists($result, 'has') && $result->has()) {
            throw new ToolError('Installation failed: ' . implode(' ', array_map('strval', $result->getList())));
        }
        $this->app->clearCaches();

        return ['status' => sprintf('Package "%s" %s installed.', $pkg->getPackageName(), $pkg->getPackageVersion())];
    }

    private function upgradePackage(Args $args): array
    {
        $handle = $args->requireString('handle');
        if ($handle === 'ortic_mcp_server') {
            throw new ToolError('The MCP server package cannot upgrade itself. Upgrade it from the dashboard.');
        }
        $entity = $this->packages->getByHandle($handle);
        if (!$entity) {
            throw new ToolError(sprintf('Package "%s" is not installed.', $handle));
        }
        $pkg = $this->packages->getClass($handle);
        $from = $entity->getPackageVersion();
        $to = $pkg->getPackageVersion();
        if (!$args->bool('force') && !version_compare($to, $from, '>')) {
            throw new ToolError(sprintf('The package is already at version %s. Increase $pkgVersion in its controller.php or pass force=true.', $from));
        }
        $test = $pkg->testForUpgrade();
        if ($test !== true && is_object($test)) {
            throw new ToolError('The package cannot be upgraded: ' . implode(' ', array_map('strval', $test->getList())));
        }
        $pkg->upgradeCoreData();
        $pkg->upgrade();
        $this->app->clearCaches();

        return ['status' => sprintf('Package "%s" upgraded from %s to %s.', $handle, $from, $to)];
    }

    private function installBlockType(Args $args): array
    {
        $handle = $args->requireString('handle');
        if (!preg_match('/^[a-z0-9_]+$/', $handle)) {
            throw new ToolError('Invalid block type handle.');
        }
        $existing = BlockType::getByHandle($handle);
        if ($existing) {
            $existing->refresh();

            return ['status' => sprintf('Block type "%s" refreshed.', $handle)];
        }
        if (!is_dir(DIR_APPLICATION . '/' . DIRNAME_BLOCKS . '/' . $handle)) {
            throw new ToolError(sprintf('application/blocks/%s does not exist. Blocks inside packages are installed by the package.', $handle));
        }
        $result = BlockType::installBlockType($handle);
        if (is_string($result) && $result !== '') {
            throw new ToolError($result);
        }
        $this->app->clearCaches();

        return ['status' => sprintf('Block type "%s" installed.', $handle)];
    }

    private function refreshEntities(): array
    {
        $event = new PackageEntities();
        $this->app->make(EventDispatcher::class)->dispatch('on_refresh_package_entities', $event);
        $managers = array_merge([$this->app->make(EntityManagerInterface::class)], $event->getEntityManagers());
        foreach ($managers as $em) {
            (new DatabaseStructureManager($em))->refreshEntities();
        }

        return ['status' => 'Doctrine cache cleared, proxies regenerated and entity tables updated.'];
    }
}
