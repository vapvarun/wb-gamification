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

# Find a WP install we can actually run against.
#
# THIS GATE REPORTED GREEN FOR MONTHS WHILE PLUGIN CHECK REPORTED 184 ERRORS.
#
# WP_ROOT was computed as PLUGIN_DIR/../../.. — correct when the plugin sits inside
# wp-content/plugins, but this repo lives in ~/dev/repos and is SYMLINKED into the site. So the
# path resolved to a directory that is not a WordPress install at all, `wp plugin check` failed,
# its error text landed in the CSV (non-empty, so the guard below passed), the parser matched
# nothing, and the gate announced "0 ERRORs". A gate that cannot run must SAY SO, not pass.
wp_is_installed() { [ -n "${1:-}" ] && "$WP_CLI" --path="$1" core is-installed > /dev/null 2>&1; }

# Where is THIS repo actually installed?
#
# Candidates, in order. The last one is the one that works for a symlinked repo, and it is the only
# one that PROVES the site is running this code: it follows each site's plugin entry and checks that
# it resolves back to this directory. A gate that checks a different copy of the plugin is worse than
# no gate.
CANDIDATES=( "${WBGAM_WP_PATH:-}" "$WP_ROOT" )

QA_WP_PATH="$(python3 -c "import json;print(json.load(open('docs/qa/qa-config.json')).get('wp_path',''))" 2>/dev/null || true)"
CANDIDATES+=( "$QA_WP_PATH" )

for site in "$HOME/Local Sites"/*/app/public; do
  [ -d "$site" ] || continue
  link="$site/wp-content/plugins/$PLUGIN_SLUG"
  [ -e "$link" ] || continue
  # Resolve the symlink and compare against this repo.
  if [ "$(cd "$link" 2>/dev/null && pwd -P)" = "$PLUGIN_DIR" ]; then
    CANDIDATES+=( "$site" )
  fi
done

FOUND=""
for c in "${CANDIDATES[@]}"; do
  if wp_is_installed "$c"; then FOUND="$c"; break; fi
done

if [ -z "$FOUND" ]; then
  printf '%s\n' "${RED}✗${RESET}  Plugin Check gate cannot run — no WordPress install found running this code."
  for c in "${CANDIDATES[@]}"; do [ -n "$c" ] && printf '    %s\n' "tried: $c"; done
  printf '    %s\n' "Set WBGAM_WP_PATH=/path/to/wp to point it at a site."
  printf '    %s\n' "This gate FAILS rather than passing green: a check that did not run is not a check."
  exit 1
fi

WP_ROOT="$FOUND"

# Run Plugin Check from the WP root — wp-cli doesn't auto-discover from a nested plugin folder.
(cd "$WP_ROOT" && "$WP_CLI" plugin check "$PLUGIN_SLUG" --format=csv 2>/dev/null) > "$RAW_CSV" || true

if [ ! -s "$RAW_CSV" ]; then
  printf '%s\n' "${RED}✗${RESET}  Plugin Check produced no output — wp-cli or Plugin Check plugin may not be available."
  exit 1
fi

# It ran, but did it INSPECT anything? No FILE: blocks means the output is not a findings report
# (an error message, a warning banner, an empty run) — and parsing that yields a confident zero.
if ! grep -q '^FILE:' "$RAW_CSV"; then
  printf '%s\n' "${RED}✗${RESET}  Plugin Check returned no FILE: blocks — it did not inspect the plugin."
  head -3 "$RAW_CSV" | sed 's/^/    /'
  exit 1
fi

# Count findings by severity, scoped to shipped code only.
# `src/`, `integrations/`, and `wb-gamification.php` are the WP.org-shipped paths.
# Everything else (examples/, dist/, build/, marketing/, sdk/, tests/) is
# excluded — those don't ship and would otherwise dominate the output.
# Plugin Check emits ABSOLUTE paths (FILE: /Users/.../wb-gamification/src/Foo.php). The filter used
# to anchor on `^src/`, which an absolute path can never match — so every finding in shipped code was
# invisible and the gate counted zero. It also has to skip `dist/`, which contains a COPY of src/ and
# would otherwise double-count (and drag in the bundled libs).
#
# So: strip everything up to and including the plugin directory, then match on what's left.
shipped() {
  awk -v sev="$1" -v dir="$PLUGIN_DIR/" '
    /^FILE:/ {
      f = $2
      sub("^" dir, "", f)                                   # absolute -> repo-relative
      ship = (f ~ /^(src\/|integrations\/|wb-gamification\.php)/)
      if (f ~ /^(dist|vendor|node_modules|tests|libs|examples|bin|stubs)\//) { ship = 0 }
    }
    ship && $0 ~ ("," sev ",") { print f ": " $0 }
  ' "$RAW_CSV"
}

errors_shipped()   { shipped ERROR;   }
warnings_shipped() { shipped WARNING; }

# Accepted ERRORs, each with a written reason in audit/plugin-check-error-baseline.txt.
#
# An entry there is a CLAIM that the finding is wrong, or that every alternative is worse for site
# owners — and the claim has to be argued in the file. It is not a place to bury findings: anything
# not listed still fails the build, so the gate keeps its teeth.
ERROR_BASELINE_FILE="audit/plugin-check-error-baseline.txt"

is_baselined() {
  [ -f "$ERROR_BASELINE_FILE" ] || return 1
  local line="$1"
  while IFS='|' read -r bfile bcode; do
    case "$bfile" in ''|\#*) continue ;; esac
    [ -z "${bcode:-}" ] && continue
    case "$line" in
      "$bfile"*"$bcode"*) return 0 ;;
    esac
  done < "$ERROR_BASELINE_FILE"
  return 1
}

ALL_ERROR_LINES=$(errors_shipped)
ERROR_LINES=""
BASELINED_COUNT=0
while IFS= read -r line; do
  [ -z "$line" ] && continue
  if is_baselined "$line"; then
    BASELINED_COUNT=$((BASELINED_COUNT + 1))
  else
    ERROR_LINES="${ERROR_LINES}${line}"$'\n'
  fi
done <<< "$ALL_ERROR_LINES"

ERROR_COUNT=$(printf '%s' "$ERROR_LINES" | grep -c '.' || true)
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
  printf '%s\n' "${GREEN}✓${RESET}  Plugin Check green — 0 unbaselined ERRORs; $WARNING_COUNT WARNINGs (down from $BASELINE_TOTAL baseline)."
  [ "$BASELINED_COUNT" -gt 0 ] && printf '    %s\n' "${YELLOW}$BASELINED_COUNT accepted ERROR(s)${RESET} — see $ERROR_BASELINE_FILE for the reason each one is accepted."
  printf '    %s\n' "${DIM}Refresh baseline to lock the gain: bin/check-plugin-check.sh --update-baseline${RESET}"
else
  printf '%s\n' "${GREEN}✓${RESET}  Plugin Check green — 0 unbaselined ERRORs; $WARNING_COUNT WARNINGs (= baseline)."
  [ "$BASELINED_COUNT" -gt 0 ] && printf '    %s\n' "${YELLOW}$BASELINED_COUNT accepted ERROR(s)${RESET} — see $ERROR_BASELINE_FILE for the reason each one is accepted."
fi
exit 0
