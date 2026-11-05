<?php

namespace Ortic\McpServer\Http;

use Concrete\Core\Routing\Router;

class RouteList
{
    public function __construct(private Router $router)
    {
    }

    public function register(): void
    {
        $r = $this->router;
        $r->all(Urls::ENDPOINT_PATH, McpController::class . '::handle');

        $r->all('/.well-known/oauth-protected-resource', WellKnownController::class . '::protectedResource');
        $r->all('/.well-known/oauth-protected-resource' . Urls::ENDPOINT_PATH, WellKnownController::class . '::protectedResource');
        $r->all('/.well-known/oauth-authorization-server', WellKnownController::class . '::authorizationServer');

        $r->all(Urls::ENDPOINT_PATH . '/oauth/authorize', OAuthController::class . '::authorize');
        $r->all(Urls::ENDPOINT_PATH . '/oauth/token', OAuthController::class . '::token');
        $r->all(Urls::ENDPOINT_PATH . '/oauth/register', OAuthController::class . '::register');
        $r->all(Urls::ENDPOINT_PATH . '/oauth/revoke', OAuthController::class . '::revoke');
    }
}
