<?php

namespace Ortic\McpServer\Auth;

use Concrete\Core\Database\Connection\Connection;
use Ortic\McpServer\Settings;

/**
 * OAuth 2.1 clients and authorization codes (PKCE S256 is mandatory).
 */
class OAuthService
{
    private const CLIENTS = 'OrticMcpOAuthClients';
    private const CODES = 'OrticMcpOAuthCodes';
    private const CODE_TTL = 600;
    private const MAX_DYNAMIC_CLIENTS = 1000;
    private const MAX_REGISTRATIONS_PER_IP_PER_HOUR = 20;
    public const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    public function __construct(
        private Connection $db,
        private Settings $settings
    ) {
    }

    /**
     * Dynamic client registration (RFC 7591).
     *
     * @throws OAuthException
     */
    public function registerClient(array $metadata, string $ip): array
    {
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::CLIENTS . ' WHERE isDynamic = 1');
        if ($count >= self::MAX_DYNAMIC_CLIENTS) {
            throw new OAuthException('invalid_client_metadata', 'Too many registered clients. Remove unused clients in the dashboard.');
        }
        $recent = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . self::CLIENTS . ' WHERE isDynamic = 1 AND createdIp = ? AND createdAt > ?',
            [substr($ip, 0, 64), date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($recent >= self::MAX_REGISTRATIONS_PER_IP_PER_HOUR) {
            throw new OAuthException('invalid_client_metadata', 'Too many registrations from this address, try again later.', 429);
        }

        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || $redirectUris === []) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris must be a non-empty array.');
        }
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || ($reason = $this->whyRedirectUriIsNotAllowed($uri)) !== null) {
                throw new OAuthException('invalid_redirect_uri', is_string($uri) ? $reason : 'redirect_uris must contain strings.');
            }
        }
        $grantTypes = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grantTypes) || array_diff($grantTypes, ['authorization_code', 'refresh_token']) !== []) {
            throw new OAuthException('invalid_client_metadata', 'Only the authorization_code and refresh_token grant types are supported.');
        }
        $responseTypes = $metadata['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || array_diff($responseTypes, ['code']) !== []) {
            throw new OAuthException('invalid_client_metadata', 'Only the "code" response type is supported.');
        }
        $authMethod = $metadata['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (!in_array($authMethod, self::AUTH_METHODS, true)) {
            throw new OAuthException('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }
        $name = is_string($metadata['client_name'] ?? null) ? trim($metadata['client_name']) : '';
        if ($name === '') {
            $name = parse_url($redirectUris[0], PHP_URL_HOST) ?: 'MCP client';
        }

        $client = $this->createClient(mb_substr($name, 0, 255), array_values($redirectUris), $authMethod, true, $ip);

        $response = [
            'client_id' => $client['client_id'],
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => array_values($redirectUris),
            'grant_types' => array_values($grantTypes),
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
        ];
        if ($client['client_secret'] !== null) {
            $response['client_secret'] = $client['client_secret'];
            $response['client_secret_expires_at'] = 0;
        }

        return $response;
    }

    /**
     * @return array{client_id: string, client_secret: ?string}
     */
    public function createClient(string $name, array $redirectUris, string $authMethod, bool $dynamic, string $ip): array
    {
        $clientId = 'cmcp_' . bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? null : 'cmcp_cs_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert(self::CLIENTS, [
            'clientID' => $clientId,
            'clientSecretHash' => $secret !== null ? TokenService::hash($secret) : null,
            'name' => $name,
            'redirectUris' => json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES),
            'authMethod' => $authMethod,
            'isDynamic' => $dynamic ? 1 : 0,
            'createdAt' => date('Y-m-d H:i:s'),
            'createdIp' => substr($ip, 0, 64),
        ]);

        return ['client_id' => $clientId, 'client_secret' => $secret];
    }

    public function getClient(string $clientId): ?array
    {
        if ($clientId === '' || strlen($clientId) > 128) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT * FROM ' . self::CLIENTS . ' WHERE clientID = ?', [$clientId]);
        if (!$row) {
            return null;
        }
        $row['redirectUris'] = json_decode((string) $row['redirectUris'], true) ?: [];

        return $row;
    }

    public function listClients(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM ' . self::CLIENTS . ' ORDER BY createdAt DESC');
        foreach ($rows as &$row) {
            $row['redirectUris'] = json_decode((string) $row['redirectUris'], true) ?: [];
        }

        return $rows;
    }

    public function deleteClient(string $clientId): void
    {
        $this->db->delete(self::CLIENTS, ['clientID' => $clientId]);
        $this->db->delete(self::CODES, ['clientID' => $clientId]);
    }

    /**
     * Check client credentials at the token/revocation endpoints.
     *
     * @throws OAuthException
     */
    public function authenticateClient(?string $clientId, ?string $clientSecret): array
    {
        $client = $clientId !== null ? $this->getClient($clientId) : null;
        if (!$client) {
            throw new OAuthException('invalid_client', 'Unknown client.', 401);
        }
        if ($client['authMethod'] !== 'none') {
            if ($clientSecret === null || !hash_equals((string) $client['clientSecretHash'], TokenService::hash($clientSecret))) {
                throw new OAuthException('invalid_client', 'Client authentication failed.', 401);
            }
        }

        return $client;
    }

    /**
     * Match a redirect URI against the ones registered for the client.
     * Loopback URIs may use any port (RFC 8252, section 7.3).
     */
    public function isRegisteredRedirectUri(array $client, string $uri): bool
    {
        foreach ($client['redirectUris'] as $registered) {
            if ($registered === $uri) {
                return true;
            }
            if ($this->isLoopback($registered) && $this->isLoopback($uri) && $this->withoutPort($registered) === $this->withoutPort($uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null why the URI cannot be used, or null if it is fine
     */
    public function whyRedirectUriIsNotAllowed(string $uri): ?string
    {
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme'])) {
            return 'Redirect URIs must be absolute.';
        }
        if (isset($parts['fragment'])) {
            return 'Redirect URIs must not contain a fragment.';
        }
        $scheme = strtolower($parts['scheme']);
        if (in_array($scheme, ['javascript', 'data', 'file', 'vbscript'], true)) {
            return 'Redirect URI scheme not allowed.';
        }
        if ($scheme === 'http' && !$this->isLoopback($uri)) {
            return 'Plain http redirect URIs are only allowed for localhost.';
        }
        $allowlist = $this->settings->getRedirectAllowlist();
        if ($allowlist === []) {
            // Without an allowlist only https and loopback URIs are accepted.
            return ($scheme === 'https' || $this->isLoopback($uri)) ? null : 'Custom scheme redirect URIs must be added to the allowlist.';
        }
        foreach ($allowlist as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $uri)) {
                return null;
            }
        }

        return sprintf('The redirect URI %s is not in the allowlist configured in the dashboard (System & Settings > MCP Server > Settings).', $uri);
    }

    /**
     * @return string the plain-text authorization code
     */
    public function createAuthorizationCode(string $clientId, int $userId, string $redirectUri, string $codeChallenge, array $scopes, ?string $resource): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert(self::CODES, [
            'codeHash' => TokenService::hash($code),
            'clientID' => $clientId,
            'uID' => $userId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'scopes' => Scopes::toString($scopes),
            'resource' => $resource !== null ? substr($resource, 0, 255) : null,
            'createdAt' => date('Y-m-d H:i:s'),
            'expiresAt' => date('Y-m-d H:i:s', time() + self::CODE_TTL),
        ]);
        // Opportunistic cleanup of old codes.
        $this->db->executeStatement('DELETE FROM ' . self::CODES . ' WHERE expiresAt < ?', [date('Y-m-d H:i:s', time() - 86400)]);

        return $code;
    }

    /**
     * Validate and consume an authorization code.
     *
     * @throws OAuthException
     *
     * @return array the code row; its grant ID is "code-{id}"
     */
    public function consumeAuthorizationCode(string $code, array $client, ?string $redirectUri, ?string $codeVerifier, TokenService $tokens): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . self::CODES . ' WHERE codeHash = ?', [TokenService::hash($code)]);
        if (!$row || $row['clientID'] !== $client['clientID']) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid.');
        }
        if ($row['usedAt'] !== null) {
            // Code replay: revoke what was issued with it (RFC 6749, section 4.1.2).
            $tokens->revokeGrant('code-' . $row['id']);
            throw new OAuthException('invalid_grant', 'The authorization code has already been used.');
        }
        // A code can be presented only once, whether the exchange succeeds or not.
        $this->db->update(self::CODES, ['usedAt' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
        if (strtotime($row['expiresAt']) <= time()) {
            throw new OAuthException('invalid_grant', 'The authorization code has expired.');
        }
        if ($redirectUri !== null && $redirectUri !== $row['redirectUri']) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        if ($codeVerifier === null || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier)) {
            throw new OAuthException('invalid_grant', 'A valid code_verifier is required.');
        }
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($row['codeChallenge'], $challenge)) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }

        return $row;
    }

    private function isLoopback(string $uri): bool
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        return strtolower((string) parse_url($uri, PHP_URL_SCHEME)) === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private function withoutPort(string $uri): string
    {
        return (string) preg_replace('#^(https?://[^/:]+|https?://\[[^\]]+\]):\d+#i', '$1', $uri);
    }
}
