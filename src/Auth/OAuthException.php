<?php

namespace Ortic\McpServer\Auth;

/**
 * An OAuth error with an RFC 6749 error code.
 */
class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400
    ) {
        parent::__construct($description);
    }

    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
