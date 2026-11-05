<?php

namespace Ortic\McpServer\Http;

use Concrete\Core\Controller\AbstractController;
use Ortic\McpServer\Auth\OAuthService;
use Ortic\McpServer\Auth\Scopes;
use Ortic\McpServer\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth discovery documents used by MCP clients (RFC 9728 and RFC 8414).
 */
class WellKnownController extends AbstractController
{
    use JsonResponses;

    public function protectedResource(): Response
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            return $this->withPublicCors(new Response('', 204));
        }
        $settings = $this->app->make(Settings::class);
        if (!$settings->isEnabled() || !$settings->isOAuthEnabled()) {
            return $this->withPublicCors($this->json(['error' => 'not_found'], 404));
        }
        $urls = $this->app->make(Urls::class);

        return $this->withPublicCors($this->json([
            'resource' => $urls->endpoint(),
            'authorization_servers' => [$urls->issuer()],
            'scopes_supported' => Scopes::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string) app('site')->getSite()->getSiteName(),
        ]));
    }

    public function authorizationServer(): Response
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            return $this->withPublicCors(new Response('', 204));
        }
        $settings = $this->app->make(Settings::class);
        if (!$settings->isEnabled() || !$settings->isOAuthEnabled()) {
            return $this->withPublicCors($this->json(['error' => 'not_found'], 404));
        }
        $urls = $this->app->make(Urls::class);
        $data = [
            'issuer' => $urls->issuer(),
            'authorization_endpoint' => $urls->to(Urls::ENDPOINT_PATH . '/oauth/authorize'),
            'token_endpoint' => $urls->to(Urls::ENDPOINT_PATH . '/oauth/token'),
            'revocation_endpoint' => $urls->to(Urls::ENDPOINT_PATH . '/oauth/revoke'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => OAuthService::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => OAuthService::AUTH_METHODS,
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => Scopes::ALL,
            'authorization_response_iss_parameter_supported' => true,
        ];
        if ($settings->isDynamicRegistrationEnabled()) {
            $data['registration_endpoint'] = $urls->to(Urls::ENDPOINT_PATH . '/oauth/register');
        }

        return $this->withPublicCors($this->json($data));
    }
}
