#!/usr/bin/env bash
# Smoke-tests the MCP endpoint of a Concrete CMS site where the package is installed.
# Passing proves that the package's routes are registered, so its classes autoload.
#
# Usage: check-endpoint.sh <base-url>    (for example http://127.0.0.1:8080/index.php)
set -euo pipefail

base="${1:?usage: check-endpoint.sh <base-url>}"
base="${base%/}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

fail() {
    echo "::error::$1"
    exit 1
}

# Without a token the endpoint must answer 401 with an OAuth challenge.
status="$(curl -s -o "$tmp/body" -D "$tmp/headers" -w '%{http_code}' -X POST "$base/ccm/mcp" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')" || fail "Could not reach $base/ccm/mcp"
cat "$tmp/headers" "$tmp/body"
echo
[ "$status" = 401 ] || fail "Expected 401 from /ccm/mcp, got $status"
grep -qi '^WWW-Authenticate:' "$tmp/headers" || fail "Missing WWW-Authenticate header"

# The OAuth discovery documents must be valid JSON.
for path in /.well-known/oauth-protected-resource/ccm/mcp /.well-known/oauth-authorization-server; do
    curl -sf "$base$path" -o "$tmp/metadata.json" || fail "$path did not return 200"
    php -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$tmp/metadata.json" \
        || fail "$path is not JSON"
done

echo "MCP endpoint OK at $base"
