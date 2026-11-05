<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Express\Command\ExpressEntryCommandFactory;
use Concrete\Core\Application\Application;
use Concrete\Core\Entity\Express\Entity;
use Concrete\Core\Entity\Express\Entry;
use Concrete\Core\Http\Request;
use Concrete\Core\Permission\Checker;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\Serializer;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolContext;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;
use Ortic\McpServer\Settings;

/**
 * Express objects (custom data types). Only the entities chosen in the dashboard are exposed.
 */
class ExpressTools implements ToolProviderInterface
{
    public function __construct(private Application $app, private Serializer $serializer, private Settings $settings)
    {
    }

    public function getTools(): array
    {
        $entryRef = [
            'entity' => ['type' => 'string', 'description' => 'Express object handle (see list_express_objects).'],
            'entry_id' => ['type' => 'string', 'description' => 'Public identifier of the entry.'],
        ];

        return [
            new Tool(
                name: 'list_express_objects',
                group: 'express',
                title: 'List Express objects',
                description: 'List the Express objects (custom data types such as form submissions or catalogs) available to you, with their attributes and associations.',
                properties: [],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn () => $this->listObjects(),
                idempotent: true
            ),
            new Tool(
                name: 'search_express_entries',
                group: 'express',
                title: 'Search Express entries',
                description: 'List or search the entries of an Express object, newest first, with their attribute values.',
                properties: [
                    'entity' => $entryRef['entity'],
                    'keywords' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (1-100, default 25).'],
                    'page' => ['type' => 'integer', 'description' => 'Result page number (default 1).'],
                ],
                required: ['entity'],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->searchEntries($a),
                idempotent: true
            ),
            new Tool(
                name: 'get_express_entry',
                group: 'express',
                title: 'Get Express entry',
                description: 'Get one Express entry with all attribute values.',
                properties: $entryRef,
                required: ['entity', 'entry_id'],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getEntry($a),
                idempotent: true
            ),
            new Tool(
                name: 'create_express_entry',
                group: 'express',
                title: 'Create Express entry',
                description: 'Create an entry. "values" maps attribute handles (and association names, with entry IDs) to values.',
                properties: [
                    'entity' => $entryRef['entity'],
                    'values' => ['type' => 'object'],
                ],
                required: ['entity', 'values'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->saveEntry($a, false)
            ),
            new Tool(
                name: 'update_express_entry',
                group: 'express',
                title: 'Update Express entry',
                description: 'Change attribute values or associations of an entry. Only the given values change.',
                properties: $entryRef + ['values' => ['type' => 'object']],
                required: ['entity', 'entry_id', 'values'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->saveEntry($a, true)
            ),
            new Tool(
                name: 'delete_express_entry',
                group: 'express',
                title: 'Delete Express entry',
                description: 'Permanently delete an Express entry. Confirm with the user first.',
                properties: $entryRef,
                required: ['entity', 'entry_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->deleteEntry($a)
            ),
        ];
    }

    /** @return Entity[] */
    public function exposedEntities(): array
    {
        $selected = $this->settings->getExpressEntities();
        $result = [];
        foreach ($this->app->make('express')->getEntities() as $entity) {
            $exposed = $selected === [] ? $entity->getIncludeInRestApi() : in_array($entity->getHandle(), $selected, true);
            if ($exposed) {
                $result[$entity->getHandle()] = $entity;
            }
        }

        return $result;
    }

    private function listObjects(): array
    {
        $objects = [];
        foreach ($this->exposedEntities() as $entity) {
            if (!(new Checker($entity))->canViewExpressEntries()) {
                continue;
            }
            $attributes = [];
            foreach ($entity->getAttributes() as $key) {
                $attributes[] = ['handle' => $key->getAttributeKeyHandle(), 'name' => $key->getAttributeKeyDisplayName('text'), 'type' => $key->getAttributeTypeHandle()];
            }
            $associations = [];
            foreach ($entity->getAssociations() as $association) {
                $associations[] = [
                    'name' => $association->getTargetPropertyName(),
                    'target' => $association->getTargetEntity()->getHandle(),
                    'type' => (new \ReflectionClass($association))->getShortName(),
                ];
            }
            $objects[] = [
                'handle' => $entity->getHandle(),
                'name' => $entity->getEntityDisplayName('text'),
                'description' => $entity->getEntityDisplayDescription('text'),
                'attributes' => $attributes,
                'associations' => $associations,
            ];
        }

        return ['objects' => $objects];
    }

    private function searchEntries(Args $args): array
    {
        $entity = $this->entity($args);
        if (!(new Checker($entity))->canViewExpressEntries()) {
            throw new ToolError('You cannot view these entries.');
        }
        $list = $this->app->make('express')->getList($entity->getHandle(), true);
        $list->setPermissionsChecker(fn ($entry) => (new Checker($entry))->canViewExpressEntry());
        if (($keywords = $args->string('keywords')) !== null && $keywords !== '') {
            $list->filterByKeywords($keywords);
        }
        $list->sortByDateAddedDescending();
        $pagination = $list->getPagination();
        $pagination->setMaxPerPage($args->limit());
        $pagination->setCurrentPage(max(1, (int) $args->int('page', 1)));
        $entries = [];
        foreach ($pagination->getCurrentPageResults() as $entry) {
            $entries[] = $this->entry($entry);
        }

        return [
            'total' => (int) $pagination->getTotalResults(),
            'page' => $pagination->getCurrentPage(),
            'total_pages' => $pagination->getNbPages(),
            'entries' => $entries,
        ];
    }

    private function getEntry(Args $args): array
    {
        [, $entry] = $this->resolveEntry($args);
        if (!(new Checker($entry))->canViewExpressEntry()) {
            throw new ToolError('You cannot view this entry.');
        }

        return $this->entry($entry);
    }

    private function saveEntry(Args $args, bool $update): array
    {
        $values = $args->object('values', [], true);
        $factory = $this->app->make(ExpressEntryCommandFactory::class);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($values));
        if ($update) {
            [$entity, $entry] = $this->resolveEntry($args);
            if (!(new Checker($entry))->canEditExpressEntry()) {
                throw new ToolError('You cannot edit this entry.');
            }
            $command = $factory->createUpdateEntryCommand($entity, $entry, $request);
        } else {
            $entity = $this->entity($args);
            if (!(new Checker($entity))->canAddExpressEntries()) {
                throw new ToolError('You cannot add entries to this object.');
            }
            $command = $factory->createAddEntryCommand($entity, $request);
        }
        $known = [];
        foreach ($entity->getAttributes() as $key) {
            $known[] = $key->getAttributeKeyHandle();
        }
        foreach ($entity->getAssociations() as $association) {
            $known[] = $association->getTargetPropertyName();
        }
        $unknown = array_diff(array_keys($values), $known);
        if ($unknown !== []) {
            throw new ToolError('Unknown attribute or association: ' . implode(', ', $unknown) . '. Use list_express_objects.');
        }

        return $this->entry($this->app->executeCommand($command));
    }

    private function deleteEntry(Args $args): array
    {
        [, $entry] = $this->resolveEntry($args);
        if (!(new Checker($entry))->canDeleteExpressEntry()) {
            throw new ToolError('You cannot delete this entry.');
        }
        $summary = ['id' => $entry->getPublicIdentifier(), 'label' => $entry->getLabel()];
        $this->app->make('express')->deleteEntry($entry);

        return ['deleted' => $summary];
    }

    private function entity(Args $args): Entity
    {
        $handle = $args->requireString('entity');
        $entities = $this->exposedEntities();
        if (!isset($entities[$handle])) {
            throw new ToolError(sprintf('Express object "%s" not found or not exposed to MCP.', $handle));
        }

        return $entities[$handle];
    }

    private function resolveEntry(Args $args): array
    {
        $entity = $this->entity($args);
        $entry = $this->app->make('express')->getEntryByPublicIdentifier($args->requireString('entry_id'));
        if (!$entry || !$entry->is($entity->getHandle())) {
            throw new ToolError('Entry not found.');
        }

        return [$entity, $entry];
    }

    private function entry(Entry $entry): array
    {
        $associations = [];
        foreach ($entry->getAssociations() as $association) {
            $ids = [];
            foreach ($association->getSelectedEntries() as $selected) {
                $ids[] = ['id' => $selected->getPublicIdentifier(), 'label' => $selected->getLabel()];
            }
            $associations[$association->getAssociation()->getTargetPropertyName()] = $ids;
        }

        return [
            'id' => $entry->getPublicIdentifier(),
            'label' => $entry->getLabel(),
            'date_added' => $entry->getDateCreated() ? $entry->getDateCreated()->format(DATE_ATOM) : null,
            'date_last_updated' => $entry->getDateModified() ? $entry->getDateModified()->format(DATE_ATOM) : null,
            'author' => $entry->getAuthor() ? $entry->getAuthor()->getUserName() : null,
            'values' => $this->serializer->attributes($entry),
            'associations' => $associations,
        ];
    }
}
