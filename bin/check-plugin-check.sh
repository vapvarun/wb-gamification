#!/usr/bin/env bash
# bin/check-plugin-check.sh — gate WordPress.org Plugin Check results.
#
# Plugin Check is the official WP.org plugin checker. The plugin must
# ship with zero ERROR-severity findings in shipped code (anything in
# src/, integrations/, wb-gamification.php) — that's the submission bar.
# WARNINGs are tracked against a baseline so the count can't grow.
#
# Why a custom gate when `wp plugin check` exists? The raw command
# returns ALL findings including those from examples/, dist/, build/,
# marketing/, and sdk/ — none of which ship to wp.org. This wrapper:
#   1. Filters to shipped paths only (the files the WP.org reviewer sees).
#   2. Fails on any ERROR-severity finding in shipped code.
#   3. Fails if WARNING count grows over the baseline at
#      audit/plugin-check-warning-baseline.txt.
#
# Refresh baseline after legitimate fixes that retire warnings:
#   bin/check-plugin-check.sh --update-baseline
#
# Exit 0 = green; exit 1 = errors or new warnings detected.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"  # plugin → plugins → wp-content → public
cd "$PLUGIN_DIR"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'

BASELINE_FILE="audit/plugin-check-warning-baseline.txt"
RAW_CSV="/tmp/wbg-plugin-check-$$.csv"
trap "rm -f '$RAW_CSV'" EXIT

PLUGIN_SLUG="$(basename "$PLUGIN_DIR")"

# Locate wp-cli — prefer the binary on PATH; fall back to Local's bundled
# php since this script may run from contexts where PATH is minimal.
if command -v wp > /dev/null 2>&1; then
  WP_CLI="wp"
elif [ -x /Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp ]; then
  WP_CLI="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix/wp"
else
  printf '%s\n' "${YELLOW}!${RESET}  wp-cli not found — Plugin Check gate skipped."
  exit 0
fi

# Run Plugin Check. Note we cd into the WP root so wp-cli finds the
# install — wp-cli doesn't auto-discover from a nested plugin folder.
(cd "$WP_ROOT" && "$WP_CLI" plugin check "$PLUGIN_SLUG" --format=csv 2>&1) > "$RAW_CSV" || true

if [ ! -s "$RAW_CSV" ]; then
  printf '%s\n' "${RED}✗${RESET}  Plugin Check produced no output — wp-cli or Plugin Check plugin may not be available."
  exit 1
fi

# Count findings by severity, scoped to shipped code only.
# `src/`, `integrations/`, and `wb-gamification.php` are the WP.org-shipped paths.
# Everything else (examples/, dist/, build/, marketing/, sdk/, tests/) is
# excluded — those don't ship and would otherwise dominate the output.
errors_shipped() {
  awk '/^FILE:/{f=$2; src=match(f, /^(src\/|integrations\/|wb-gamification\.php)/)}
       src && /,ERROR,/{print f": "$0}' "$RAW_CSV"
}

warnings_shipped() {
  awk '/^FILE:/{f=$2; src=match(f, /^(src\/|integrations\/|wb-gamification\.php)/)}
       src && /,WARNING,/' "$RAW_CSV"
}

ERROR_LINES=$(errors_shipped)
ERROR_COUNT=$(printf '%s\n' "$ERROR_LINES" | grep -c '.' || true)
[ -z "$ERROR_COUNT" ] && ERROR_COUNT=0

WARNING_COUNT=$(warnings_shipped | wc -l | tr -d ' ')

# Update-baseline path.
if [ "${1:-}" = "--update-baseline" ]; then
  mkdir -p "$(dirname "$BASELINE_FILE")"
  {
    echo "# bin/check-plugin-check.sh — WARNING baseline in shipped code."
    echo "# Each line is '<code>: <count>'. Plugin Check WARNINGs that the team"
    echo "# accepts as living debt (false positives, convention-driven globals"
    echo "# in block templates, custom-table direct queries). Gate fails if the"
    echo "# TOTAL warning count grows above this baseline OR if a NEW code"
    echo "# appears that wasn't in the baseline."
    echo "# Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo ""
    warnings_shipped | awk -F, '{print $4}' | sort | uniq -c | awk '{printf "%s: %d\n", $2, $1}' | sort
    echo ""
    echo "# total: $WARNING_COUNT"
  } > "$BASELINE_FILE"
  printf '%s\n' "${GREEN}✓${RESET}  Baseline updated — $WARNING_COUNT warnings recorded."
  exit 0
fi

# Hard fail on any shipped-code ERROR.
if [ "$ERROR_COUNT" -gt 0 ]; then
  printf '%s\n' "${RED}✗${RESET}  Plugin Check failed — $ERROR_COUNT ERROR(s) in shipped code:"
  printf '%s\n' "$ERROR_LINES" | head -10 | sed 's/^/    /'
  if [ "$ERROR_COUNT" -gt 10 ]; then
    printf '    %s\n' "... ($((ERROR_COUNT - 10)) more — see full output via 'wp plugin check $PLUGIN_SLUG')"
  fi
  echo ""
  echo "    ERRORs in shipped code block wp.org submission."
  echo "    Fix every entry above before pushing."
  exit 1
fi

# Compare WARNING count to baseline.
if [ ! -f "$BASELINE_FILE" ]; then
  printf '%s\n' "${YELLOW}!${RESET}  No baseline at $BASELINE_FILE"
  printf '    %s\n' "Seed: bin/check-plugin-check.sh --update-baseline"
  printf '    %s\n' "    Current shipped-code warnings: $WARNING_COUNT"
  exit 1
fi

BASELINE_TOTAL=$(grep -E '^# total:' "$BASELINE_FILE" | awk '{print $3}')
[ -z "$BASELINE_TOTAL" ] && BASELINE_TOTAL=0

if [ "$WARNING_COUNT" -gt "$BASELINE_TOTAL" ]; then
  printf '%s\n' "${RED}✗${RESET}  Plugin Check WARNING count grew: $BASELINE_TOTAL → $WARNING_COUNT (+$((WARNING_COUNT - BASELINE_TOTAL)))"
  echo ""
  echo "    Either fix the new warnings or refresh the baseline if it represents"
  echo "    legitimate convention-driven debt:"
  echo "      bin/check-plugin-check.sh --update-baseline"
  exit 1
fi

if [ "$WARNING_COUNT" -lt "$BASELINE_TOTAL" ]; then
  printf '%s\n' "${GREEN}✓${RESET}  Plugin Check green — 0 ERRORs; $WARNING_COUNT WARNINGs (down from $BASELINE_TOTAL baseline)."
  printf '    %s\n' "${DIM}Refresh baseline to lock the gain: bin/check-plugin-check.sh --update-baseline${RESET}"
else
  printf '%s\n' "${GREEN}✓${RESET}  Plugin Check green — 0 ERRORs; $WARNING_COUNT WARNINGs (= baseline)."
fi
exit 0
