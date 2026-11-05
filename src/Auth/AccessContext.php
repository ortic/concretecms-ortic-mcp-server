<?php

namespace Ortic\McpServer\Auth;

/**
 * The validated bearer token of the current MCP request.
 */
final class AccessContext
{
    /**
     * @param string[] $scopes
     */
    public function __construct(
        public readonly int $tokenId,
        public readonly int $userId,
        public readonly string $kind,
        public readonly array $scopes,
        public readonly ?string $clientId
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
