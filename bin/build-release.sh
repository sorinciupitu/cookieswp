#!/usr/bin/env bash
set -euo pipefail
VERSION="${1:-$(git describe --tags --abbrev=0 2>/dev/null | sed 's/^v//')}"

HEADER_VERSION=$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)/\1/p' consent-suite.php)
CONST_VERSION=$(grep -o "define( 'CCM_VERSION', '[^']*' )" consent-suite.php | sed "s/.*'\([^']*\)'.*/\1/")
[ "$HEADER_VERSION" = "$VERSION" ] || { echo "Header Version ($HEADER_VERSION) != $VERSION" >&2; exit 1; }
[ "$CONST_VERSION" = "$VERSION" ] || { echo "CCM_VERSION ($CONST_VERSION) != $VERSION" >&2; exit 1; }

rm -rf build release
mkdir -p build/consent-suite release
cp -R consent-suite.php includes public uninstall.php build/consent-suite/
find build -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
( cd build && zip -rq ../release/consent-suite.zip consent-suite )
echo "Built release/consent-suite.zip ($VERSION)"
