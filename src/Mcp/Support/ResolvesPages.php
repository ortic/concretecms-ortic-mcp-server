<?php

namespace Ortic\McpServer\Mcp\Support;

use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Concrete\Core\Workflow\Progress\Response as WorkflowResponse;
use Concrete\Core\Workflow\Request\ApprovePageRequest;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\ToolError;

trait ResolvesPages
{
    /**
     * Load a page from "page_id" or "path" arguments.
     *
     * @param string $version ACTIVE or RECENT
     */
    protected function resolvePage(Args $args, string $version = 'ACTIVE', string $idKey = 'page_id', string $pathKey = 'path'): Page
    {
        $id = $args->int($idKey);
        $path = $args->string($pathKey);
        if ($id === null && ($path === null || $path === '')) {
            throw new ToolError(sprintf('Provide "%s" or "%s".', $idKey, $pathKey));
        }
        if ($id !== null) {
            $page = Page::getByID($id, $version);
        } else {
            $path = '/' . trim($path, '/');
            $page = $path === '/' ? Page::getByID(\Concrete\Core\Page\Page::getHomePageID(), $version) : Page::getByPath($path, $version);
        }
        if (!$page || $page->isError()) {
            throw new ToolError('Page not found.');
        }
        if ($page->isInTrash()) {
            throw new ToolError('This page is in the trash.');
        }

        return $page;
    }

    /**
     * Load a page again, bypassing the request cache (needed after it was changed in this request).
     */
    protected function reloadPage(Page $page, string $version = 'RECENT'): Page
    {
        $page->refreshCache();

        return Page::getByID($page->getCollectionID(), $version);
    }

    /**
     * Approve the most recent version of a page through the workflow, like the "Publish" button does.
     *
     * @return string human-readable outcome
     */
    protected function approveRecentVersion(Page $page): string
    {
        $recent = $this->reloadPage($page);
        $checker = new Checker($recent);
        if (!$checker->canApprovePageVersions()) {
            throw new ToolError('The changes were saved, but you are not allowed to approve page versions, so they are not published yet.');
        }
        $version = $recent->getVersionObject();
        if ($version->isApproved()) {
            return 'The most recent version was already approved.';
        }
        $request = new ApprovePageRequest();
        $request->setRequestedPage($recent);
        $request->setRequestedVersionID($version->getVersionID());
        $request->setRequesterUserID(app(User::class)->getUserID());
        $response = $request->trigger();

        return $response instanceof WorkflowResponse
            ? sprintf('Version %d is now live.', $version->getVersionID())
            : sprintf('Version %d was submitted for approval through the page workflow.', $version->getVersionID());
    }
}
