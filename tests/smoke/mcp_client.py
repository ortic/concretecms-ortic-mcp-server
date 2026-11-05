"""Minimal MCP Streamable HTTP client for smoke-testing the Concrete MCP server."""
import json
import os
import ssl
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("MCP_BASE_URL", "https://ortic-mcp.ddev.site").rstrip("/")
ENDPOINT = BASE + "/ccm/mcp"
# Local DDEV certificates are not trusted by Python.
CTX = ssl._create_unverified_context() if os.environ.get("MCP_INSECURE", "1") == "1" else ssl.create_default_context()


def http(method, url, body=None, headers=None, form=False):
    headers = dict(headers or {})
    data = None
    if body is not None:
        if form:
            data = urllib.parse.urlencode(body).encode()
            headers.setdefault("Content-Type", "application/x-www-form-urlencoded")
        else:
            data = json.dumps(body).encode()
            headers.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, context=CTX) as r:
            return r.status, dict(r.headers), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode()


class Client:
    def __init__(self, token):
        self.token = token
        self.id = 0

    def rpc(self, method, params=None):
        self.id += 1
        status, headers, text = http("POST", ENDPOINT, {"jsonrpc": "2.0", "id": self.id, "method": method, "params": params or {}}, {
            "Authorization": "Bearer " + self.token,
            "Accept": "application/json, text/event-stream",
            "MCP-Protocol-Version": "2025-11-25",
        })
        if status != 200:
            raise RuntimeError(f"HTTP {status}: {text[:500]}")
        data = json.loads(text)
        if "error" in data:
            raise RuntimeError(f"JSON-RPC error: {data['error']}")
        return data["result"]

    def call(self, _tool, **arguments):
        result = self.rpc("tools/call", {"name": _tool, "arguments": arguments})
        text = result["content"][0]["text"]
        try:
            payload = json.loads(text)
        except ValueError:
            payload = text
        return result["isError"], payload

