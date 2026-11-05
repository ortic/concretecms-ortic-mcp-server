<?php

namespace Ortic\McpServer\Http;

use Concrete\Core\Support\Facade\Url;

/**
 * Absolute URLs of the MCP and OAuth endpoints.
 */
class Urls
{
    public const ENDPOINT_PATH = '/ccm/mcp';

    public function to(string $path): string
    {
        return (string) Url::to($path);
    }

    public function endpoint(): string
    {
        return $this->to(self::ENDPOINT_PATH);
    }

    /** scheme://host[:port] of the site. */
    public function origin(): string
    {
        $parts = parse_url($this->issuer());
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /** The OAuth issuer: the canonical site URL without trailing slash. */
    public function issuer(): string
    {
        return rtrim((string) app('url/canonical'), '/');
    }

    /** RFC 9728 metadata URL for the MCP endpoint (well-known suffix inserted after the host). */
    public function protectedResourceMetadata(): string
    {
        // Built from the route path, not the generated URL, which may contain /index.php.
        $basePath = rtrim((string) parse_url($this->issuer(), PHP_URL_PATH), '/');

        return $this->origin() . '/.well-known/oauth-protected-resource' . $basePath . self::ENDPOINT_PATH;
    }

    public function authorizationServerMetadata(): string
    {
        $path = rtrim((string) parse_url($this->issuer(), PHP_URL_PATH), '/');

        return $this->origin() . '/.well-known/oauth-authorization-server' . $path;
    }
}
