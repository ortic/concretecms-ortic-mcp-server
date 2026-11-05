"""Usage: python3 test_dev.py TOKEN_FILE (see tests/smoke/README.md)."""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from mcp_client import Client  # noqa: E402

c = Client(open(sys.argv[1]).read().strip())
failures = []


def check(label, is_error, payload, expect_error=False):
    ok = is_error == expect_error
    summary = json.dumps(payload)[:300] if not isinstance(payload, str) else payload[:300]
    print(("PASS " if ok else "FAIL ") + label + " -> " + summary)
    if not ok:
        failures.append(label)
    return payload


tools = [t["name"] for t in c.rpc("tools/list")["tools"]]
print("dev tools:", [t for t in tools if t.startswith("dev_")])
init = c.rpc("initialize", {"protocolVersion": "2025-06-18"})
print("negotiated:", init["protocolVersion"], "| dev instructions:", "Developer tools are enabled" in init["instructions"])

check("dev_list_files root", *c.call("dev_list_files"))
check("dev_list_files application", *c.call("dev_list_files", path="application"))
check("dev_list_files packages recursive", *c.call("dev_list_files", path="packages", recursive=True))
check("dev_read core file", *c.call("dev_read_file", path="concrete/src/Page/Page.php", start_line=1, end_line=5))
check("dev_read database.php blocked", *c.call("dev_read_file", path="application/config/database.php"), expect_error=True)
check("dev_read generated_overrides blocked", *c.call("dev_read_file", path="application/config/generated_overrides/concrete.php"), expect_error=True)
check("dev_read traversal blocked", *c.call("dev_read_file", path="application/../concrete/config/app.php"), expect_error=True)
check("dev_read absolute blocked", *c.call("dev_read_file", path="/etc/passwd"), expect_error=True)
check("dev_read outside roots", *c.call("dev_read_file", path="index.php"), expect_error=True)
check("dev_write core blocked", *c.call("dev_write_file", path="concrete/test.php", content="<?php"), expect_error=True)
check("dev_write own package blocked", *c.call("dev_write_file", path="packages/ortic_mcp_server/x.php", content="<?php"), expect_error=True)
check("dev_write bad extension", *c.call("dev_write_file", path="application/x.phar", content="x"), expect_error=True)
check("dev_write .env blocked", *c.call("dev_write_file", path="application/.env.php", content="x"), expect_error=True)
check("dev_write syntax error", *c.call("dev_write_file", path="application/elements/mcp_test.php", content="<?php echo 'x'"), expect_error=True)

w = check("dev_write create", *c.call("dev_write_file", path="application/elements/mcp_test.php", content="<?php\ndefined('C5_EXECUTE') or die('Access Denied.');\necho 'Hello from MCP';\n"))
check("dev_write unchanged", *c.call("dev_write_file", path="application/elements/mcp_test.php", content="<?php\ndefined('C5_EXECUTE') or die('Access Denied.');\necho 'Hello from MCP';\n"))
e = check("dev_edit_file", *c.call("dev_edit_file", path="application/elements/mcp_test.php", old_text="Hello from MCP", new_text="Hello again"))
check("dev_edit not found", *c.call("dev_edit_file", path="application/elements/mcp_test.php", old_text="nope", new_text="x"), expect_error=True)
check("dev_edit introduces syntax error", *c.call("dev_edit_file", path="application/elements/mcp_test.php", old_text="echo 'Hello again';", new_text="echo 'broken"), expect_error=True)
r = check("dev_read after edit", *c.call("dev_read_file", path="application/elements/mcp_test.php"))
assert "Hello again" in r["content"]
check("dev_search_files", *c.call("dev_search_files", pattern="Hello again", file_pattern="*.php"))
check("dev_search_files regex", *c.call("dev_search_files", pattern="Hello\\s+again", regex=True, path="application"))
css = check("dev_write css in new dir", *c.call("dev_write_file", path="application/themes/mcp_test/css/main.css", content="body { color: red; }"))
h = check("dev_list_file_history", *c.call("dev_list_file_history", path="application/elements/mcp_test.php"))
check("dev_restore_file (undo edit)", *c.call("dev_restore_file", history_id=e["history_id"]))
r = check("dev_read after restore", *c.call("dev_read_file", path="application/elements/mcp_test.php"))
assert "Hello from MCP" in r["content"], r
d = check("dev_delete_file", *c.call("dev_delete_file", path="application/elements/mcp_test.php"))
check("dev_restore delete", *c.call("dev_restore_file", history_id=d["history_id"]))
check("dev_restore create (removes file)", *c.call("dev_restore_file", history_id=w["history_id"]))
check("dev_delete css", *c.call("dev_delete_file", path="application/themes/mcp_test/css/main.css"))

check("dev_clear_cache", *c.call("dev_clear_cache"))
check("dev_get_logs", *c.call("dev_get_logs", min_level="debug", limit=3))
check("dev_list_packages", *c.call("dev_list_packages"))
check("dev_upgrade self blocked", *c.call("dev_upgrade_package", handle="ortic_mcp_server"), expect_error=True)
check("dev_install missing package", *c.call("dev_install_package", handle="nope"), expect_error=True)
check("dev_install_block_type refresh content", *c.call("dev_install_block_type", handle="content"))
check("dev_refresh_entities", *c.call("dev_refresh_entities"))

print("\nFAILURES:", failures if failures else "none")
