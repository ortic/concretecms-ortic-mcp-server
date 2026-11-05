# Smoke tests

End-to-end checks against a running site (by default the DDEV site from the main README). They change content: run them on a development site only.

1. Developer mode on, and two personal tokens of the super administrator: one standard, one with developer tools.
2. Run:

```bash
python3 tests/smoke/test_standard.py /path/to/standard-token.txt
python3 tests/smoke/test_dev.py /path/to/developer-token.txt
MCP_ADMIN_USER=admin MCP_ADMIN_PASSWORD=... python3 tests/smoke/test_oauth.py
```

`MCP_BASE_URL` changes the site (default `https://ortic-mcp.ddev.site`); set `MCP_INSECURE=0` to verify TLS certificates.
