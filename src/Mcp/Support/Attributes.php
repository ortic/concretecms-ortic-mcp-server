<?php

namespace Ortic\McpServer\Mcp\Support;

use Concrete\Core\Api\Attribute\AttributeValueMapFactory;
use Concrete\Core\Attribute\Category\CategoryInterface;
use Ortic\McpServer\Mcp\ToolError;

/**
 * Sets attribute values from {handle: value} maps, like the REST API does.
 */
class Attributes
{
    public function __construct(private AttributeValueMapFactory $factory)
    {
    }

    /**
     * @param object $target object with setAttribute() (page version, file version, user info, ...)
     *
     * @return string[] handles that were set
     */
    public function apply($target, CategoryInterface $category, array $values): array
    {
        $unknown = [];
        foreach (array_keys($values) as $handle) {
            if (!is_string($handle) || !$category->getAttributeKeyByHandle($handle)) {
                $unknown[] = (string) $handle;
            }
        }
        if ($unknown !== []) {
            throw new ToolError('Unknown attribute handle(s): ' . implode(', ', $unknown) . '. Use list_attribute_keys to see the available attributes.');
        }
        $set = [];
        foreach ($this->factory->createFromRequestData($category, $values)->getEntries() as $entry) {
            $target->setAttribute($entry->getAttributeKey(), $entry->getAttributeValue());
            $set[] = $entry->getAttributeKey()->getAttributeKeyHandle();
        }

        return $set;
    }

    /**
     * Describe the attribute keys of a category.
     */
    public function describe(CategoryInterface $category): array
    {
        $keys = [];
        foreach ($category->getList() as $key) {
            $keys[] = [
                'handle' => $key->getAttributeKeyHandle(),
                'name' => $key->getAttributeKeyDisplayName('text'),
                'type' => $key->getAttributeTypeHandle(),
            ];
        }

        return $keys;
    }
}
