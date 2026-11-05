<?php

namespace Ortic\McpServer\Mcp\Support;

use Concrete\Core\Api\Fractal\Transformer\AttributeValueTransformer;
use Concrete\Core\Api\Fractal\Transformer\CollectionVersionTransformer;
use Concrete\Core\Api\Fractal\Transformer\PageTransformer;
use Concrete\Core\Block\Block;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Editor\LinkAbstractor;
use Concrete\Core\Entity\File\File;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\UserInfo;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\TransformerAbstract;

/**
 * Converts Concrete objects to plain arrays for tool results, reusing the REST API transformers where possible.
 */
class Serializer
{
    public function __construct(private Connection $db)
    {
    }

    public function item($object, TransformerAbstract $transformer, array $includes = []): array
    {
        $manager = new Manager();
        $manager->setSerializer(new ArraySerializer());
        if ($includes !== []) {
            $manager->parseIncludes($includes);
        }

        return (array) $manager->createData(new Item($object, $transformer))->toArray();
    }

    public function collection(iterable $objects, TransformerAbstract $transformer): array
    {
        $manager = new Manager();
        $manager->setSerializer(new ArraySerializer());
        $data = $manager->createData(new Collection($objects, $transformer))->toArray();

        return array_values((array) ($data['data'] ?? $data));
    }

    public function pageSummary(Page $page): array
    {
        return [
            'id' => (int) $page->getCollectionID(),
            'name' => (string) $page->getCollectionName(),
            'path' => (string) $page->getCollectionPath() ?: '/',
            'url' => (string) Url::to($page),
            'type' => $page->getPageTypeHandle() ?: null,
            'template' => $page->getPageTemplateHandle() ?: null,
            'is_active' => (bool) $page->isActive(),
            'date_last_updated' => (string) $page->getCollectionDateLastModified(),
        ];
    }

    public function page(Page $page): array
    {
        $data = $this->item($page, new PageTransformer());
        $data['path'] = $data['path'] ?: '/';
        $checker = new Checker($page);
        $data['url'] = (string) Url::to($page);
        $data['parent_id'] = (int) $page->getCollectionParentID() ?: null;
        $data['handle'] = (string) $page->getCollectionHandle();
        $data['is_active'] = (bool) $page->isActive();
        $data['is_system_page'] = (bool) $page->isSystemPage();
        $data['children_count'] = (int) $page->getNumChildren();
        $data['permissions'] = [
            'can_edit_contents' => (bool) $checker->canEditPageContents(),
            'can_edit_properties' => (bool) $checker->canEditPageProperties(),
            'can_approve' => (bool) $checker->canApprovePageVersions(),
            'can_delete' => (bool) $checker->canDeletePage(),
        ];

        return $data;
    }

    public function version($version): array
    {
        $data = $this->item($version, new CollectionVersionTransformer());
        $author = $version->getVersionAuthorUserID() ? UserInfo::getByID($version->getVersionAuthorUserID()) : null;
        $data['author'] = $author ? $author->getUserName() : null;

        return $data;
    }

    /**
     * Attribute values of a page, file, user or Express entry.
     */
    public function attributes($object): array
    {
        $values = $object->getObjectAttributeCategory()->getAttributeValues($object);
        $result = [];
        foreach ($this->collection($values, new AttributeValueTransformer()) as $row) {
            if (is_array($row) && isset($row['handle'])) {
                $result[$row['handle']] = ['type' => $row['type'], 'value' => $row['value']];
            }
        }

        return $result;
    }

    /**
     * Areas and blocks of a page version, with block data in the shape the block's save() accepts.
     */
    public function areas(Page $page): array
    {
        $handles = $this->db->fetchFirstColumn(
            'SELECT DISTINCT arHandle FROM CollectionVersionBlocks WHERE cID = ? AND cvID = ? ORDER BY arHandle',
            [$page->getCollectionID(), $page->getVersionID()]
        );
        $areas = [];
        foreach ($handles as $handle) {
            $blocks = [];
            foreach ($page->getBlocks($handle) as $block) {
                $blocks[] = $this->block($block);
            }
            $areas[] = ['area' => $handle, 'blocks' => $blocks];
        }

        return $areas;
    }

    public function block(Block $block): array
    {
        $data = [
            'id' => (int) $block->getBlockID(),
            'type' => (string) $block->getBlockTypeHandle(),
            'name' => $block->getBlockName() ?: null,
            'custom_template' => $block->getBlockFilename() ?: null,
            'is_from_page_defaults' => (bool) $block->isAliasOfMasterCollection(),
        ];
        $source = $block;
        if ($block->getBlockTypeHandle() === BLOCK_HANDLE_SCRAPBOOK_PROXY) {
            $original = Block::getByID($block->getController()->getOriginalBlockID());
            if ($original) {
                $source = $original;
                $data['clipboard_block_type'] = (string) $original->getBlockTypeHandle();
            }
        }
        $data['data'] = $this->blockRecord($source);

        return $data;
    }

    /**
     * The block's main database record, with links converted to the format used by the block edit forms.
     */
    public function blockRecord(Block $block): ?array
    {
        $controller = $block->getController();
        $table = $controller ? $controller->getBlockTypeDatabaseTable() : null;
        if (!$table) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT * FROM ' . $this->db->quoteIdentifier($table) . ' WHERE bID = ?', [$block->getBlockID()]);
        if (!$row) {
            return null;
        }
        unset($row['bID']);
        foreach ($row as $key => $value) {
            if (is_string($value) && str_contains($value, '{CCM:')) {
                $row[$key] = LinkAbstractor::translateFromEditMode($value);
            }
        }

        return $row;
    }

    public function file(File $file, bool $withAttributes = false): array
    {
        $version = $file->getApprovedVersion();
        $folder = $file->getFileFolderObject();
        $data = [
            'id' => (int) $file->getFileID(),
            'uuid' => $file->hasFileUUID() ? $file->getFileUUID() : null,
            'title' => $version ? (string) $version->getTitle() : null,
            'filename' => $version ? (string) $version->getFileName() : null,
            'description' => $version ? (string) $version->getDescription() : null,
            'tags' => $version ? (string) $version->getTags() : null,
            'url' => $version ? (string) $version->getURL() : null,
            'type' => $version ? (string) $version->getType() : null,
            'extension' => $version ? (string) $version->getExtension() : null,
            'size' => $version ? (int) $version->getFullSize() : null,
            'folder_id' => $folder ? (int) $folder->getTreeNodeID() : null,
            'date_added' => $version ? $version->getDateAdded()->format(DATE_ATOM) : null,
        ];
        if ($version && $version->getTypeObject()->getGenericType() == \Concrete\Core\File\Type\Type::T_IMAGE) {
            $data['width'] = (int) $version->getAttribute('width') ?: null;
            $data['height'] = (int) $version->getAttribute('height') ?: null;
        }
        if ($withAttributes && $version) {
            $data['attributes'] = $this->attributes($version);
        }

        return $data;
    }

    public function user(UserInfo $user, bool $withDetails = false): array
    {
        $data = [
            'id' => (int) $user->getUserID(),
            'username' => (string) $user->getUserName(),
            'email' => (string) $user->getUserEmail(),
            'is_active' => (bool) $user->isActive(),
            'is_validated' => (bool) $user->isValidated(),
            'date_added' => $user->getUserDateAdded() ? $user->getUserDateAdded()->format(DATE_ATOM) : null,
            'last_login' => $user->getLastLogin() ? date(DATE_ATOM, (int) $user->getLastLogin()) : null,
            'language' => $user->getUserDefaultLanguage() ?: null,
        ];
        if ($withDetails) {
            $groups = [];
            foreach ($user->getUserObject()->getUserGroupObjects() as $group) {
                if ($group->getGroupID() > REGISTERED_GROUP_ID) {
                    $groups[] = ['id' => (int) $group->getGroupID(), 'name' => (string) $group->getGroupName()];
                }
            }
            $data['groups'] = $groups;
            $data['attributes'] = $this->attributes($user);
        }

        return $data;
    }

    public function group(Group $group): array
    {
        return [
            'id' => (int) $group->getGroupID(),
            'name' => (string) $group->getGroupName(),
            'display_name' => (string) $group->getGroupDisplayName(false),
            'description' => (string) $group->getGroupDescription(),
            'path' => (string) $group->getGroupPath(),
            'member_count' => (int) $group->getGroupMembersNum(),
        ];
    }
}
