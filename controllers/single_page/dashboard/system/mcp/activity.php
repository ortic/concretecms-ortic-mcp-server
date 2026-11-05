<?php

namespace Concrete\Package\OrticMcpServer\Controller\SinglePage\Dashboard\System\Mcp;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Audit\AuditLog;

defined('C5_EXECUTE') or die('Access Denied.');

class Activity extends DashboardPageController
{
    private const PER_PAGE = 50;

    public function view()
    {
        $query = $this->request->query;
        $filters = [
            'tool' => trim((string) $query->get('tool')),
            'success' => $query->get('status') === 'failed' ? 0 : ($query->get('status') === 'ok' ? 1 : ''),
        ];
        $page = max(1, (int) $query->get('p', 1));
        [$rows, $total] = $this->app->make(AuditLog::class)->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $this->set('rows', $rows);
        $this->set('total', $total);
        $this->set('page', $page);
        $this->set('pages', max(1, (int) ceil($total / self::PER_PAGE)));
        $this->set('filterTool', $filters['tool']);
        $this->set('filterStatus', (string) $query->get('status'));
        $this->set('users', $this->app->make(UserInfoRepository::class));
    }

    public function purge()
    {
        if (!$this->token->validate('ortic_mcp_purge_log')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $days = max(1, (int) $this->request->request->get('days', 90));
        $count = $this->app->make(AuditLog::class)->purgeOlderThan($days);
        $this->flash('success', t2('%d entry deleted.', '%d entries deleted.', $count, $count));

        return $this->buildRedirect($this->action());
    }
}
