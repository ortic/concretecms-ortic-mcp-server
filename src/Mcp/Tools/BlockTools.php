<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Area\Area;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Entity\Block\BlockType\BlockType as BlockTypeEntity;
use Concrete\Core\Block\BlockType\BlockTypeList;
use Concrete\Core\Block\Command\AddBlockToPageCommand;
use Concrete\Core\Block\Command\DeleteBlockCommand;
use Concrete\Core\Block\Command\UpdatePageBlockCommand;
use Concrete\Core\Block\Exception\AreaNotFoundException;
use Concrete\Core\Block\Exception\BlockNotFoundException;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Editor\LinkAbstractor;
use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\ResolvesPages;
use Ortic\McpServer\Mcp\Support\Serializer;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;

class BlockTools implements ToolProviderInterface
{
    use ResolvesPages;
    use GetBlockToEditTrait;

    private const BLOCK_REF = [
        'page_id' => ['type' => 'integer', 'description' => 'Page ID.'],
        'area' => ['type' => 'string', 'description' => 'Area handle, e.g. "Main" (see get_page).'],
        'block_id' => ['type' => 'integer', 'description' => 'Block ID from get_page (version="recent").'],
    ];

    public function __construct(private Application $app, private Serializer $serializer, private Connection $db)
    {
    }

    public function getTools(): array
    {
        return [
            new Tool(
                name: 'list_block_types',
                group: 'blocks',
                title: 'List block types',
                description: 'List the installed block types that can be added to pages (handle, name, description).',
                properties: [],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn () => $this->listBlockTypes(),
                idempotent: true
            ),
            new Tool(
                name: 'get_block_type',
                group: 'blocks',
                title: 'Get block type fields',
                description: 'Show the data fields (database columns) of a block type, which is the shape of "data" for add_block and update_block. Rich text fields contain HTML.',
                properties: [
                    'handle' => ['type' => 'string', 'description' => 'Block type handle, e.g. "content".'],
                ],
                required: ['handle'],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getBlockType($a),
                idempotent: true
            ),
            new Tool(
                name: 'add_block',
                group: 'blocks',
                title: 'Add block',
                description: 'Add a block to an area of a page. The change goes to the newest unapproved page version unless approve=true. Example for a Content block: block_type="content", data={"content": "<p>Hello</p>"}.',
                properties: [
                    'page_id' => ['type' => 'integer', 'description' => 'Page ID.'],
                    'area' => ['type' => 'string', 'description' => 'Area handle, e.g. "Main".'],
                    'block_type' => ['type' => 'string', 'description' => 'Block type handle (see list_block_types).'],
                    'data' => ['type' => 'object', 'description' => 'Block data, same fields as the block edit form (see get_block_type).'],
                    'after_block_id' => ['type' => 'integer', 'description' => 'Place the new block after this block. Use 0 to place it first. Default: last.'],
                    'approve' => ['type' => 'boolean', 'description' => 'Publish the page version immediately (default false).'],
                ],
                required: ['page_id', 'area', 'block_type', 'data'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->addBlock($a)
            ),
            new Tool(
                name: 'update_block',
                group: 'blocks',
                title: 'Update block',
                description: 'Change a block\'s data. Only the fields you pass are changed; the others keep their current values. The change goes to the newest unapproved page version unless approve=true. The block may get a new ID in that version; the result contains it.',
                properties: self::BLOCK_REF + [
                    'data' => ['type' => 'object', 'description' => 'Fields to change, e.g. {"content": "<p>New text</p>"}.'],
                    'approve' => ['type' => 'boolean', 'description' => 'Publish the page version immediately (default false).'],
                ],
                required: ['page_id', 'area', 'block_id', 'data'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->updateBlock($a)
            ),
            new Tool(
                name: 'delete_block',
                group: 'blocks',
                title: 'Delete block',
                description: 'Remove a block from a page area (in the newest unapproved version unless approve=true; older versions keep it).',
                properties: self::BLOCK_REF + [
                    'approve' => ['type' => 'boolean', 'description' => 'Publish the page version immediately (default false).'],
                ],
                required: ['page_id', 'area', 'block_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->deleteBlock($a)
            ),
        ];
    }

    private function listBlockTypes(): array
    {
        $types = [];
        foreach (BlockTypeList::getInstalledList() as $type) {
            if ($type->isInternalBlockType()) {
                continue;
            }
            $sets = $type->getBlockTypeSets();
            $set = $sets ? reset($sets) : null;
            $types[] = [
                'handle' => $type->getBlockTypeHandle(),
                'name' => t($type->getBlockTypeName()),
                'description' => t($type->getBlockTypeDescription()),
                'set' => $set ? $set->getBlockTypeSetDisplayName('text') : null,
                'package' => $type->getPackageHandle() ?: null,
            ];
        }

        return ['block_types' => $types];
    }

    private function getBlockType(Args $args): array
    {
        $type = $this->blockType($args->requireString('handle'));
        $controller = $type->getController();
        $fields = [];
        $table = $controller->getBlockTypeDatabaseTable();
        if ($table) {
            foreach ($this->db->getSchemaManager()->listTableColumns($table) as $column) {
                if ($column->getName() === 'bID') {
                    continue;
                }
                $fields[] = [
                    'name' => $column->getName(),
                    'type' => $column->getType()->getName(),
                    'nullable' => !$column->getNotnull(),
                    'default' => $column->getDefault(),
                ];
            }
        }

        return [
            'handle' => $type->getBlockTypeHandle(),
            'name' => t($type->getBlockTypeName()),
            'description' => t($type->getBlockTypeDescription()),
            'table' => $table ?: null,
            'fields' => $fields,
            'notes' => [
                'Fields holding rich text (such as "content") take HTML. Links to pages and files are kept as written.',
                'Blocks that store repeatable items in extra tables (sliders, accordions...) may need fields that are not listed here; check the block\'s edit form or controller if a save does nothing.',
            ],
        ];
    }

    private function addBlock(Args $args): array
    {
        $page = $this->resolvePage($args, 'RECENT');
        $areaHandle = $args->requireString('area');
        $type = $this->blockType($args->requireString('block_type'));
        $data = $this->prepareData($args->object('data', [], true));

        $area = Area::getOrCreate($page, $areaHandle);
        if (!(new Checker($area))->canAddBlock($type)) {
            throw new ToolError('You cannot add this block type to this area.');
        }
        $controller = $type->getController();
        $controller->setAreaObject($area);
        $this->assertValid($controller->validate($data));

        $command = new AddBlockToPageCommand();
        $command->setPage($page);
        $command->setArea($area);
        $command->setBlockType($type);
        $command->setData($data);
        /** @var Block $block */
        $block = $this->app->executeCommand($command);

        if ($args->has('after_block_id')) {
            $afterId = $args->int('after_block_id');
            $recent = $this->reloadPage($page);
            $block = Block::getByID($block->getBlockID(), $recent, $areaHandle);
            $after = $afterId ? Block::getByID($afterId, $recent, $areaHandle) : null;
            if ($afterId && !$after) {
                throw new ToolError('The block was added at the end: after_block_id was not found in this area of the newest version.');
            }
            $block->moveBlockToDisplayOrderPosition($after);
            $recent->rescanDisplayOrder($areaHandle);
        }

        return $this->blockResult($page, $areaHandle, (int) $block->getBlockID(), $args->bool('approve'));
    }

    private function updateBlock(Args $args): array
    {
        $page = $this->resolvePage($args, 'RECENT');
        $areaHandle = $args->requireString('area');
        $blockId = $args->requireInt('block_id');
        [$area, $block] = $this->findBlock($page, $areaHandle, $blockId);
        if (!(new Checker($block))->canEditBlock()) {
            throw new ToolError('You cannot edit this block.');
        }

        $source = $block;
        if ($block->getBlockTypeHandle() === BLOCK_HANDLE_SCRAPBOOK_PROXY) {
            $source = Block::getByID($block->getController()->getOriginalBlockID()) ?: $block;
        }
        $current = $this->serializer->blockRecord($source) ?? [];
        $data = array_merge($current, $this->prepareData($args->object('data', [], true)));
        $this->assertValid($source->getController()->validate($data));

        $toEdit = $this->getBlockToEdit($page, $area, $areaHandle, $blockId);
        $command = new UpdatePageBlockCommand();
        $command->setPage($page);
        $command->setBlock($toEdit);
        $command->setData($data);
        $updated = $this->app->executeCommand($command);

        return $this->blockResult($page, $areaHandle, (int) $updated->getBlockID(), $args->bool('approve'), $blockId);
    }

    private function deleteBlock(Args $args): array
    {
        $page = $this->resolvePage($args, 'RECENT');
        $areaHandle = $args->requireString('area');
        $blockId = $args->requireInt('block_id');
        [$area, $block] = $this->findBlock($page, $areaHandle, $blockId);
        if (!(new Checker($block))->canDeleteBlock()) {
            throw new ToolError('You cannot delete this block.');
        }
        $toEdit = $this->getBlockToEdit($page, $area, $areaHandle, $blockId);
        $collection = $toEdit->getBlockCollectionObject();
        $this->app->executeCommand(new DeleteBlockCommand(
            $toEdit->getBlockID(),
            $collection->getCollectionID(),
            $collection->getVersionID(),
            $areaHandle
        ));
        $result = [
            'deleted_block_id' => $blockId,
            'page_id' => (int) $page->getCollectionID(),
            'version_id' => (int) $collection->getVersionID(),
        ];
        $result['status'] = $args->bool('approve') ? $this->approveRecentVersion($page) : 'Removed in the newest unapproved version. Use approve_page_version to publish.';

        return $result;
    }

    private function blockResult(Page $page, string $areaHandle, int $blockId, bool $approve, ?int $previousId = null): array
    {
        $recent = $this->reloadPage($page);
        $block = Block::getByID($blockId, $recent, $areaHandle);
        $result = [
            'page_id' => (int) $page->getCollectionID(),
            'version_id' => (int) $recent->getVersionID(),
            'area' => $areaHandle,
            'block' => $block ? $this->serializer->block($block) : ['id' => $blockId],
        ];
        if ($previousId !== null && $previousId !== $blockId) {
            $result['note'] = sprintf('The block now has ID %d in this version (was %d).', $blockId, $previousId);
        }
        $result['status'] = $approve ? $this->approveRecentVersion($page) : 'Saved in the newest unapproved version. Use approve_page_version to publish.';

        return $result;
    }

    private function findBlock(Page $page, string $areaHandle, int $blockId): array
    {
        try {
            return $this->getBlockToWorkWith($page, $areaHandle, $blockId);
        } catch (AreaNotFoundException $e) {
            throw new ToolError(sprintf('Area "%s" not found on this page.', $areaHandle));
        } catch (BlockNotFoundException $e) {
            throw new ToolError(sprintf('Block %d not found in area "%s" of the newest version. Call get_page with version="recent" to get current block IDs.', $blockId, $areaHandle));
        }
    }

    private function blockType(string $handle): BlockTypeEntity
    {
        $type = BlockType::getByHandle($handle);
        if (!$type) {
            throw new ToolError(sprintf('Unknown block type "%s". Use list_block_types.', $handle));
        }

        return $type;
    }

    /**
     * Block data coming from an export (CIF placeholders) is converted back to editable links.
     */
    private function prepareData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && str_contains($value, '{ccm:export:')) {
                $data[$key] = LinkAbstractor::translateFromEditMode(LinkAbstractor::import($value));
            } elseif (is_bool($value)) {
                $data[$key] = $value ? 1 : 0;
            }
        }

        return $data;
    }

    private function assertValid($validation): void
    {
        if ($validation instanceof ErrorList && $validation->has()) {
            throw new ToolError('The block data is invalid: ' . implode(' ', array_map('strval', $validation->getList())));
        }
    }
}
