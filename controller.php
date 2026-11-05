<?php

namespace Concrete\Package\OrticMcpServer;

use Concrete\Core\Database\EntityManager\Provider\ProviderInterface;
use Concrete\Core\Package\Package;
use Ortic\McpServer\Http\RouteList;

defined('C5_EXECUTE') or die('Access Denied.');

class Controller extends Package implements ProviderInterface
{
    protected $pkgHandle = 'ortic_mcp_server';

    protected $pkgVersion = '0.1.0';

    protected $appVersionRequired = '9.5.0';

    protected $phpVersionRequired = '8.1';

    protected $pkgAutoloaderRegistries = [
        'src' => 'Ortic\\McpServer',
    ];

    public function getPackageName()
    {
        return t('MCP Server');
    }

    public function getPackageDescription()
    {
        return t('Lets AI assistants such as Claude work with this site through the Model Context Protocol.');
    }

    /**
     * The package stores its data with db.xml tables, so Doctrine must not scan src/ for entities.
     */
    public function getDrivers()
    {
        return [];
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installContentFile('install.xml');

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installContentFile('install.xml');
    }

    public function on_start()
    {
        $this->app->make(RouteList::class)->register();
    }
}
