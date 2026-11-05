<?php

namespace Concrete\Package\OrticMcpServer\Controller\SinglePage\Dashboard\System\Mcp;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Developer\FileHistory as History;
use Ortic\McpServer\Mcp\ToolError;

defined('C5_EXECUTE') or die('Access Denied.');

class FileHistory extends DashboardPageController
{
    private const PER_PAGE = 50;

    public function view()
    {
        $path = trim((string) $this->request->query->get('path'));
        $page = max(1, (int) $this->request->query->get('p', 1));
        [$rows, $total] = $this->app->make(History::class)->search($path, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $this->set('rows', $rows);
        $this->set('total', $total);
        $this->set('page', $page);
        $this->set('pages', max(1, (int) ceil($total / self::PER_PAGE)));
        $this->set('filterPath', $path);
        $this->set('users', $this->app->make(UserInfoRepository::class));
    }

    public function show($id = null)
    {
        $entry = $this->app->make(History::class)->get((int) $id);
        if (!$entry) {
            $this->error->add(t('History entry not found.'));

            return $this->view();
        }
        $this->set('entry', $entry);
        $absolute = DIR_BASE . '/' . $entry['path'];
        $this->set('currentContent', is_file($absolute) ? (string) file_get_contents($absolute) : null);
        $this->set('users', $this->app->make(UserInfoRepository::class));
    }

    public function restore()
    {
        if (!$this->token->validate('ortic_mcp_restore')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        try {
            $result = $this->app->make(History::class)->restore(
                (int) $this->request->request->get('id'),
                (int) $this->app->make(User::class)->getUserID(),
                null,
                true
            );
            $this->flash('success', t('%s: %s.', $result['path'], $result['result']));
        } catch (ToolError $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->buildRedirect($this->action());
    }
}
