#!/usr/bin/env bash
# build-i18n-json -- the per-script translation files WordPress loads in the browser.
#
# WHY THIS EXISTS
#
#   WordPress finds a script's translations by md5 of the script's path RELATIVE TO THE PLUGIN, as
#   registered (wp-includes/l10n.php, _load_script_textdomain_from_src: ".min.js" is mapped to ".js").
#   The block editor scripts ship as build/Blocks/<slug>/index.js, bundled from src/Blocks/** and
#   src/shared/**. `wp i18n make-json` keys a file to the path written in the .po reference, and the
#   .po references point at the SOURCE modules, so the files it produced matched no shipped script
#   and the editor UI stayed English. The reference must be the shipped path, so we extract from the
#   shipped scripts themselves (build/ and assets/js, minified call forms like (0,i.__)() included),
#   merge the existing .po translations into that JS-only catalogue, and run make-json on the result.
#
# WHAT IT DOES (offline, idempotent)
#
#   1. Needs build/ (npm run build); builds it when missing.
#   2. Stages every shipped script (build/**/*.js, assets/js/*.js; never *.min.js) under its real path.
#   3. wp i18n make-pot over the stage -> JS-only .pot whose references are the shipped paths.
#   4. For each languages/wb-gamification-<locale>.po: msgmerge (no fuzzy matching) onto that .pot,
#      then wp i18n make-json -> wb-gamification-<locale>-<md5(shipped path)>.json.
#   5. In OUT_DIR, replaces every existing wb-gamification-<locale>-<md5>.json with the new set.
#
# Usage:
#   bash bin/build-i18n-json.sh              # writes into languages/
#   bash bin/build-i18n-json.sh /tmp/out     # writes into another dir (used by bin/check-i18n.sh)
#   I18N_PO_DIR=languages  WP_BIN=/path/to/wp
#
# Run it after every change to a .po file or to any JS source, and commit the result.

set -euo pipefail
cd "$(dirname "$0")/.."

DOMAIN="wb-gamification"
PO_DIR="${I18N_PO_DIR:-languages}"
OUT_DIR="${1:-languages}"

WP_BIN="${WP_BIN:-}"
if [ -z "$WP_BIN" ]; then
  if command -v wp >/dev/null 2>&1; then WP_BIN="$(command -v wp)"
  elif [ -x "/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp" ]; then
    WP_BIN="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
  fi
fi
[ -n "$WP_BIN" ] || { echo "build-i18n-json: wp-cli not found (set WP_BIN)" >&2; exit 1; }
for t in msgmerge tar; do command -v "$t" >/dev/null 2>&1 || { echo "build-i18n-json: $t not found" >&2; exit 1; }; done

if [ ! -d build/Blocks ]; then
  echo "build-i18n-json: build/ is missing, running npm run build" >&2
  npm run build >/dev/null
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/stage" "$work/po"

# Shipped scripts at their real plugin-relative paths.
find build assets/js -name '*.js' ! -name '*.min.js' | LC_ALL=C sort | tar -cf - -T - | tar -xf - -C "$work/stage"

"$WP_BIN" i18n make-pot "$work/stage" "$work/js.pot" --domain="$DOMAIN" --slug="$DOMAIN" --skip-audit >/dev/null

shopt -s nullglob
pos=( "$PO_DIR"/${DOMAIN}-*.po )
[ ${#pos[@]} -gt 0 ] || { echo "build-i18n-json: no ${DOMAIN}-*.po in ${PO_DIR}" >&2; exit 1; }
for po in "${pos[@]}"; do
  msgmerge -q -N "$po" "$work/js.pot" -o "$work/po/$(basename "$po")"
done
"$WP_BIN" i18n make-json "$work/po" --no-purge >/dev/null

mkdir -p "$OUT_DIR"
rm -f "$OUT_DIR"/${DOMAIN}-*-????????????????????????????????.json
cp "$work"/po/${DOMAIN}-*.json "$OUT_DIR"/
echo "build-i18n-json: $(ls "$work"/po/${DOMAIN}-*.json | wc -l | tr -d ' ') file(s) in ${OUT_DIR} for ${#pos[@]} locale(s)"
