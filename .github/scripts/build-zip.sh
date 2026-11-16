#!/usr/bin/env bash
# Builds the ZIP that is attached to GitHub releases: a single "ortic_mcp_server" folder
# that can be unzipped straight into the packages/ directory of a Concrete CMS site.
#
# Usage: build-zip.sh [git-ref] [output.zip]
set -euo pipefail

ref="${1:-HEAD}"
out="${2:-ortic_mcp_server.zip}"
handle="ortic_mcp_server"

cd "$(git rev-parse --show-toplevel)"

# git archive honours the export-ignore rules in .gitattributes.
git archive --format=zip -9 --prefix="$handle/" -o "$out" "$ref"

listing="$(unzip -Z1 "$out")"
for required in controller.php db.xml install.xml src/Mcp/Server.php; do
    if ! grep -qx "$handle/$required" <<<"$listing"; then
        echo "error: $handle/$required is missing from $out" >&2
        exit 1
    fi
done
if grep -qE "^$handle/(tests|\.github|dev|\.ddev)/" <<<"$listing"; then
    echo "error: $out contains development files" >&2
    exit 1
fi

echo "Built $out ($(wc -l <<<"$listing") entries)"
