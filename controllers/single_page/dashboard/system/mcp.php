<?php

namespace Concrete\Package\OrticMcpServer\Controller\SinglePage\Dashboard\System;

use Concrete\Core\Page\Controller\DashboardPageController;

defined('C5_EXECUTE') or die('Access Denied.');

class Mcp extends DashboardPageController
{
    public function view()
    {
        return $this->buildRedirectToFirstAccessibleChildPage();
    }
}
