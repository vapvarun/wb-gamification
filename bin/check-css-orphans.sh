#!/usr/bin/env bash
# bin/check-css-orphans.sh — catch PHP-CSS class name drift.
#
# When a PHP template emits `class="wbgam-switch__slider"` but the CSS
# only defines `.wbgam-switch__track`, the UI silently falls apart —
# the styled element is invisible / unclickable until someone notices
# in QA. The Setup Wizard email toggles (Basecamp #9925205802 Issue 2)
# hit exactly this pattern: PHP template said `__slider`, CSS knew
# `__track`, switch was invisible for weeks.
#
# This gate extracts every `wbgam-*` / `wb-gam-*` class name used in
# any class= attribute in the PHP source, then verifies each one
# appears as a selector in at least one CSS file. Classes that look
# like PHP variable interpolation (e.g. `wb-gam-mode-badge--` waiting
# for `{$mode}`) are filtered out.
#
# Baseline-driven: existing orphans (56 at commit 343b0a1) are recorded
# in audit/css-orphan-baseline.txt — gate fails only when the orphan
# set GROWS. Refresh the baseline whenever an orphan is legitimately
# fixed (delete it from the baseline file in the same commit).
#
# Exit 0 = no new orphans; exit 1 = at least one new orphan vs baseline.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

BASELINE_FILE="audit/css-orphan-baseline.txt"
RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'

# 1. Extract every PHP-side wbgam-/wb-gam- class name from class="…" attrs.
#    Includes compound classes (`class="foo bar"` → both `foo` and `bar`).
#    Excludes any value containing a PHP tag, brace, percent, or angle
#    bracket — those are interpolation placeholders, not real class names.
php_classes() {
  grep -rhoE 'class="[^"]*"' \
    src/ templates/ integrations/ wb-gamification.php \
    --include='*.php' 2>/dev/null \
    | grep -oE '\b(wbgam|wb-gam)-[a-zA-Z0-9_-]+' \
    | grep -vE '^(wbgam|wb-gam)-[a-zA-Z0-9_-]+--$' \
    | sort -u
}

# 2. Extract every CSS selector class name from every non-minified,
#    non-RTL CSS file. Includes admin.css, frontend.css, hub.css,
#    share-page.css, give-kudos.css, plus every per-block style.css
#    under src/Blocks/<slug>/.
css_selectors() {
  find assets/css src/Blocks \
    -name '*.css' \
    -not -name '*.min.css' \
    -not -name '*-rtl.css' \
    -exec grep -hoE '\.(wbgam|wb-gam)-[a-zA-Z0-9_-]+' {} \; 2>/dev/null \
    | sed 's/^\.//' \
    | sort -u
}

# 3. Orphans = PHP classes that have no matching CSS selector anywhere.
CURRENT_ORPHANS=$(comm -23 <(php_classes) <(css_selectors))
CURRENT_COUNT=$(printf '%s\n' "$CURRENT_ORPHANS" | grep -c '.' || true)
[ -z "$CURRENT_COUNT" ] && CURRENT_COUNT=0

# 4. Compare against baseline. New entries fail the gate.
if [ ! -f "$BASELINE_FILE" ]; then
  echo "${YELLOW}!${RESET}  No baseline found at $BASELINE_FILE"
  echo "    Run the following to seed it:"
  echo "${DIM}    bash bin/check-css-orphans.sh --update-baseline${RESET}"
  if [ "${1:-}" = "--update-baseline" ]; then
    mkdir -p "$(dirname "$BASELINE_FILE")"
    {
      echo "# bin/check-css-orphans.sh baseline — PHP classes with no matching CSS rule."
      echo "# Each line is a class name. New orphans fail the gate; legitimate fixes"
      echo "# (matching CSS added) should DELETE the line from this file in the same commit."
      echo "# Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
      echo ""
      echo "$CURRENT_ORPHANS"
    } > "$BASELINE_FILE"
    echo "${GREEN}✓${RESET}  Baseline written with $CURRENT_COUNT orphans."
    exit 0
  fi
  exit 1
fi

BASELINE_ORPHANS=$(grep -v '^#\|^$' "$BASELINE_FILE" | sort -u)
BASELINE_COUNT=$(printf '%s\n' "$BASELINE_ORPHANS" | grep -c '.' || true)
[ -z "$BASELINE_COUNT" ] && BASELINE_COUNT=0

NEW_ORPHANS=$(comm -23 <(printf '%s\n' "$CURRENT_ORPHANS") <(printf '%s\n' "$BASELINE_ORPHANS"))
FIXED_ORPHANS=$(comm -13 <(printf '%s\n' "$CURRENT_ORPHANS") <(printf '%s\n' "$BASELINE_ORPHANS"))

NEW_COUNT=$(printf '%s\n' "$NEW_ORPHANS" | grep -c '.' || true)
[ -z "$NEW_COUNT" ] && NEW_COUNT=0
FIXED_COUNT=$(printf '%s\n' "$FIXED_ORPHANS" | grep -c '.' || true)
[ -z "$FIXED_COUNT" ] && FIXED_COUNT=0

if [ "${1:-}" = "--update-baseline" ]; then
  mkdir -p "$(dirname "$BASELINE_FILE")"
  {
    echo "# bin/check-css-orphans.sh baseline — PHP classes with no matching CSS rule."
    echo "# Each line is a class name. New orphans fail the gate; legitimate fixes"
    echo "# (matching CSS added) should DELETE the line from this file in the same commit."
    echo "# Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo ""
    echo "$CURRENT_ORPHANS"
  } > "$BASELINE_FILE"
  echo "${GREEN}✓${RESET}  Baseline refreshed ($CURRENT_COUNT orphans)."
  exit 0
fi

if [ "$NEW_COUNT" -eq 0 ]; then
  if [ "$FIXED_COUNT" -gt 0 ]; then
    echo "${GREEN}✓${RESET}  CSS-orphan gate green — $FIXED_COUNT orphan(s) fixed since baseline:"
    echo "$FIXED_ORPHANS" | sed 's/^/      ✓ /'
    echo "    ${DIM}Refresh the baseline: bin/check-css-orphans.sh --update-baseline${RESET}"
  else
    echo "${GREEN}✓${RESET}  CSS-orphan gate green — $BASELINE_COUNT known orphans, no new drift."
  fi
  exit 0
fi

echo "${RED}✗${RESET}  CSS-orphan gate failed — $NEW_COUNT new PHP class(es) have no matching CSS rule:"
echo "$NEW_ORPHANS" | sed 's/^/      - /'
echo ""
echo "    Each entry above is a PHP template emitting a class= value that no CSS file"
echo "    defines. That's the same bug class as the Setup Wizard switch (Basecamp"
echo "    #9925205802) where PHP said __slider but CSS knew __track."
echo ""
echo "    Fix options (per class):"
echo "    1. Add the matching CSS rule (the usual case)."
echo "    2. If the PHP is using a class that's intentionally defined elsewhere"
echo "       (theme override, block-level scoped CSS), document why + refresh"
echo "       the baseline: bin/check-css-orphans.sh --update-baseline"
exit 1
