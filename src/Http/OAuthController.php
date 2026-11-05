<?php

namespace Ortic\McpServer\Http;

use Concrete\Core\Controller\AbstractController;
use Concrete\Core\User\PostLoginLocation;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Audit\AuditLog;
use Ortic\McpServer\Auth\OAuthException;
use Ortic\McpServer\Auth\OAuthService;
use Ortic\McpServer\Auth\Permissions;
use Ortic\McpServer\Auth\Scopes;
use Ortic\McpServer\Auth\TokenService;
use Ortic\McpServer\Settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth 2.1 authorization server endpoints for MCP clients.
 */
class OAuthController extends AbstractController
{
    use JsonResponses;

    private const CSRF_ACTION = 'ortic_mcp_authorize';

    public function authorize(): Response
    {
        $settings = $this->app->make(Settings::class);
        if (!$settings->isEnabled() || !$settings->isOAuthEnabled()) {
            return $this->errorPage(t('Connecting apps with OAuth is disabled on this site.'), 404);
        }
        $isPost = $this->request->getMethod() === 'POST';
        $params = $this->stringParams($isPost ? $this->request->request->all() : $this->request->query->all());
        $oauth = $this->app->make(OAuthService::class);

        // Until the client and redirect URI are validated, errors are shown here instead of redirected.
        $client = $oauth->getClient($params['client_id'] ?? '');
        if (!$client) {
            return $this->errorPage(t('The application that sent you here is not registered on this site. Try connecting it again.'), 400);
        }
        $redirectUri = $params['redirect_uri'] ?? '';
        if ($redirectUri === '' && count($client['redirectUris']) === 1) {
            $redirectUri = $client['redirectUris'][0];
        }
        if (!$oauth->isRegisteredRedirectUri($client, $redirectUri)) {
            return $this->errorPage(t('The redirect address of this request does not belong to the application.'), 400);
        }
        if (($reason = $oauth->whyRedirectUriIsNotAllowed($redirectUri)) !== null) {
            return $this->errorPage($reason, 400);
        }
        $state = $params['state'] ?? null;

        if (($params['response_type'] ?? '') !== 'code') {
            return $this->redirectError($redirectUri, $state, 'unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = $params['code_challenge'] ?? '';
        if (($params['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
            return $this->redirectError($redirectUri, $state, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $resource = $params['resource'] ?? null;
        if ($resource !== null && $resource !== '' && !$this->isValidResource($resource)) {
            return $this->redirectError($redirectUri, $state, 'invalid_target', 'The requested resource is not served by this authorization server.');
        }
        $requestedScopes = Scopes::normalize($params['scope'] ?? '');

        $user = $this->app->make(User::class);
        if (!$user->isRegistered()) {
            if ($isPost) {
                return $this->errorPage(t('Your session has expired. Please start the connection again from your app.'), 400);
            }
            $this->app->make(PostLoginLocation::class)->setSessionPostLoginUrl($this->request->getUri());

            return new RedirectResponse((string) $this->app->make(Urls::class)->to('/login'));
        }
        if (!Permissions::canUseMcp()) {
            return $this->errorPage(t('Your account is not allowed to connect AI assistants to this site. Ask an administrator to grant you the "Use the MCP server" task permission.'), 403);
        }
        $developerAvailable = $settings->isDeveloperEnabled() && Permissions::canUseDeveloperTools();

        if (!$isPost) {
            return $this->consentPage($client, $redirectUri, $params, $requestedScopes, $developerAvailable, $user);
        }

        $csrf = $this->app->make('token');
        if (!$csrf->validate(self::CSRF_ACTION, $params['ccm_token'] ?? '')) {
            return $this->errorPage(t('The form has expired. Please start the connection again from your app.'), 400);
        }
        if (($params['decision'] ?? '') !== 'approve') {
            return $this->redirectError($redirectUri, $state, 'access_denied', 'The user denied the request.');
        }

        $scopes = [Scopes::STANDARD];
        if ($developerAvailable && !empty($params['grant_developer'])) {
            $scopes[] = Scopes::DEVELOPER;
        }
        $code = $oauth->createAuthorizationCode($client['clientID'], (int) $user->getUserID(), $redirectUri, $challenge, $scopes, $resource);
        $this->app->make(AuditLog::class)->log('oauth_authorized', (int) $user->getUserID(), null, null, [
            'client' => $client['name'],
            'client_id' => $client['clientID'],
            'scopes' => $scopes,
        ], true, null, null, $this->clientIp());

        return new RedirectResponse($this->appendQuery($redirectUri, [
            'code' => $code,
            'state' => $state,
            'iss' => $this->app->make(Urls::class)->issuer(),
        ]));
    }

    public function token(): Response
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            return $this->withPublicCors(new Response('', 204));
        }
        if ($this->request->getMethod() !== 'POST') {
            return $this->withPublicCors($this->json(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405));
        }
        $settings = $this->app->make(Settings::class);
        $oauth = $this->app->make(OAuthService::class);
        $tokens = $this->app->make(TokenService::class);
        $params = $this->stringParams($this->request->request->all());

        try {
            if (!$settings->isEnabled() || !$settings->isOAuthEnabled()) {
                throw new OAuthException('access_denied', 'OAuth is disabled on this site.', 403);
            }
            [$clientId, $clientSecret] = $this->clientCredentials($params);
            $client = $oauth->authenticateClient($clientId, $clientSecret);
            switch ($params['grant_type'] ?? '') {
                case 'authorization_code':
                    $row = $oauth->consumeAuthorizationCode($params['code'] ?? '', $client, $params['redirect_uri'] ?? null, $params['code_verifier'] ?? null, $tokens);
                    $user = $this->app->make(UserInfoRepository::class)->getByID((int) $row['uID']);
                    if (!$user || !$user->isActive()) {
                        throw new OAuthException('invalid_grant', 'The user account is no longer active.');
                    }
                    $result = $tokens->issueOAuthTokens((int) $row['uID'], $client['clientID'], Scopes::normalize($row['scopes']), 'code-' . $row['id']);
                    $tokens->purgeExpired();
                    break;
                case 'refresh_token':
                    $result = $tokens->refresh($params['refresh_token'] ?? '', $client['clientID'], $params['scope'] ?? null);
                    break;
                default:
                    throw new OAuthException('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.');
            }

            return $this->withPublicCors($this->json($result));
        } catch (OAuthException $e) {
            $response = $this->json($e->toArray(), $e->status);
            if ($e->status === 401) {
                $response->headers->set('WWW-Authenticate', 'Basic realm="mcp"');
            }

            return $this->withPublicCors($response);
        }
    }

    public function register(): Response
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            return $this->withPublicCors(new Response('', 204));
        }
        if ($this->request->getMethod() !== 'POST') {
            return $this->withPublicCors($this->json(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405));
        }
        $settings = $this->app->make(Settings::class);
        if (!$settings->isEnabled() || !$settings->isDynamicRegistrationEnabled()) {
            return $this->withPublicCors($this->json(['error' => 'access_denied', 'error_description' => 'Dynamic client registration is disabled. Register the client in the dashboard instead.'], 403));
        }
        $metadata = json_decode((string) $this->request->getContent(), true);
        if (!is_array($metadata)) {
            return $this->withPublicCors($this->json(['error' => 'invalid_client_metadata', 'error_description' => 'The request body must be a JSON object.'], 400));
        }
        try {
            $result = $this->app->make(OAuthService::class)->registerClient($metadata, $this->clientIp());
            $this->app->make(AuditLog::class)->log('oauth_client_registered', null, null, null, [
                'client_id' => $result['client_id'],
                'client_name' => $result['client_name'],
                'redirect_uris' => $result['redirect_uris'],
            ], true, null, null, $this->clientIp());

            return $this->withPublicCors($this->json($result, 201));
        } catch (OAuthException $e) {
            return $this->withPublicCors($this->json($e->toArray(), $e->status));
        }
    }

    public function revoke(): Response
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            return $this->withPublicCors(new Response('', 204));
        }
        if ($this->request->getMethod() !== 'POST') {
            return $this->withPublicCors($this->json(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405));
        }
        $params = $this->stringParams($this->request->request->all());
        try {
            [$clientId, $clientSecret] = $this->clientCredentials($params);
            $client = $this->app->make(OAuthService::class)->authenticateClient($clientId, $clientSecret);
            $this->app->make(TokenService::class)->revokeByValue($params['token'] ?? '', $client['clientID']);
        } catch (OAuthException $e) {
            return $this->withPublicCors($this->json($e->toArray(), $e->status));
        }

        // RFC 7009: respond 200 even if the token was unknown.
        return $this->withPublicCors(new Response('', 200));
    }

    private function consentPage(array $client, string $redirectUri, array $params, array $requestedScopes, bool $developerAvailable, User $user): Response
    {
        $view = [
            'siteName' => (string) app('site')->getSite()->getSiteName(),
            'clientName' => (string) $client['name'],
            'redirectHost' => (string) parse_url($redirectUri, PHP_URL_HOST),
            'userName' => (string) $user->getUserName(),
            'developerAvailable' => $developerAvailable,
            'developerRequested' => in_array(Scopes::DEVELOPER, $requestedScopes, true),
            'formAction' => (string) $this->app->make(Urls::class)->to(Urls::ENDPOINT_PATH . '/oauth/authorize'),
            'hidden' => array_intersect_key($params, array_flip(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'])) + ['redirect_uri' => $redirectUri],
            'csrfToken' => $this->app->make('token')->generate(self::CSRF_ACTION),
            'logoutUrl' => (string) \Concrete\Core\Support\Facade\Url::to('/login', 'logout', $this->app->make('token')->generate('logout')),
        ];

        return $this->renderPage('consent', $view, 200);
    }

    private function errorPage(string $message, int $status): Response
    {
        return $this->renderPage('error', [
            'siteName' => (string) app('site')->getSite()->getSiteName(),
            'message' => $message,
        ], $status);
    }

    private function renderPage(string $template, array $vars, int $status): Response
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__, 2) . '/views/oauth/' . $template . '.php';
        $html = (string) ob_get_clean();

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function redirectError(string $redirectUri, ?string $state, string $error, string $description): Response
    {
        return new RedirectResponse($this->appendQuery($redirectUri, [
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
            'iss' => $this->app->make(Urls::class)->issuer(),
        ]));
    }

    private function appendQuery(string $uri, array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{0: ?string, 1: ?string} client ID and secret from HTTP Basic auth or the request body
     */
    private function clientCredentials(array $params): array
    {
        $header = (string) $this->request->headers->get('Authorization', '');
        if (preg_match('/^Basic\s+(\S+)$/i', $header, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [urldecode($id), urldecode($secret)];
            }
        }

        return [$params['client_id'] ?? null, $params['client_secret'] ?? null];
    }

    private function isValidResource(string $resource): bool
    {
        $urls = $this->app->make(Urls::class);
        $resource = rtrim($resource, '/');

        return $resource === rtrim($urls->endpoint(), '/') || $resource === $urls->issuer() || str_starts_with($resource, $urls->issuer() . '/');
    }

    /** @return array<string, string> only scalar parameters, as strings */
    private function stringParams(array $params): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }
}
