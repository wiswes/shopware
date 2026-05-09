#!/usr/bin/env bash
#
# Build a Shopware Marketplace-ready ZIP of the WiswesWidget plugin.
#
# Usage:
#   ./bin/build-marketplace-zip.sh [output-dir]
#
# What it does:
#   1. Reads the version from composer.json.
#   2. Copies the plugin source into a clean staging dir, *excluding*
#      .git, dotfiles, dev-only manifests, and any pre-existing builds.
#   3. (Optional) Runs `bin/console plugin:zip-import` validation
#      against the staged copy if a Shopware project is detected at
#      $SHOPWARE_ROOT — otherwise just zips it.
#   4. Produces WiswesWidget-<version>.zip in the output dir
#      (default: ./build/).
#
# Marketplace expects the top-level entry inside the ZIP to be the
# plugin folder named exactly after the plugin technical name —
# `WiswesWidget/`. The script preserves that convention.
#
# Run from the plugin root.

set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_NAME="WiswesWidget"
OUT_DIR="${1:-${PLUGIN_ROOT}/build}"
SHOPWARE_ROOT="${SHOPWARE_ROOT:-${PLUGIN_ROOT%/custom/plugins/WiswesWidget}}"

# ---- helpers ----
green()  { printf "\033[0;32m==>\033[0m %s\n" "$*"; }
yellow() { printf "\033[1;33m!!\033[0m %s\n" "$*" >&2; }
fail()   { printf "\033[0;31mxx\033[0m %s\n" "$*" >&2; exit 1; }

cd "$PLUGIN_ROOT"

VERSION=$(php -r '$j=json_decode(file_get_contents("composer.json"),true); echo $j["version"];')
[ -n "$VERSION" ] || fail "composer.json is missing a version field."
green "Building ${PLUGIN_NAME} v${VERSION}"

mkdir -p "$OUT_DIR"
STAGE="$(mktemp -d)/${PLUGIN_NAME}"
trap 'rm -rf "$(dirname "$STAGE")"' EXIT

# ---- stage source ----
green "Staging source → $STAGE"
mkdir -p "$STAGE"
# rsync mirrors only the files we ship: src/, info/, top-level
# README/LICENSE/CHANGELOG_*, composer.json. Everything else is dev
# detritus that bloats the ZIP and earns red flags in code review.
rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.github' \
    --exclude='.idea' \
    --exclude='.vscode' \
    --exclude='.DS_Store' \
    --exclude='build' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='*.zip' \
    --exclude='bin/build-marketplace-zip.sh' \
    --exclude='tests' \
    --exclude='phpunit.xml*' \
    --exclude='phpstan.neon*' \
    --exclude='.phpcs.xml*' \
    --include='src/***' \
    --include='info/***' \
    --include='composer.json' \
    --include='LICENSE' \
    --include='README.md' \
    --include='CHANGELOG_en-GB.md' \
    --include='CHANGELOG_de-DE.md' \
    --exclude='*' \
    "$PLUGIN_ROOT"/ "$STAGE"/

# ---- sanity-check required files ----
green "Verifying required files"
for f in composer.json LICENSE CHANGELOG_en-GB.md CHANGELOG_de-DE.md \
         info/manual.html src/WiswesWidget.php; do
    [ -e "$STAGE/$f" ] || fail "missing required file: $f"
done

# ---- optional Shopware-side validation ----
if [ -x "$SHOPWARE_ROOT/bin/console" ]; then
    green "Running Shopware plugin validator (requires plugin to be installed)"
    "$SHOPWARE_ROOT"/bin/console store:plugin-prepare-zip "$STAGE" 2>&1 || \
        yellow "store:plugin-prepare-zip not available — skipping validator"
else
    yellow "No Shopware project at $SHOPWARE_ROOT — skipping validator. Set SHOPWARE_ROOT to enable."
fi

# ---- zip ----
ZIP_PATH="$OUT_DIR/${PLUGIN_NAME}-${VERSION}.zip"
rm -f "$ZIP_PATH"
green "Writing $ZIP_PATH"
( cd "$(dirname "$STAGE")" && zip -qr "$ZIP_PATH" "$PLUGIN_NAME" )

green "Done."
ls -lh "$ZIP_PATH"
echo ""
echo "Next: upload $ZIP_PATH at https://account.shopware.com → Plugins → WisWes Chat Widget → New release."
