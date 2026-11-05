<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Attribute\Category\PageCategory;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Collection\Version\Version;
use Concrete\Core\Page\Collection\Version\VersionList;
use Concrete\Core\Page\Command\DeletePageCommand;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Page\Template;
use Concrete\Core\Page\Type\Type;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Concrete\Core\Workflow\Progress\Response as WorkflowResponse;
use Concrete\Core\Workflow\Request\ApprovePageRequest;
use Concrete\Core\Workflow\Request\MovePageRequest;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\Attributes;
use Ortic\McpServer\Mcp\Support\HtmlToText;
use Ortic\McpServer\Mcp\Support\ResolvesPages;
use Ortic\McpServer\Mcp\Support\Serializer;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolContext;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;

class PageTools implements ToolProviderInterface
{
    use ResolvesPages;

    private const PAGE_REF = [
        'page_id' => ['type' => 'integer', 'description' => 'Page ID.'],
        'path' => ['type' => 'string', 'description' => 'Page path such as /about/team (alternative to page_id).'],
    ];

    public function __construct(private Application $app, private Serializer $serializer, private Attributes $attributes)
    {
    }

    public function getTools(): array
    {
        return [
            new Tool(
                name: 'search_pages',
                group: 'pages',
                title: 'Search pages',
                description: 'Find pages by keywords, parent page or page type. Returns summaries (id, name, path, url, type). Only pages the user can view are returned.',
                properties: [
                    'keywords' => ['type' => 'string', 'description' => 'Search in page names, descriptions and indexed content.'],
                    'parent_id' => ['type' => 'integer', 'description' => 'Only pages below this page.'],
                    'parent_path' => ['type' => 'string', 'description' => 'Only pages below this path (alternative to parent_id).'],
                    'direct_children_only' => ['type' => 'boolean', 'description' => 'With a parent: only direct children (default true). Set false to include all descendants.'],
                    'page_type' => ['type' => 'string', 'description' => 'Page type handle, e.g. "page" or "blog_entry".'],
                    'include_inactive' => ['type' => 'boolean', 'description' => 'Include pages without an approved version and deactivated pages.'],
                    'include_system_pages' => ['type' => 'boolean', 'description' => 'Include dashboard and other system pages.'],
                    'sort' => ['type' => 'string', 'enum' => ['sitemap', 'name', 'date_modified', 'date_public'], 'description' => 'Sort order. Default: sitemap order with a parent, otherwise last modified first.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (1-100, default 25).'],
                    'page' => ['type' => 'integer', 'description' => 'Result page number for pagination (default 1).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->searchPages($a),
                idempotent: true
            ),
            new Tool(
                name: 'get_page',
                group: 'pages',
                title: 'Get page',
                description: 'Get a page with its properties, attributes and the blocks in each area (with block IDs and data). Use version="recent" before editing to see unapproved changes.',
                properties: self::PAGE_REF + [
                    'version' => ['type' => 'string', 'enum' => ['active', 'recent'], 'description' => 'active = what visitors see (default), recent = newest version including unapproved edits.'],
                    'include_blocks' => ['type' => 'boolean', 'description' => 'Include areas and blocks (default true).'],
                    'include_attributes' => ['type' => 'boolean', 'description' => 'Include custom attributes (default true).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getPage($a),
                idempotent: true
            ),
            new Tool(
                name: 'get_page_content',
                group: 'pages',
                title: 'Get rendered page content',
                description: 'Render the live version of a page and return its content as plain text and/or HTML, as a visitor would read it. Use this to review or summarize copy; use get_page to edit.',
                properties: self::PAGE_REF + [
                    'format' => ['type' => 'string', 'enum' => ['text', 'html', 'both'], 'description' => 'Default: text.'],
                    'max_length' => ['type' => 'integer', 'description' => 'Truncate each returned field to this many characters (default 30000).'],
                    'include_layout' => ['type' => 'boolean', 'description' => 'Include the theme header, navigation and footer (default false).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getPageContent($a),
                idempotent: true
            ),
            new Tool(
                name: 'list_page_types',
                group: 'pages',
                title: 'List page types and templates',
                description: 'List page types (with their allowed templates) and page templates. Needed to create pages or change their template.',
                properties: [],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn () => $this->listPageTypes(),
                idempotent: true
            ),
            new Tool(
                name: 'create_page',
                group: 'pages',
                title: 'Create page',
                description: 'Create a page below a parent page. The page type\'s default blocks are added automatically. By default the page is created unapproved (not visible); pass publish=true to make it live.',
                properties: [
                    'parent_id' => ['type' => 'integer', 'description' => 'Parent page ID.'],
                    'parent_path' => ['type' => 'string', 'description' => 'Parent page path (alternative to parent_id), "/" for the home page.'],
                    'name' => ['type' => 'string', 'description' => 'Page name (title).'],
                    'handle' => ['type' => 'string', 'description' => 'URL slug. Generated from the name if omitted.'],
                    'description' => ['type' => 'string'],
                    'page_type' => ['type' => 'string', 'description' => 'Page type handle (default "page"). See list_page_types.'],
                    'template' => ['type' => 'string', 'description' => 'Page template handle. Default: the page type\'s default template.'],
                    'attributes' => ['type' => 'object', 'description' => 'Attribute values keyed by attribute handle, e.g. {"meta_title": "..."}.'],
                    'publish' => ['type' => 'boolean', 'description' => 'Approve the page immediately (default false).'],
                ],
                required: ['name'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->createPage($a)
            ),
            new Tool(
                name: 'update_page',
                group: 'pages',
                title: 'Update page properties',
                description: 'Change a page\'s name, URL slug, description, template, page type or attributes. Changes go to a new unapproved version unless approve=true.',
                properties: self::PAGE_REF + [
                    'name' => ['type' => 'string'],
                    'handle' => ['type' => 'string', 'description' => 'New URL slug.'],
                    'description' => ['type' => 'string'],
                    'template' => ['type' => 'string', 'description' => 'Page template handle.'],
                    'page_type' => ['type' => 'string', 'description' => 'Page type handle.'],
                    'attributes' => ['type' => 'object', 'description' => 'Attribute values keyed by attribute handle.'],
                    'version_comment' => ['type' => 'string', 'description' => 'Comment shown in the version history.'],
                    'approve' => ['type' => 'boolean', 'description' => 'Publish the changes immediately (default false).'],
                ],
                required: [],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->updatePage($a)
            ),
            new Tool(
                name: 'move_page',
                group: 'pages',
                title: 'Move page',
                description: 'Move a page (and its subpages) below another parent page.',
                properties: self::PAGE_REF + [
                    'new_parent_id' => ['type' => 'integer', 'description' => 'ID of the new parent page.'],
                    'keep_old_path' => ['type' => 'boolean', 'description' => 'Keep the old URL working as a redirect (default true).'],
                ],
                required: ['new_parent_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->movePage($a)
            ),
            new Tool(
                name: 'delete_page',
                group: 'pages',
                title: 'Delete page',
                description: 'Move a page and its subpages to the trash (it can be restored from the sitemap trash). Confirm with the user first.',
                properties: self::PAGE_REF,
                required: [],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->deletePage($a)
            ),
            new Tool(
                name: 'list_page_versions',
                group: 'pages',
                title: 'List page versions',
                description: 'List the versions of a page, newest first, with their approval state and author.',
                properties: self::PAGE_REF + [
                    'limit' => ['type' => 'integer', 'description' => 'Max versions (default 20).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->listVersions($a),
                idempotent: true
            ),
            new Tool(
                name: 'approve_page_version',
                group: 'pages',
                title: 'Approve page version',
                description: 'Publish a page version (default: the most recent one) so visitors see it. Goes through the page workflow if one is configured.',
                properties: self::PAGE_REF + [
                    'version_id' => ['type' => 'integer', 'description' => 'Version to approve. Default: most recent version. Use an older ID to roll back.'],
                ],
                required: [],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->approveVersion($a)
            ),
        ];
    }

    private function searchPages(Args $args): array
    {
        $list = new PageList();
        $list->getQueryObject()->andWhere('p.cPointerExternalLink IS NULL');
        $list->setPermissionsChecker(fn ($page) => (new Checker($page))->canViewPage());

        $parent = null;
        if ($args->has('parent_id') || $args->has('parent_path')) {
            $parent = $this->resolvePage($args, 'ACTIVE', 'parent_id', 'parent_path');
            if ($args->bool('direct_children_only', true)) {
                $list->filterByParentID($parent->getCollectionID());
            } elseif ((string) $parent->getCollectionPath() !== '') {
                $list->filterByPath($parent->getCollectionPath(), true);
            }
        }
        if (($keywords = $args->string('keywords')) !== null && $keywords !== '') {
            $list->filterByKeywords($keywords);
        }
        if (($type = $args->string('page_type')) !== null && $type !== '') {
            $list->filterByPageTypeHandle($type);
        }
        if ($args->bool('include_inactive')) {
            $list->includeInactivePages();
        }
        if ($args->bool('include_system_pages')) {
            $list->includeSystemPages();
        }
        $sort = $args->enum('sort', ['sitemap', 'name', 'date_modified', 'date_public'], $parent ? 'sitemap' : 'date_modified');
        match ($sort) {
            'sitemap' => $list->sortByDisplayOrder(),
            'name' => $list->sortByName(),
            'date_public' => $list->sortByPublicDateDescending(),
            default => $list->sortByDateModifiedDescending(),
        };

        $pagination = $list->getPagination();
        $pagination->setMaxPerPage($args->limit());
        $pagination->setCurrentPage(max(1, (int) $args->int('page', 1)));
        $pages = [];
        foreach ($pagination->getCurrentPageResults() as $page) {
            $pages[] = $this->serializer->pageSummary($page);
        }

        return [
            'total' => (int) $pagination->getTotalResults(),
            'page' => $pagination->getCurrentPage(),
            'total_pages' => $pagination->getNbPages(),
            'pages' => $pages,
        ];
    }

    private function getPage(Args $args): array
    {
        $version = $args->enum('version', ['active', 'recent'], 'active') === 'recent' ? 'RECENT' : 'ACTIVE';
        $page = $this->resolvePage($args, $version);
        $checker = new Checker($page);
        if ($version === 'RECENT' ? !$checker->canViewPageVersions() : !$checker->canViewPage()) {
            throw new ToolError($version === 'RECENT' ? 'You cannot view unapproved versions of this page.' : 'You cannot view this page.');
        }
        if ($version === 'ACTIVE' && !$page->getVersionObject()->isApproved()) {
            throw new ToolError('This page has no approved version yet. Use version="recent".');
        }
        $data = $this->serializer->page($page);
        if ($args->bool('include_attributes', true)) {
            $data['attributes'] = $this->serializer->attributes($page);
        }
        if ($args->bool('include_blocks', true)) {
            $data['areas'] = $this->serializer->areas($page);
        }
        $recent = Page::getByID($page->getCollectionID(), 'RECENT');
        $data['has_unapproved_changes'] = $recent && !$recent->isError() && !$recent->getVersionObject()->isApproved();

        return $data;
    }

    private function getPageContent(Args $args): array
    {
        $page = $this->resolvePage($args);
        if (!(new Checker($page))->canViewPage()) {
            throw new ToolError('You cannot view this page.');
        }
        if ($page->isExternalLink()) {
            throw new ToolError('This page is an external link to ' . $page->getCollectionPointerExternalLink());
        }
        $request = Request::getInstance();
        $previousPage = $request->getCurrentPage();
        $hadUser = $request->hasCustomRequestUser();
        $previousUser = $request->getCustomRequestUser();
        try {
            $request->setCurrentPage($page);
            $view = $page->getPageController()->getViewObject();
            $html = (string) $view->render();
        } catch (\Throwable $e) {
            throw new ToolError('The page could not be rendered: ' . $e->getMessage());
        } finally {
            if ($previousPage) {
                $request->setCurrentPage($previousPage);
            }
            if ($hadUser) {
                $request->setCustomRequestUser($previousUser);
            }
        }
        $content = HtmlToText::extractPageContent($html, $args->bool('include_layout'));
        $format = $args->enum('format', ['text', 'html', 'both'], 'text');
        $max = max(1000, (int) $args->int('max_length', 30000));
        $result = ['id' => (int) $page->getCollectionID(), 'name' => $page->getCollectionName(), 'url' => (string) \URL::to($page)];
        if ($format !== 'html') {
            $text = HtmlToText::convert($content);
            $result['text'] = mb_substr($text, 0, $max);
            $result['text_truncated'] = mb_strlen($text) > $max;
        }
        if ($format !== 'text') {
            $result['html'] = mb_substr($content, 0, $max);
            $result['html_truncated'] = mb_strlen($content) > $max;
        }

        return $result;
    }

    private function listPageTypes(): array
    {
        $types = [];
        foreach (Type::getList() as $type) {
            $templates = [];
            foreach ($type->getPageTypePageTemplateObjects() as $template) {
                $templates[] = $template->getPageTemplateHandle();
            }
            $default = $type->getPageTypeDefaultPageTemplateObject();
            $types[] = [
                'handle' => $type->getPageTypeHandle(),
                'name' => $type->getPageTypeDisplayName('text'),
                'default_template' => $default ? $default->getPageTemplateHandle() : null,
                'allowed_templates' => $templates,
            ];
        }
        $templates = [];
        foreach (Template::getList() as $template) {
            $templates[] = ['handle' => $template->getPageTemplateHandle(), 'name' => $template->getPageTemplateDisplayName('text')];
        }

        return ['page_types' => $types, 'templates' => $templates];
    }

    private function createPage(Args $args): array
    {
        $parent = $this->resolvePage($args, 'ACTIVE', 'parent_id', 'parent_path');
        $typeHandle = $args->string('page_type', 'page');
        $type = Type::getByHandle($typeHandle);
        if (!$type) {
            throw new ToolError(sprintf('Unknown page type "%s". Use list_page_types.', $typeHandle));
        }
        $templateHandle = $args->string('template');
        $template = $templateHandle !== null ? Template::getByHandle($templateHandle) : $type->getPageTypeDefaultPageTemplateObject();
        if (!$template) {
            throw new ToolError(sprintf('Unknown page template "%s". Use list_page_types.', (string) $templateHandle));
        }
        if (!(new Checker($parent))->canAddSubCollection($type)) {
            throw new ToolError(sprintf('You cannot add pages of type "%s" below this page.', $type->getPageTypeDisplayName('text')));
        }
        $publish = $args->bool('publish');
        $data = ['cName' => $args->requireString('name'), 'cvIsApproved' => $publish];
        if (($handle = $args->string('handle')) !== null && $handle !== '') {
            $data['cHandle'] = $this->app->make('helper/text')->urlify($handle);
        }
        if (($description = $args->string('description')) !== null) {
            $data['cDescription'] = $description;
        }
        $page = $this->reloadPage($parent->add($type, $data, $template));
        if (($attributes = $args->object('attributes')) !== null && $attributes !== []) {
            $this->attributes->apply($page, $this->app->make(PageCategory::class), $attributes);
        }
        $result = $this->serializer->page($page);
        $result['published'] = $publish;
        if (!$publish) {
            $result['note'] = 'The page was created unapproved and is not visible to visitors yet. Use approve_page_version to publish it.';
        }

        return $result;
    }

    private function updatePage(Args $args): array
    {
        $page = $this->resolvePage($args, 'RECENT');
        $checker = new Checker($page);
        if (!$checker->canEditPageContents() && !$checker->canEditPageProperties()) {
            throw new ToolError('You cannot edit this page.');
        }
        $data = [];
        if (($name = $args->string('name')) !== null) {
            $data['cName'] = $name;
        }
        if (($description = $args->string('description')) !== null) {
            $data['cDescription'] = $description;
        }
        if (($handle = $args->string('handle')) !== null) {
            $data['cHandle'] = $this->app->make('helper/text')->urlify($handle);
        }
        $attributes = $args->object('attributes', []);
        if (($data !== [] || $attributes !== []) && !$checker->canEditPageProperties()) {
            throw new ToolError('You cannot edit the properties of this page.');
        }
        if (($templateHandle = $args->string('template')) !== null) {
            if (!$checker->canEditPageTemplate()) {
                throw new ToolError('You cannot change the template of this page.');
            }
            $template = Template::getByHandle($templateHandle);
            if (!$template) {
                throw new ToolError(sprintf('Unknown page template "%s".', $templateHandle));
            }
            $data['pTemplateID'] = $template->getPageTemplateID();
        }
        if (($typeHandle = $args->string('page_type')) !== null) {
            if (!$checker->canEditPageType()) {
                throw new ToolError('You cannot change the page type of this page.');
            }
            $type = Type::getByHandle($typeHandle);
            if (!$type) {
                throw new ToolError(sprintf('Unknown page type "%s".', $typeHandle));
            }
            $data['ptID'] = $type->getPageTypeID();
        }
        if ($data === [] && $attributes === [] && !$args->bool('approve')) {
            throw new ToolError('Nothing to update.');
        }

        $version = $page->getVersionToModify();
        if ($attributes !== []) {
            $this->attributes->apply($version, $this->app->make(PageCategory::class), $attributes);
        }
        if ($data !== []) {
            $version->update($data);
        }
        if (($comment = $args->string('version_comment')) !== null) {
            $version->getVersionObject()->setComment($comment);
        }
        $status = $args->bool('approve') ? $this->approveRecentVersion($page) : 'Saved as an unapproved version. Use approve_page_version to publish.';
        $recent = $this->reloadPage($page);
        $result = [
            'page' => $this->serializer->pageSummary($recent),
            'version' => $this->serializer->version($recent->getVersionObject()),
            'status' => $status,
        ];

        return $result;
    }

    private function movePage(Args $args): array
    {
        $page = $this->resolvePage($args);
        $target = Page::getByID($args->requireInt('new_parent_id'));
        if (!$target || $target->isError()) {
            throw new ToolError('New parent page not found.');
        }
        if ((int) $page->getCollectionID() === (int) Page::getHomePageID()) {
            throw new ToolError('The home page cannot be moved.');
        }
        if (str_starts_with($target->getCollectionPath() . '/', $page->getCollectionPath() . '/') && $page->getCollectionPath() !== '') {
            throw new ToolError('A page cannot be moved below itself.');
        }
        if (!(new Checker($page))->canMoveOrCopyPage()) {
            throw new ToolError('You cannot move this page.');
        }
        if (!(new Checker($target))->canAddSubpage($page->getPageTypeObject())) {
            throw new ToolError('You cannot add this type of page below the target page.');
        }
        $request = new MovePageRequest();
        $request->setRequestedPage($page);
        $request->setRequestedTargetPage($target);
        $request->setSaveOldPagePath($args->bool('keep_old_path', true));
        $request->setRequesterUserID($this->app->make(User::class)->getUserID());
        $response = $request->trigger();
        $moved = $this->reloadPage($page, 'ACTIVE');

        return [
            'page' => $this->serializer->pageSummary($moved),
            'status' => $response instanceof WorkflowResponse ? 'Moved.' : 'The move request is waiting for workflow approval.',
        ];
    }

    private function deletePage(Args $args): array
    {
        $page = $this->resolvePage($args);
        if ((int) $page->getCollectionID() === (int) Page::getHomePageID()) {
            throw new ToolError('The home page cannot be deleted.');
        }
        if (!(new Checker($page))->canDeletePage()) {
            throw new ToolError('You cannot delete this page.');
        }
        $summary = $this->serializer->pageSummary($page);
        $response = $this->app->executeCommand(new DeletePageCommand($page->getCollectionID(), $this->app->make(User::class)->getUserID()));
        $after = $this->reloadPage($page, 'ACTIVE');

        return [
            'page' => $summary,
            'status' => ($after && !$after->isError() && $after->isInTrash()) ? 'Moved to the trash.'
                : ($response instanceof WorkflowResponse ? 'Deleted.' : 'The delete request is waiting for workflow approval.'),
        ];
    }

    private function listVersions(Args $args): array
    {
        $page = $this->resolvePage($args, 'RECENT');
        if (!(new Checker($page))->canViewPageVersions()) {
            throw new ToolError('You cannot view the versions of this page.');
        }
        $list = new VersionList($page);
        $versions = [];
        foreach ($list->get(max(1, min(100, (int) $args->int('limit', 20)))) as $version) {
            $versions[] = $this->serializer->version($version);
        }

        return ['page_id' => (int) $page->getCollectionID(), 'versions' => $versions];
    }

    private function approveVersion(Args $args): array
    {
        $page = $this->resolvePage($args, 'RECENT');
        $versionId = $args->int('version_id');
        if ($versionId === null) {
            return ['status' => $this->approveRecentVersion($page)];
        }
        if (!(new Checker($page))->canApprovePageVersions()) {
            throw new ToolError('You cannot approve versions of this page.');
        }
        $version = Version::get($page, $versionId);
        if (!$version || $version->isError()) {
            throw new ToolError('Version not found.');
        }
        $request = new ApprovePageRequest();
        $request->setRequestedPage($page);
        $request->setRequestedVersionID($versionId);
        $request->setRequesterUserID($this->app->make(User::class)->getUserID());
        $response = $request->trigger();

        return [
            'status' => $response instanceof WorkflowResponse
                ? sprintf('Version %d is now live.', $versionId)
                : sprintf('Version %d was submitted for approval through the page workflow.', $versionId),
        ];
    }
}
