#!/usr/bin/env bash
# Prints the package version declared in controller.php ($pkgVersion).
set -euo pipefail

cd "$(dirname "$0")/../.."

version="$(sed -nE "s/^[[:space:]]*protected[[:space:]]+\\\$pkgVersion[[:space:]]*=[[:space:]]*'([^']+)';.*/\1/p" controller.php)"
if [ -z "$version" ]; then
    echo "error: could not read \$pkgVersion from controller.php" >&2
    exit 1
fi
echo "$version"
