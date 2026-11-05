"""Usage: MCP_ADMIN_USER=admin MCP_ADMIN_PASSWORD=... python3 test_oauth.py (see tests/smoke/README.md)."""
import base64
import os
import hashlib
import http.cookiejar
import json
import re
import secrets
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("MCP_BASE_URL", "https://ortic-mcp.ddev.site").rstrip("/")
CTX = ssl._create_unverified_context() if os.environ.get("MCP_INSECURE", "1") == "1" else ssl.create_default_context()
failures = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=CTX), urllib.request.HTTPCookieProcessor(jar), NoRedirect)


def req(method, url, data=None, headers=None, json_body=None):
    headers = dict(headers or {})
    body = None
    if json_body is not None:
        body = json.dumps(json_body).encode()
        headers["Content-Type"] = "application/json"
    elif data is not None:
        body = urllib.parse.urlencode(data).encode()
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    r = urllib.request.Request(url, data=body, method=method, headers=headers)
    try:
        resp = opener.open(r)
        return resp.status, resp.headers, resp.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode()


def check(label, cond, detail=""):
    print(("PASS " if cond else "FAIL ") + label + (" -> " + str(detail)[:250] if detail else ""))
    if not cond:
        failures.append(label)


# 1. Discovery, as an MCP client does it
s, h, b = req("POST", BASE + "/ccm/mcp", json_body={"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}})
www = h.get("WWW-Authenticate", "")
check("401 with resource_metadata", s == 401 and "resource_metadata=" in www, www)
prm_url = re.search(r'resource_metadata="([^"]+)"', www).group(1)
s, h, b = req("GET", prm_url)
prm = json.loads(b)
check("protected resource metadata", s == 200 and prm["resource"].endswith("/ccm/mcp"), prm)
issuer = prm["authorization_servers"][0]
parsed = urllib.parse.urlparse(issuer)
as_url = f"{parsed.scheme}://{parsed.netloc}/.well-known/oauth-authorization-server{parsed.path.rstrip('/')}"
s, h, b = req("GET", as_url)
meta = json.loads(b)
check("authorization server metadata", s == 200 and meta["issuer"] == issuer and "S256" in meta["code_challenge_methods_supported"], meta)

# 2. Dynamic client registration
redirect = "https://claude.ai/api/mcp/auth_callback"
s, h, b = req("POST", meta["registration_endpoint"], json_body={"client_name": "Claude", "redirect_uris": [redirect], "grant_types": ["authorization_code", "refresh_token"], "response_types": ["code"], "token_endpoint_auth_method": "none"})
client = json.loads(b)
check("register public client", s == 201 and "client_secret" not in client, client)
s, h, b = req("POST", meta["registration_endpoint"], json_body={"client_name": "Evil", "redirect_uris": ["https://evil.example/cb"]})
check("register disallowed redirect rejected", s == 400 and json.loads(b)["error"] == "invalid_redirect_uri", b)
s, h, b = req("POST", meta["registration_endpoint"], json_body={"client_name": "Conf", "redirect_uris": ["http://localhost:33418/callback"], "token_endpoint_auth_method": "client_secret_post"})
conf = json.loads(b)
check("register confidential loopback client", s == 201 and "client_secret" in conf, conf)

# 3. Authorization request (not logged in -> login)
verifier = secrets.token_urlsafe(48)
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
state = secrets.token_urlsafe(8)
params = {"response_type": "code", "client_id": client["client_id"], "redirect_uri": redirect, "code_challenge": challenge,
          "code_challenge_method": "S256", "state": state, "scope": "mcp", "resource": prm["resource"]}
auth_url = meta["authorization_endpoint"] + "?" + urllib.parse.urlencode(params)
s, h, b = req("GET", auth_url)
check("authorize redirects to login", s in (301, 302) and "/login" in h.get("Location", ""), h.get("Location"))

s, h, b = req("GET", meta["authorization_endpoint"] + "?" + urllib.parse.urlencode({**params, "redirect_uri": "https://claude.ai/other"}))
check("authorize with unregistered redirect shows error page", s == 400 and "does not belong" in b, s)
s, h, b = req("GET", meta["authorization_endpoint"] + "?" + urllib.parse.urlencode({**params, "code_challenge_method": "plain"}))
check("authorize without S256 redirects with error", s == 302 and "error=invalid_request" in h.get("Location", ""), h.get("Location"))

# 4. Log in to Concrete
s, h, b = req("GET", BASE + "/login")
form_token = re.search(r'name="ccm_token" value="([^"]+)"', b).group(1)
action = re.search(r'<form[^>]+action="([^"]*authenticate/concrete[^"]*)"', b).group(1)
s, h, b = req("POST", urllib.parse.urljoin(BASE, action), {"uName": os.environ.get("MCP_ADMIN_USER", "admin"), "uPassword": os.environ["MCP_ADMIN_PASSWORD"], "ccm_token": form_token})
check("login", s in (301, 302), h.get("Location"))
loc = h.get("Location", "")
if "login_complete" in loc:
    s, h, b = req("GET", loc)
    loc = h.get("Location", "")
check("login returns to authorize", "oauth/authorize" in loc, loc)

# 5. Consent page
s, h, b = req("GET", auth_url)
check("consent page", s == 200 and "Allow access" in b and h.get("X-Frame-Options") == "DENY", s)
hidden = dict(re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', b))
hidden = {k: v.replace("&amp;", "&").replace("&quot;", '"') for k, v in hidden.items()}
check("developer checkbox offered (dev mode on, super admin)", 'name="grant_developer"' in b)

s, h, b = req("POST", meta["authorization_endpoint"], {**hidden, "decision": "approve", "ccm_token": "bad"})
check("approve with bad CSRF rejected", s == 400, s)
s, h, b = req("POST", meta["authorization_endpoint"], {**hidden, "decision": "approve"})
loc = h.get("Location", "")
q = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
check("approve redirects with code+state+iss", s == 302 and loc.startswith(redirect) and q.get("state") == [state] and q.get("iss") == [issuer], loc[:200])
code = q["code"][0]

# 6. Token exchange
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client["client_id"], "code_verifier": "wrong" * 10})
check("token with wrong verifier rejected", s == 400 and json.loads(b)["error"] == "invalid_grant", b)
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client["client_id"], "code_verifier": verifier})
check("code is single-use even after failed attempt", s == 400, b)

# new authorization, correct exchange this time
s, h, b = req("GET", auth_url)
hidden = {k: v.replace("&amp;", "&") for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', b)}
s, h, b = req("POST", meta["authorization_endpoint"], {**hidden, "decision": "approve"})
code = urllib.parse.parse_qs(urllib.parse.urlparse(h["Location"]).query)["code"][0]
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client["client_id"], "code_verifier": verifier})
tokens = json.loads(b)
check("token exchange", s == 200 and tokens["token_type"] == "Bearer" and tokens["scope"] == "mcp" and h.get("Cache-Control", "").startswith("no-store"), tokens)
def mcp(token, method="tools/list"):
    return req("POST", BASE + "/ccm/mcp", json_body={"jsonrpc": "2.0", "id": 1, "method": method, "params": {}},
               headers={"Authorization": "Bearer " + token, "Accept": "application/json, text/event-stream"})


s, h, b = mcp(tokens["access_token"])
names = [t["name"] for t in json.loads(b)["result"]["tools"]] if s == 200 else []
check("MCP with OAuth access token", s == 200 and "get_page" in names and not any(n.startswith("dev_") for n in names), len(names))
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client["client_id"], "code_verifier": verifier})
check("code replay rejected", s == 400, b)
s, h, b = mcp(tokens["access_token"])
check("code replay revoked the tokens issued with it", s == 401, s)

# fresh grant for refresh tests
s, h, b = req("GET", auth_url)
hidden = {k: v.replace("&amp;", "&") for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', b)}
s, h, b = req("POST", meta["authorization_endpoint"], {**hidden, "decision": "approve", "grant_developer": "1"})
code = urllib.parse.parse_qs(urllib.parse.urlparse(h["Location"]).query)["code"][0]
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client["client_id"], "code_verifier": verifier})
t1 = json.loads(b)
check("developer grant", t1.get("scope") == "mcp mcp:developer", t1.get("scope"))
s, h, b = mcp(t1["access_token"])
check("developer tools visible with developer grant", s == 200 and any(t["name"].startswith("dev_") for t in json.loads(b)["result"]["tools"]))
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "refresh_token", "refresh_token": t1["refresh_token"], "client_id": client["client_id"], "scope": "mcp"})
t2 = json.loads(b)
check("refresh (narrowed scope)", s == 200 and t2["scope"] == "mcp" and t2["refresh_token"] != t1["refresh_token"], t2.get("scope"))
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "refresh_token", "refresh_token": t1["refresh_token"], "client_id": client["client_id"]})
check("refresh token reuse rejected", s == 400, b)
s, h, b = mcp(t2["access_token"])
check("reuse revoked the whole grant", s == 401, s)

# Revocation endpoint
s, h, b = req("GET", auth_url)
hidden = {k: v.replace("&amp;", "&") for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', b)}
s, h, b = req("POST", meta["authorization_endpoint"], {**hidden, "decision": "approve"})
code = urllib.parse.parse_qs(urllib.parse.urlparse(h["Location"]).query)["code"][0]
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client["client_id"], "code_verifier": verifier})
t3 = json.loads(b)
s, h, b = req("POST", meta["revocation_endpoint"], {"token": t3["refresh_token"], "client_id": client["client_id"]})
check("revoke", s == 200)
s, h, b = mcp(t3["access_token"])
check("revoked grant cannot call MCP", s == 401 and "invalid_token" in h.get("WWW-Authenticate", ""), h.get("WWW-Authenticate"))

# Deny
s, h, b = req("GET", auth_url)
hidden = {k: v.replace("&amp;", "&") for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', b)}
s, h, b = req("POST", meta["authorization_endpoint"], {**hidden, "decision": "deny"})
check("deny redirects with access_denied", "error=access_denied" in h.get("Location", ""), h.get("Location"))

# Confidential client must authenticate
s, h, b = req("POST", meta["token_endpoint"], {"grant_type": "refresh_token", "refresh_token": "x", "client_id": conf["client_id"]})
check("confidential client without secret rejected", s == 401, b)

# Origin check
s, h, b = req("POST", BASE + "/ccm/mcp", json_body={"jsonrpc": "2.0", "id": 1, "method": "ping"}, headers={"Origin": "https://evil.example"})
check("foreign Origin rejected with 403", s == 403, s)
s, h, b = req("GET", BASE + "/ccm/mcp")
check("GET returns 405", s == 405, s)

print("\nFAILURES:", failures if failures else "none")
