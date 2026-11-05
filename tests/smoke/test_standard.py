"""Usage: python3 test_standard.py TOKEN_FILE (see tests/smoke/README.md)."""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from mcp_client import Client  # noqa: E402

token = open(sys.argv[1]).read().strip()
c = Client(token)
failures = []


def check(label, is_error, payload, expect_error=False):
    ok = is_error == expect_error
    summary = json.dumps(payload)[:300] if not isinstance(payload, str) else payload[:300]
    print(("PASS " if ok else "FAIL ") + label + " -> " + summary)
    if not ok:
        failures.append(label)
    return payload


tools = c.rpc("tools/list")["tools"]
print("tools:", len(tools), [t["name"] for t in tools])

check("get_site_info", *c.call("get_site_info"))
check("list_attribute_keys page", *c.call("list_attribute_keys", category="page"))
check("list_attribute_keys file", *c.call("list_attribute_keys", category="file"))
r = check("search_pages", *c.call("search_pages", limit=5))
check("search_pages keywords", *c.call("search_pages", keywords="blog"))
check("search_pages parent /", *c.call("search_pages", parent_path="/", direct_children_only=True))
check("search_pages descendants", *c.call("search_pages", parent_path="/", direct_children_only=False, limit=3, sort="name"))
check("list_page_types", *c.call("list_page_types"))
home = check("get_page home", *c.call("get_page", path="/"))
check("get_page_content home", *c.call("get_page_content", page_id=1, max_length=1500))
check("get_page missing", *c.call("get_page", page_id=999999), expect_error=True)
check("list_block_types", *c.call("list_block_types"))
check("get_block_type content", *c.call("get_block_type", handle="content"))
check("search_files", *c.call("search_files", limit=3))
check("search_files images", *c.call("search_files", type="image", limit=2))
check("browse_file_folder", *c.call("browse_file_folder"))
check("search_users", *c.call("search_users"))
check("get_user admin", *c.call("get_user", user_id=1))
check("list_groups", *c.call("list_groups"))

# --- writes on a new test page ---
created = check("create_page", *c.call("create_page", parent_path="/", name="MCP Test Page", description="created by smoke test", attributes={"meta_title": "MCP meta"}))
pid = created["id"]
check("get_page active unapproved -> error", *c.call("get_page", page_id=pid), expect_error=True)
recent = check("get_page recent", *c.call("get_page", page_id=pid, version="recent"))
check("approve new page", *c.call("approve_page_version", page_id=pid))
added = check("add_block content", *c.call("add_block", page_id=pid, area="Main", block_type="content", data={"content": "<p>Hello from <a href=\"/about\">MCP</a></p>"}))
bid = added["block"]["id"]
added2 = check("add_block first", *c.call("add_block", page_id=pid, area="Main", block_type="content", data={"content": "<h2>Heading</h2>"}, after_block_id=0, approve=True))
page = check("get_page recent after add", *c.call("get_page", page_id=pid, version="recent"))
print("   areas:", json.dumps(page.get("areas"))[:600])
upd = check("update_block", *c.call("update_block", page_id=pid, area="Main", block_id=bid, data={"content": "<p>Updated text</p>"}))
new_bid = upd["block"]["id"]
content = check("get_page_content after approve", *c.call("get_page_content", page_id=pid))
check("update_page", *c.call("update_page", page_id=pid, name="MCP Test Page Renamed", attributes={"meta_description": "desc"}, version_comment="smoke", approve=True))
check("update_page bad attr", *c.call("update_page", page_id=pid, attributes={"does_not_exist": "x"}), expect_error=True)
check("list_page_versions", *c.call("list_page_versions", page_id=pid))
check("delete_block", *c.call("delete_block", page_id=pid, area="Main", block_id=new_bid, approve=True))
final = check("get_page_content final", *c.call("get_page_content", page_id=pid))
child = check("create child page", *c.call("create_page", parent_id=pid, name="Child", publish=True))
check("move_page child to home", *c.call("move_page", page_id=child["id"], new_parent_id=1))
check("move_page into itself", *c.call("move_page", page_id=pid, new_parent_id=pid), expect_error=True)
check("delete_page child", *c.call("delete_page", page_id=child["id"]))
check("delete_page", *c.call("delete_page", page_id=pid))
check("delete home", *c.call("delete_page", page_id=1), expect_error=True)

# files
up = check("upload_file base64", *c.call("upload_file", filename="mcp-test.txt", content_base64="SGVsbG8gTUNQ", title="MCP test file"))
fid = up["id"]
check("get_file", *c.call("get_file", file_id=fid))
check("update_file", *c.call("update_file", file_id=fid, description="desc", tags="mcp\ntest"))
folder = check("create_file_folder", *c.call("create_file_folder", name="MCP Folder"))
check("browse new folder", *c.call("browse_file_folder", folder_id=folder["id"]))
check("upload into folder", *c.call("upload_file", filename="in-folder.txt", content_base64="eA==", folder_id=folder["id"]))
check("upload bad ext", *c.call("upload_file", filename="evil.php", content_base64="eA=="), expect_error=True)
check("upload private url", *c.call("upload_file", filename="x.txt", url="http://127.0.0.1/"), expect_error=True)
check("delete_file", *c.call("delete_file", file_id=fid))

# users & groups
u = check("create_user", *c.call("create_user", username="mcp_tester", email="mcp_tester@example.com"))
uid = u["id"]
g = check("create_group", *c.call("create_group", name="MCP Testers", description="test"))
check("add_user_to_group", *c.call("add_user_to_group", user_id=uid, group_id=g["id"]))
check("search_users by group", *c.call("search_users", group_id=g["id"]))
check("update_user", *c.call("update_user", user_id=uid, new_email="mcp2@example.com", is_active=False))
check("remove_user_from_group", *c.call("remove_user_from_group", user_id=uid, group_id=g["id"]))
check("delete self", *c.call("delete_user", user_id=1), expect_error=True)
check("delete_user", *c.call("delete_user", user_id=uid))

# protocol
check("unknown args type", *c.call("search_pages", limit="abc"), expect_error=True)
try:
    c.call("dev_read_file", path="application/bootstrap/app.php")
    print("FAIL dev tool hidden for standard token")
    failures.append("dev hidden")
except RuntimeError as e:
    print("PASS dev tool hidden for standard token ->", str(e)[:120])

print("\nFAILURES:", failures if failures else "none")
