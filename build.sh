#!/bin/sh
# Builds dist/seekahost_forwarding.zip (extract into the WHMCS root).
set -e
cd "$(dirname "$0")"
rm -f dist/seekahost_forwarding.zip
mkdir -p dist
zip -rq -X dist/seekahost_forwarding.zip modules README.md
echo "Built dist/seekahost_forwarding.zip"
