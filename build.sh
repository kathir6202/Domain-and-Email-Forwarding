#!/bin/sh
# Builds dist/seekahost_forwarding.zip (extract into the WHMCS root).
# Only modules/addons/seekahost_forwarding/ is added; the README goes inside it.
set -e
cd "$(dirname "$0")"
ROOT="$(pwd)"
STAGE="$(mktemp -d)"
mkdir -p "$STAGE/modules/addons" dist
cp -R modules/addons/seekahost_forwarding "$STAGE/modules/addons/"
cp README.md "$STAGE/modules/addons/seekahost_forwarding/README.md"
rm -f dist/seekahost_forwarding.zip
(cd "$STAGE" && zip -rq -X "$ROOT/dist/seekahost_forwarding.zip" modules)
rm -rf "$STAGE"
echo "Built dist/seekahost_forwarding.zip"
