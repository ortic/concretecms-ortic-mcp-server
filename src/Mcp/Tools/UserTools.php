<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Attribute\Category\UserCategory;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Permission\Key\Key;
use Concrete\Core\User\Group\GroupRepository;
use Concrete\Core\User\RegistrationService;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\User\UserList;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\Attributes;
use Ortic\McpServer\Mcp\Support\Serializer;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolContext;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;

class UserTools implements ToolProviderInterface
{
    public function __construct(
        private Application $app,
        private Serializer $serializer,
        private Attributes $attributes,
        private UserInfoRepository $users
    ) {
    }

    public function getTools(): array
    {
        $userRef = [
            'user_id' => ['type' => 'integer'],
            'username' => ['type' => 'string', 'description' => 'Alternative to user_id.'],
            'email' => ['type' => 'string', 'description' => 'Alternative to user_id.'],
        ];

        return [
            new Tool(
                name: 'search_users',
                group: 'users',
                title: 'Search users',
                description: 'Search user accounts by keywords (username, email, attributes) or group.',
                properties: [
                    'keywords' => ['type' => 'string'],
                    'group_id' => ['type' => 'integer', 'description' => 'Only members of this group.'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Filter by active state.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (1-100, default 25).'],
                    'page' => ['type' => 'integer', 'description' => 'Result page number (default 1).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->searchUsers($a),
                idempotent: true
            ),
            new Tool(
                name: 'get_user',
                group: 'users',
                title: 'Get user',
                description: 'Get a user account with its groups and attributes.',
                properties: $userRef,
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getUser($a),
                idempotent: true
            ),
            new Tool(
                name: 'create_user',
                group: 'users',
                title: 'Create user',
                description: 'Create a user account. If no password is given, a random one is set and the user has to use "Forgot password" to sign in.',
                properties: [
                    'username' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'password' => ['type' => 'string', 'description' => 'Optional. Must follow the site password rules.'],
                    'language' => ['type' => 'string', 'description' => 'e.g. "de_DE".'],
                    'group_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Groups to add the user to.'],
                    'attributes' => ['type' => 'object', 'description' => 'Attribute values keyed by handle.'],
                ],
                required: ['username', 'email'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a, ToolContext $c) => $this->createUser($a)
            ),
            new Tool(
                name: 'update_user',
                group: 'users',
                title: 'Update user',
                description: 'Change a user\'s username, email, language, active state or attributes.',
                properties: $userRef + [
                    'new_username' => ['type' => 'string'],
                    'new_email' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Activate or deactivate the account.'],
                    'attributes' => ['type' => 'object', 'description' => 'Attribute values keyed by handle.'],
                ],
                required: [],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a, ToolContext $c) => $this->updateUser($a, $c)
            ),
            new Tool(
                name: 'delete_user',
                group: 'users',
                title: 'Delete user',
                description: 'Permanently delete a user account (through the user workflow if one is configured). Confirm with the user first.',
                properties: $userRef,
                required: [],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a, ToolContext $c) => $this->deleteUser($a, $c)
            ),
        ];
    }

    private function searchUsers(Args $args): array
    {
        $list = new UserList();
        $list->setPermissionsChecker(fn ($user) => (new Checker($user))->canViewUser());
        if (($keywords = $args->string('keywords')) !== null && $keywords !== '') {
            $list->filterByKeywords($keywords);
        }
        if (($groupId = $args->int('group_id')) !== null) {
            $group = $this->app->make(GroupRepository::class)->getGroupByID($groupId);
            if (!$group) {
                throw new ToolError('Group not found.');
            }
            $list->filterByGroup($group);
        }
        if ($args->has('is_active')) {
            $list->filterByIsActive($args->bool('is_active'));
        }
        $list->sortByUserName();
        $pagination = $list->getPagination();
        $pagination->setMaxPerPage($args->limit());
        $pagination->setCurrentPage(max(1, (int) $args->int('page', 1)));
        $users = [];
        foreach ($pagination->getCurrentPageResults() as $user) {
            $users[] = $this->serializer->user($user);
        }

        return [
            'total' => (int) $pagination->getTotalResults(),
            'page' => $pagination->getCurrentPage(),
            'total_pages' => $pagination->getNbPages(),
            'users' => $users,
        ];
    }

    private function getUser(Args $args): array
    {
        $user = $this->resolveUser($args);
        if (!(new Checker($user))->canViewUser()) {
            throw new ToolError('You cannot view this user.');
        }

        return $this->serializer->user($user, true);
    }

    private function createUser(Args $args): array
    {
        if (!(new Checker())->canAddUsers()) {
            throw new ToolError('You cannot add users.');
        }
        $username = $args->requireString('username');
        $email = $args->requireString('email');
        $password = $args->string('password');
        $errors = $this->app->make('error');
        $this->app->make('validator/user/name')->isValid($username, $errors);
        $this->app->make('validator/user/email')->isValid($email, $errors);
        if ($password !== null) {
            $this->app->make('validator/password')->isValid($password, $errors);
        } else {
            $password = bin2hex(random_bytes(16)) . 'Aa1!';
        }
        if ($errors->has()) {
            throw new ToolError(implode(' ', array_map('strval', $errors->getList())));
        }
        $data = ['uName' => $username, 'uEmail' => $email, 'uPassword' => $password];
        if (($language = $args->string('language')) !== null && $language !== '') {
            $data['uDefaultLanguage'] = $language;
        }
        $user = $this->app->make(RegistrationService::class)->create($data);
        if (!$user) {
            throw new ToolError('The user could not be created.');
        }
        $skipped = [];
        foreach ($args->list('group_ids', []) as $groupId) {
            $group = $this->app->make(GroupRepository::class)->getGroupByID((int) $groupId);
            if ($group && (new Checker($group))->canAssignGroup()) {
                $user->getUserObject()->enterGroup($group);
            } else {
                $skipped[] = $groupId;
            }
        }
        if (($attributes = $args->object('attributes')) !== null && $attributes !== []) {
            $this->attributes->apply($user, $this->app->make(UserCategory::class), $attributes);
        }
        $result = $this->serializer->user($this->freshUser((int) $user->getUserID()), true);
        if ($skipped !== []) {
            $result['warning'] = 'Not added to these groups (not found or not allowed): ' . implode(', ', $skipped);
        }

        return $result;
    }

    private function updateUser(Args $args, ToolContext $ctx): array
    {
        $user = $this->resolveUser($args);
        if (!(new Checker($user))->canEditUser()) {
            throw new ToolError('You cannot edit this user.');
        }
        $key = Key::getByHandle('edit_user_properties');
        $key->setPermissionObject($user);
        $assignment = $key->getMyAssignment();
        $errors = $this->app->make('error');
        $data = [];
        if (($username = $args->string('new_username')) !== null) {
            if (!$assignment->allowEditUserName()) {
                throw new ToolError('You cannot change the username of this user.');
            }
            $this->app->make('validator/user/name')->isValid($username, $errors);
            $data['uName'] = $username;
        }
        if (($email = $args->string('new_email')) !== null) {
            if (!$assignment->allowEditEmail()) {
                throw new ToolError('You cannot change the email of this user.');
            }
            $this->app->make('validator/user/email')->isValid($email, $errors);
            $data['uEmail'] = $email;
        }
        if (($language = $args->string('language')) !== null) {
            if (!$assignment->allowEditDefaultLanguage()) {
                throw new ToolError('You cannot change the language of this user.');
            }
            $data['uDefaultLanguage'] = $language;
        }
        if ($errors->has()) {
            throw new ToolError(implode(' ', array_map('strval', $errors->getList())));
        }
        if (($attributes = $args->object('attributes')) !== null && $attributes !== []) {
            $category = $this->app->make(UserCategory::class);
            $allowed = $assignment->getAttributesAllowedArray();
            foreach (array_keys($attributes) as $handle) {
                $attributeKey = $category->getAttributeKeyByHandle($handle);
                if ($attributeKey && $allowed !== null && !in_array($attributeKey->getAttributeKeyID(), (array) $allowed)) {
                    throw new ToolError(sprintf('You cannot edit the attribute "%s" of this user.', $handle));
                }
            }
        }
        if ($data !== []) {
            $user->update($data);
        }
        if (!empty($attributes)) {
            $this->attributes->apply($user, $this->app->make(UserCategory::class), $attributes);
        }
        if ($args->has('is_active')) {
            $active = $args->bool('is_active');
            if ((int) $user->getUserID() === (int) $ctx->user->getUserID() && !$active) {
                throw new ToolError('You cannot deactivate your own account.');
            }
            if (!(new Checker($user))->canActivateUser()) {
                throw new ToolError('You cannot activate or deactivate this user.');
            }
            $active ? $user->activate() : $user->deactivate();
        }

        return $this->serializer->user($this->freshUser((int) $user->getUserID()), true);
    }

    private function deleteUser(Args $args, ToolContext $ctx): array
    {
        $user = $this->resolveUser($args);
        if ((int) $user->getUserID() === USER_SUPER_ID) {
            throw new ToolError('The super administrator cannot be deleted.');
        }
        if ((int) $user->getUserID() === (int) $ctx->user->getUserID()) {
            throw new ToolError('You cannot delete your own account.');
        }
        if (!(new Checker($user))->canDeleteUser()) {
            throw new ToolError('You cannot delete this user.');
        }
        $summary = $this->serializer->user($user);
        $user->triggerDelete($this->app->make(User::class));
        $stillThere = $this->users->getByID($user->getUserID());

        return [
            'user' => $summary,
            'status' => $stillThere ? 'The delete request is waiting for workflow approval.' : 'Deleted.',
        ];
    }

    private function resolveUser(Args $args): UserInfo
    {
        $user = null;
        if (($id = $args->int('user_id')) !== null) {
            $user = $this->users->getByID($id);
        } elseif (($name = $args->string('username')) !== null) {
            $user = $this->users->getByName($name);
        } elseif (($email = $args->string('email')) !== null) {
            $user = $this->users->getByEmail($email);
        } else {
            throw new ToolError('Provide user_id, username or email.');
        }
        if (!$user) {
            throw new ToolError('User not found.');
        }

        return $user;
    }

    private function freshUser(int $id): \Concrete\Core\User\UserInfo
    {
        $user = $this->users->getByID($id);
        app(\Doctrine\ORM\EntityManagerInterface::class)->refresh($user->getEntityObject());

        return $this->users->getByID($id);
    }
}
