<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Tree\Type\Group as GroupTree;
use Concrete\Core\User\Group\Command\AddGroupCommand;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\Group\GroupList;
use Concrete\Core\User\Group\GroupRepository;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\Serializer;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;

class GroupTools implements ToolProviderInterface
{
    public function __construct(
        private Application $app,
        private Serializer $serializer,
        private GroupRepository $groups,
        private UserInfoRepository $users
    ) {
    }

    public function getTools(): array
    {
        $membership = [
            'user_id' => ['type' => 'integer'],
            'group_id' => ['type' => 'integer'],
        ];

        return [
            new Tool(
                name: 'list_groups',
                group: 'groups',
                title: 'List groups',
                description: 'List user groups with their member counts.',
                properties: [
                    'keywords' => ['type' => 'string', 'description' => 'Filter by group name.'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->listGroups($a),
                idempotent: true
            ),
            new Tool(
                name: 'create_group',
                group: 'groups',
                title: 'Create group',
                description: 'Create a user group at the top level of the group tree.',
                properties: [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                required: ['name'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->createGroup($a)
            ),
            new Tool(
                name: 'add_user_to_group',
                group: 'groups',
                title: 'Add user to group',
                description: 'Add a user to a group. Adding users to the Administrators group gives them full access to the site.',
                properties: $membership,
                required: ['user_id', 'group_id'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->changeMembership($a, true),
                idempotent: true
            ),
            new Tool(
                name: 'remove_user_from_group',
                group: 'groups',
                title: 'Remove user from group',
                description: 'Remove a user from a group.',
                properties: $membership,
                required: ['user_id', 'group_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->changeMembership($a, false),
                idempotent: true
            ),
        ];
    }

    private function listGroups(Args $args): array
    {
        $list = new GroupList();
        $list->setPermissionsChecker(fn ($group) => (new Checker($group))->canViewTreeNode());
        if (($keywords = $args->string('keywords')) !== null && $keywords !== '') {
            $list->filterByKeywords($keywords);
        }
        $groups = [];
        foreach ($list->getResults() as $group) {
            $groups[] = $this->serializer->group($group);
        }

        return ['groups' => $groups];
    }

    private function createGroup(Args $args): array
    {
        $tree = GroupTree::get();
        $root = $tree ? $tree->getRootTreeNodeObject() : null;
        if (!$root || !(new Checker($root))->canAddTreeSubNode()) {
            throw new ToolError('You cannot create groups.');
        }
        $command = new AddGroupCommand();
        $command->setName($args->requireString('name'));
        if (($description = $args->string('description')) !== null) {
            $command->setDescription($description);
        }
        $command->setParentNodeID($root->getTreeNodeID());
        /** @var Group $group */
        $group = $this->app->executeCommand($command);

        return $this->serializer->group($group);
    }

    private function changeMembership(Args $args, bool $add): array
    {
        $user = $this->users->getByID($args->requireInt('user_id'));
        if (!$user) {
            throw new ToolError('User not found.');
        }
        $group = $this->groups->getGroupByID($args->requireInt('group_id'));
        if (!$group) {
            throw new ToolError('Group not found.');
        }
        if ((int) $group->getGroupID() <= REGISTERED_GROUP_ID) {
            throw new ToolError('Membership of the Guest and Registered Users groups is automatic.');
        }
        if (!(new Checker($group))->canAssignGroup()) {
            throw new ToolError('You cannot assign this group.');
        }
        if (!(new Checker($user))->canEditUser()) {
            throw new ToolError('You cannot edit this user.');
        }
        $object = $user->getUserObject();
        if ($add) {
            if (!$object->inExactGroup($group)) {
                $object->enterGroup($group);
            }
        } else {
            if ((int) $user->getUserID() === USER_SUPER_ID && (int) $group->getGroupID() === ADMIN_GROUP_ID) {
                throw new ToolError('The super administrator cannot be removed from the Administrators group.');
            }
            if ($object->inExactGroup($group)) {
                $object->exitGroup($group);
            }
        }

        return [
            'user' => $this->serializer->user($this->freshUser((int) $user->getUserID()), true),
            'status' => $add ? 'Added to the group.' : 'Removed from the group.',
        ];
    }

    private function freshUser(int $id): \Concrete\Core\User\UserInfo
    {
        $user = $this->users->getByID($id);
        app(\Doctrine\ORM\EntityManagerInterface::class)->refresh($user->getEntityObject());

        return $this->users->getByID($id);
    }
}
