<?php

namespace Ortic\McpServer\Http;

use Concrete\Core\Controller\AbstractController;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Auth\Permissions;
use Ortic\McpServer\Auth\Scopes;
use Ortic\McpServer\Auth\TokenService;
use Ortic\McpServer\Mcp\Server;
use Ortic\McpServer\Mcp\ToolContext;
use Ortic\McpServer\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint (Streamable HTTP transport, JSON responses only, no sessions).
 */
class McpController extends AbstractController
{
    use JsonResponses;

    public function handle(): Response
    {
        $settings = $this->app->make(Settings::class);
        $origin = $this->request->headers->get('Origin');
        if ($origin !== null && !$this->isOriginAllowed($origin, $settings)) {
            // Required by the MCP spec to prevent DNS rebinding attacks.
            return $this->json(['error' => 'origin_not_allowed', 'error_description' => 'This Origin is not allowed to call the MCP endpoint.'], 403);
        }
        $method = $this->request->getMethod();
        if ($method === 'OPTIONS') {
            return $this->withCors(new Response('', 204), $origin);
        }
        if (!$settings->isEnabled()) {
            return $this->withCors($this->json(['error' => 'disabled', 'error_description' => 'The MCP server is disabled on this site.'], 503), $origin);
        }
        if ($method !== 'POST') {
            // No server-to-client event stream and no sessions to terminate.
            $response = new Response('', 405);
            $response->headers->set('Allow', 'POST, OPTIONS');

            return $this->withCors($response, $origin);
        }

        $token = $this->getBearerToken();
        $access = $token !== null ? $this->app->make(TokenService::class)->authenticate($token, $this->clientIp()) : null;
        $user = $access ? $this->app->make(UserInfoRepository::class)->getByID($access->userId) : null;
        if (!$access || !$user || !$user->isActive()) {
            return $this->withCors($this->unauthorized($token !== null, $settings), $origin);
        }

        // From here on, Concrete evaluates every permission for the token owner.
        $this->request->setCustomRequestUser($user);
        $this->app->forgetInstance(User::class);
        if (!Permissions::canUseMcp()) {
            return $this->withCors($this->json([
                'error' => 'insufficient_permissions',
                'error_description' => 'This user is not allowed to use the MCP server (task permission "Use the MCP server").',
            ], 403), $origin);
        }

        $protocolVersion = $this->request->headers->get('MCP-Protocol-Version');
        if ($protocolVersion !== null && !in_array($protocolVersion, Server::PROTOCOL_VERSIONS, true)) {
            return $this->withCors($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Unsupported MCP-Protocol-Version: ' . $protocolVersion],
            ], 400), $origin);
        }

        $payload = json_decode((string) $this->request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->withCors($this->json(Server::parseError(), 400), $origin);
        }

        $developerAllowed = $access->hasScope(Scopes::DEVELOPER)
            && $settings->isDeveloperEnabled()
            && Permissions::canUseDeveloperTools();
        $context = new ToolContext($user, $access, $settings, $developerAllowed, $this->clientIp());

        $result = $this->app->make(Server::class)->handlePayload($payload, $context);
        if ($result === null) {
            return $this->withCors(new Response('', 202), $origin);
        }

        return $this->withCors($this->json($result), $origin);
    }

    private function getBearerToken(): ?string
    {
        $header = (string) $this->request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return $m[1];
        }

        return null;
    }

    private function unauthorized(bool $tokenWasSent, Settings $settings): Response
    {
        $urls = $this->app->make(Urls::class);
        $challenge = 'Bearer realm="mcp"';
        if ($settings->isOAuthEnabled()) {
            $challenge .= ', resource_metadata="' . $urls->protectedResourceMetadata() . '"';
        }
        if ($tokenWasSent) {
            $challenge .= ', error="invalid_token", error_description="The access token is invalid, expired or revoked."';
        }
        $response = $this->json([
            'error' => $tokenWasSent ? 'invalid_token' : 'unauthorized',
            'error_description' => $tokenWasSent ? 'The access token is invalid, expired or revoked.' : 'Authentication required.',
        ], 401);
        $response->headers->set('WWW-Authenticate', $challenge);

        return $response;
    }

    private function isOriginAllowed(string $origin, Settings $settings): bool
    {
        $origin = rtrim($origin, '/');
        if (strcasecmp($origin, $this->app->make(Urls::class)->origin()) === 0) {
            return true;
        }
        foreach ($settings->getAllowedOrigins() as $allowed) {
            if ($allowed === '*' || strcasecmp(rtrim($allowed, '/'), $origin) === 0) {
                return true;
            }
        }

        return false;
    }

    private function withCors(Response $response, ?string $origin): Response
    {
        if ($origin === null) {
            return $response;
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id, Last-Event-ID');
        $response->headers->set('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id, MCP-Protocol-Version');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
