<?php

namespace Ortic\McpServer\Auth;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Settings;

/**
 * Issues and validates bearer tokens. Tokens are random strings; only their SHA-256 hash is stored.
 */
class TokenService
{
    public const KIND_PERSONAL = 'personal';
    public const KIND_ACCESS = 'access';
    public const KIND_REFRESH = 'refresh';

    private const TABLE = 'OrticMcpTokens';

    private const PREFIXES = [
        self::KIND_PERSONAL => 'cmcp_pat_',
        self::KIND_ACCESS => 'cmcp_at_',
        self::KIND_REFRESH => 'cmcp_rt_',
    ];

    public function __construct(
        private Connection $db,
        private Settings $settings,
        private UserInfoRepository $users
    ) {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array{token: string, id: int}
     */
    public function createPersonalToken(int $userId, string $name, array $scopes, ?\DateTimeInterface $expiresAt): array
    {
        return $this->insertToken(self::KIND_PERSONAL, $userId, $scopes, $name, null, null, $expiresAt);
    }

    /**
     * Issue an access + refresh token pair for an OAuth grant.
     *
     * @return array<string, mixed> RFC 6749 token response
     */
    public function issueOAuthTokens(int $userId, string $clientId, array $scopes, string $grantId): array
    {
        $accessTtl = $this->settings->getAccessTokenTtl();
        $access = $this->insertToken(self::KIND_ACCESS, $userId, $scopes, null, $clientId, $grantId, new \DateTimeImmutable('+' . $accessTtl . ' seconds'));
        $refresh = $this->insertToken(self::KIND_REFRESH, $userId, $scopes, null, $clientId, $grantId, new \DateTimeImmutable('+' . $this->settings->getRefreshTokenTtl() . ' seconds'));

        return [
            'access_token' => $access['token'],
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $refresh['token'],
            'scope' => Scopes::toString($scopes),
        ];
    }

    /**
     * Exchange a refresh token for a new token pair (the refresh token is rotated).
     *
     * @throws OAuthException
     */
    public function refresh(string $refreshToken, string $clientId, ?string $requestedScope): array
    {
        $row = $this->findByToken($refreshToken);
        if (!$row || $row['kind'] !== self::KIND_REFRESH || $row['clientID'] !== $clientId) {
            throw new OAuthException('invalid_grant', 'The refresh token is invalid.');
        }
        if ($row['revokedAt'] !== null) {
            // A rotated refresh token was used again: it may have been stolen, so end the whole grant.
            $this->revokeGrant((string) $row['grantID']);
            throw new OAuthException('invalid_grant', 'The refresh token has already been used.');
        }
        if ($this->isExpired($row)) {
            throw new OAuthException('invalid_grant', 'The refresh token has expired.');
        }
        $user = $this->users->getByID((int) $row['uID']);
        if (!$user || !$user->isActive()) {
            throw new OAuthException('invalid_grant', 'The user account is no longer active.');
        }
        $scopes = Scopes::normalize($row['scopes']);
        if ($requestedScope !== null && $requestedScope !== '') {
            // A refresh may narrow the scopes, never widen them.
            $scopes = array_values(array_intersect($scopes, Scopes::normalize($requestedScope)));
        }
        $this->db->update(self::TABLE, ['revokedAt' => $this->now()], ['id' => $row['id']]);

        return $this->issueOAuthTokens((int) $row['uID'], $clientId, $scopes, (string) $row['grantID']);
    }

    /**
     * Validate a bearer token sent to the MCP endpoint.
     */
    public function authenticate(string $token, string $ip): ?AccessContext
    {
        $row = $this->findByToken($token);
        if (!$row || !in_array($row['kind'], [self::KIND_PERSONAL, self::KIND_ACCESS], true)) {
            return null;
        }
        if ($row['revokedAt'] !== null || $this->isExpired($row)) {
            return null;
        }
        // Avoid a database write on every request.
        if ($row['lastUsedAt'] === null || strtotime($row['lastUsedAt']) < time() - 60) {
            $this->db->update(self::TABLE, ['lastUsedAt' => $this->now(), 'lastUsedIp' => substr($ip, 0, 64)], ['id' => $row['id']]);
            if ($row['grantID']) {
                $this->db->executeStatement(
                    'UPDATE OrticMcpOAuthClients SET lastUsedAt = ? WHERE clientID = ?',
                    [$this->now(), $row['clientID']]
                );
            }
        }

        return new AccessContext(
            (int) $row['id'],
            (int) $row['uID'],
            $row['kind'],
            Scopes::normalize($row['scopes']),
            $row['clientID']
        );
    }

    /**
     * Revoke a token presented by its value (RFC 7009). Revoking any token of an OAuth grant ends the grant.
     */
    public function revokeByValue(string $token, ?string $clientId): void
    {
        $row = $this->findByToken($token);
        if (!$row) {
            return;
        }
        if ($clientId !== null && $row['clientID'] !== $clientId) {
            return;
        }
        if ($row['grantID']) {
            $this->revokeGrant((string) $row['grantID']);
        } else {
            $this->revokeById((int) $row['id']);
        }
    }

    public function revokeById(int $id): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET revokedAt = ? WHERE id = ? AND revokedAt IS NULL', [$this->now(), $id]);
    }

    public function revokeGrant(string $grantId): void
    {
        if ($grantId === '') {
            return;
        }
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET revokedAt = ? WHERE grantID = ? AND revokedAt IS NULL', [$this->now(), $grantId]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET revokedAt = ? WHERE uID = ? AND revokedAt IS NULL', [$this->now(), $userId]);
    }

    public function revokeAllForClient(string $clientId): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET revokedAt = ? WHERE clientID = ? AND revokedAt IS NULL', [$this->now(), $clientId]);
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);

        return $row ?: null;
    }

    /**
     * Personal tokens that are still usable or were recently revoked/expired.
     */
    public function listPersonalTokens(?int $userId = null): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE kind = ?';
        $params = [self::KIND_PERSONAL];
        if ($userId !== null) {
            $sql .= ' AND uID = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY createdAt DESC';

        return $this->db->fetchAllAssociative($sql, $params);
    }

    /**
     * Active OAuth grants (one row per authorization), newest first.
     */
    public function listActiveGrants(?int $userId = null): array
    {
        $sql = 'SELECT t.grantID, t.uID, t.clientID, MIN(t.createdAt) AS firstIssuedAt, MAX(t.lastUsedAt) AS lastUsedAt, MAX(t.scopes) AS scopes, c.name AS clientName
            FROM ' . self::TABLE . ' t LEFT JOIN OrticMcpOAuthClients c ON c.clientID = t.clientID
            WHERE t.kind = ? AND t.revokedAt IS NULL AND (t.expiresAt IS NULL OR t.expiresAt > ?)';
        $params = [self::KIND_REFRESH, $this->now()];
        if ($userId !== null) {
            $sql .= ' AND t.uID = ?';
            $params[] = $userId;
        }
        $sql .= ' GROUP BY t.grantID, t.uID, t.clientID, c.name ORDER BY firstIssuedAt DESC';

        return $this->db->fetchAllAssociative($sql, $params);
    }

    public function purgeExpired(): void
    {
        // Keep revoked/expired rows for a week so reuse detection and the dashboard still see them.
        $cutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
        $this->db->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE kind <> ? AND ((expiresAt IS NOT NULL AND expiresAt < ?) OR (revokedAt IS NOT NULL AND revokedAt < ?))',
            [self::KIND_PERSONAL, $cutoff, $cutoff]
        );
    }

    private function insertToken(string $kind, int $userId, array $scopes, ?string $name, ?string $clientId, ?string $grantId, ?\DateTimeInterface $expiresAt): array
    {
        $token = self::PREFIXES[$kind] . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert(self::TABLE, [
            'uID' => $userId,
            'kind' => $kind,
            'name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'tokenHash' => self::hash($token),
            'tokenHint' => substr($token, 0, strlen(self::PREFIXES[$kind]) + 4),
            'scopes' => Scopes::toString($scopes),
            'clientID' => $clientId,
            'grantID' => $grantId,
            'createdAt' => $this->now(),
            'expiresAt' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
        ]);

        return ['token' => $token, 'id' => (int) $this->db->lastInsertId()];
    }

    private function findByToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE tokenHash = ?', [self::hash($token)]);

        return $row ?: null;
    }

    private function isExpired(array $row): bool
    {
        return $row['expiresAt'] !== null && strtotime($row['expiresAt']) <= time();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
