#!/usr/bin/env bash
# check-i18n -- the plugin ships fully translated, and every translation file is in step.
#
# THE RULE
#
#   Every msgid in the code is in the .pot, has a real translation in every shipped locale, and the
#   .mo, .json and .l10n.php files WordPress actually loads are the ones built from those .po files.
#   A string that is extracted but empty, fuzzy, or only present in the .po falls back to English on
#   a translated site, and nothing else in the build notices.
#
# WHAT IT CHECKS (each one fails the build)
#
#   1. POT drift     a string in the code that is missing from the committed .pot, or the reverse.
#   2. PO drift      a string in the .pot that is missing from a shipped .po.
#   3. Untranslated  an empty msgstr (any plural form) in a shipped .po.
#   4. Fuzzy         a fuzzy entry: msgfmt drops it, so it ships as English.
#   5. Placeholders  msgfmt -c: a translation whose %s / %d / %1$s do not match the msgid.
#   6. Artifacts     the shipped .mo / .json / .l10n.php differ from what the .po builds today.
#
# Shipped locales are the .po files in the languages dir. Nothing is skipped: a missing tool is a
# failure, because a gate that quietly checks less is the bug this exists to stop.
#
# Usage:
#   bash bin/check-i18n.sh
#   I18N_LANG_DIR=/tmp/copy bash bin/check-i18n.sh     # check another languages dir (used by the self-test)
#
# Proof it can fail: bash bin/test-check-i18n.sh

set -uo pipefail

cd "$(dirname "$0")/.."

LANG_DIR="${I18N_LANG_DIR:-languages}"
DOMAIN="wb-gamification"
POT="${LANG_DIR}/${DOMAIN}.pot"
EXCLUDE="node_modules,vendor,tests,plan,audit,docs,examples,marketing,dist,build,.git,.claude,.superpowers"

fail_count=0
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; shift; for l in "$@"; do printf '       %s\n' "$l"; done; fail_count=$((fail_count + 1)); }
pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }

# ─── tools: all required, none optional ──────────────────────────────────────

WP_BIN="${WP_BIN:-}"
if [ -z "$WP_BIN" ]; then
  if command -v wp >/dev/null 2>&1; then
    WP_BIN="$(command -v wp)"
  elif [ -x "/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp" ]; then
    WP_BIN="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
  fi
fi

missing=()
for t in msgfmt msgattrib msgcmp msgunfmt; do command -v "$t" >/dev/null 2>&1 || missing+=("$t (gettext)"); done
[ -n "$WP_BIN" ] || missing+=("wp-cli (set WP_BIN)")
if [ ${#missing[@]} -gt 0 ]; then
  fail "Tools missing, so nothing was checked" "${missing[@]}"
  exit 1
fi

[ -f "$POT" ] || { fail "No .pot at ${POT}"; exit 1; }
shopt -s nullglob
pos=( "${LANG_DIR}"/${DOMAIN}-*.po )
if [ ${#pos[@]} -eq 0 ]; then
  fail "No shipped .po files in ${LANG_DIR}"
  exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

echo "i18n gate: ${#pos[@]} locales in ${LANG_DIR}"

# ─── 1. POT drift ────────────────────────────────────────────────────────────

if ! "$WP_BIN" i18n make-pot . "$work/fresh.pot" --slug="$DOMAIN" --domain="$DOMAIN" --exclude="$EXCLUDE" --skip-audit >"$work/makepot.log" 2>&1; then
  fail "make-pot failed" "$(tail -3 "$work/makepot.log")"
else
  # msgcmp prints "this message is used but not defined" for every msgid the second file lacks.
  msgcmp --use-untranslated "$POT" "$work/fresh.pot" >"$work/pot-missing.log" 2>&1
  missing_from_pot="$(grep -c 'used but not defined' "$work/pot-missing.log" || true)"
  msgcmp --use-untranslated "$work/fresh.pot" "$POT" >"$work/pot-stale.log" 2>&1
  stale_in_pot="$(grep -c 'used but not defined' "$work/pot-stale.log" || true)"
  if [ "${missing_from_pot:-0}" -gt 0 ] || [ "${stale_in_pot:-0}" -gt 0 ]; then
    fail "POT drift: ${missing_from_pot} string(s) in the code are not in the .pot, ${stale_in_pot} in the .pot are no longer in the code" \
      "Run: npm run build:pot, then translate the new strings"
    grep -B1 'used but not defined' "$work/pot-missing.log" | grep -E 'msgid|^[^ ]+:[0-9]+' | head -6
  else
    pass "POT matches the code"
  fi
fi

# ─── 2-5. Per locale ─────────────────────────────────────────────────────────

for po in "${pos[@]}"; do
  loc="$(basename "$po" .po)"; loc="${loc#${DOMAIN}-}"

  # 2. PO drift: msgcmp po pot lists every pot msgid the po lacks.
  drift="$(msgcmp --use-untranslated "$po" "$POT" 2>&1 | grep -c 'used but not defined' || true)"
  if [ "${drift:-0}" -gt 0 ]; then
    fail "${loc}: ${drift} string(s) in the .pot are missing from the .po"
    continue
  fi

  # 3. Untranslated: msgattrib --untranslated also lists the header entry (empty msgid), so take 1 off.
  untr="$(msgattrib --untranslated --no-obsolete "$po" 2>/dev/null | grep -c '^msgid ' || true)"
  untr=$(( untr > 0 ? untr - 1 : 0 ))
  # 4. Fuzzy.
  fuzzy="$(msgattrib --only-fuzzy --no-obsolete "$po" 2>/dev/null | grep -c '^msgid ' || true)"
  # 5. Placeholders / format strings.
  if ! msgfmt -c -o /dev/null "$po" >"$work/fmt-${loc}.log" 2>&1; then
    fmt_bad="$(grep -c 'error\|warning' "$work/fmt-${loc}.log" || true)"
  else
    fmt_bad=0
  fi

  if [ "${untr:-0}" -gt 0 ] || [ "${fuzzy:-0}" -gt 0 ] || [ "${fmt_bad:-0}" -gt 0 ]; then
    fail "${loc}: ${untr} untranslated, ${fuzzy} fuzzy, ${fmt_bad} placeholder/format problem(s)"
    if [ "${untr:-0}" -gt 0 ]; then msgattrib --untranslated --no-obsolete "$po" 2>/dev/null | grep '^msgid ' | sed -n '2,6p' | sed 's/^/       untranslated: /'; fi
    if [ "${fuzzy:-0}" -gt 0 ]; then msgattrib --only-fuzzy --no-obsolete "$po" 2>/dev/null | grep '^msgid ' | sed -n '1,4p' | sed 's/^/       fuzzy: /'; fi
    if [ "${fmt_bad:-0}" -gt 0 ]; then head -4 "$work/fmt-${loc}.log" | sed 's/^/       /'; fi
  else
    pass "${loc}: fully translated, placeholders match"
  fi

  # 6. Artifacts: rebuild from the .po and compare with what ships.
  mo="${LANG_DIR}/${DOMAIN}-${loc}.mo"
  if [ ! -f "$mo" ]; then
    fail "${loc}: ${mo} is missing"
  else
    msgfmt -o "$work/${loc}.mo" "$po" 2>/dev/null
    if ! diff -q <(msgunfmt "$mo" 2>/dev/null | grep -v '^"POT-Creation-Date\|^"PO-Revision-Date' | sort) \
                 <(msgunfmt "$work/${loc}.mo" 2>/dev/null | grep -v '^"POT-Creation-Date\|^"PO-Revision-Date' | sort) >/dev/null; then
      fail "${loc}: the shipped .mo is not built from the current .po" "Run: wp i18n make-mo ${LANG_DIR}"
    fi
  fi
done

# .json (one per script) and .l10n.php, rebuilt from copies of the .po files.
mkdir -p "$work/build"
cp "${LANG_DIR}"/${DOMAIN}-*.po "$work/build/"
"$WP_BIN" i18n make-json "$work/build" --no-purge >"$work/makejson.log" 2>&1 || fail "make-json failed" "$(tail -3 "$work/makejson.log")"
"$WP_BIN" i18n make-php "$work/build" >"$work/makephp.log" 2>&1 || fail "make-php failed" "$(tail -3 "$work/makephp.log")"

missing_files=(); stale_files=(); orphan_files=()
for f in "$work"/build/*.json "$work"/build/*.l10n.php; do
  [ -e "$f" ] || continue
  name="$(basename "$f")"
  shipped="${LANG_DIR}/${name}"
  if [ ! -f "$shipped" ]; then
    missing_files+=("$name")
  elif ! diff -q <(grep -v 'PO-Revision-Date\|POT-Creation-Date\|"generator"' "$shipped") <(grep -v 'PO-Revision-Date\|POT-Creation-Date\|"generator"' "$f") >/dev/null; then
    stale_files+=("$name")
  fi
done
for shipped in "${LANG_DIR}"/${DOMAIN}-*.json "${LANG_DIR}"/${DOMAIN}-*.l10n.php; do
  [ -e "$shipped" ] || continue
  [ -f "$work/build/$(basename "$shipped")" ] || orphan_files+=("$(basename "$shipped")")
done
if [ ${#missing_files[@]} -gt 0 ]; then
  fail "${#missing_files[@]} .json/.l10n.php file(s) the .po files build are not shipped (those scripts show English)" "e.g. ${missing_files[0]}" "Run: wp i18n make-json ${LANG_DIR} --no-purge && wp i18n make-php ${LANG_DIR}"
fi
if [ ${#stale_files[@]} -gt 0 ]; then
  fail "${#stale_files[@]} shipped .json/.l10n.php file(s) differ from the .po build" "e.g. ${stale_files[0]}" "Run: wp i18n make-json ${LANG_DIR} --no-purge && wp i18n make-php ${LANG_DIR}"
fi
if [ ${#orphan_files[@]} -gt 0 ]; then
  fail "${#orphan_files[@]} shipped .json file(s) belong to no script in the .po files" "e.g. ${orphan_files[0]}"
fi
if [ ${#missing_files[@]} -eq 0 ] && [ ${#stale_files[@]} -eq 0 ] && [ ${#orphan_files[@]} -eq 0 ]; then
  pass "Shipped .json and .l10n.php match the .po files"
fi

echo
if [ "$fail_count" -gt 0 ]; then
  printf '\033[31mi18n gate FAILED: %d problem(s)\033[0m\n' "$fail_count"
  exit 1
fi
printf '\033[32mi18n gate passed\033[0m\n'
