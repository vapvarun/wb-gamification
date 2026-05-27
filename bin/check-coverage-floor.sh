#!/usr/bin/env bash
# bin/check-coverage-floor.sh — block coverage regression.
#
# PHPUnit line coverage was unmeasured until the 2026-05-27 stability
# audit. Baseline at that point: 3.79% lines / 5.60% methods. That's
# thin — most of the engine is integration-flavoured logic and its
# tests mock at the boundary — but the floor's job is not to hit a
# target, it's to stop the number sliding the wrong way. Quarterly
# review can raise the floor as the suite grows.
#
# The gate parses build/coverage/coverage.txt (PHPUnit's text-summary
# report) and fails if Lines% < FLOOR_LINES or Methods% < FLOOR_METHODS.
#
# Updating the floor: edit FLOOR_LINES / FLOOR_METHODS below in the
# same commit that adds the new tests. Don't lower the floor.
#
# Exit 0 = at or above floor; exit 1 = regression detected.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'

# Floor numbers — never lower these. To raise, ship the test-add commit
# that pushes the actual number up first, then bump the floor here.
FLOOR_LINES="3.5"
FLOOR_METHODS="5.0"

COVERAGE_FILE="build/coverage/coverage.txt"

if [ ! -f "$COVERAGE_FILE" ]; then
  printf '%s\n' "${YELLOW}!${RESET}  No coverage report at $COVERAGE_FILE"
  printf '    %s\n' "Run: XDEBUG_MODE=coverage composer test:coverage"
  printf '    %s\n' "${DIM}This stage is skipped on no-coverage CI runs; only the dedicated coverage stage produces the artifact.${RESET}"
  exit 0
fi

# coverage.txt format (PHPUnit 11):
#   Code Coverage Report Summary:
#     Classes:  0.00% (0/120)
#     Methods:  5.60% (46/821)
#     Lines:    3.79% (763/20138)
LINES_PCT=$(grep -E '^\s*Lines:' "$COVERAGE_FILE" | awk -F'[: %]+' '{print $3}' | head -1)
METHODS_PCT=$(grep -E '^\s*Methods:' "$COVERAGE_FILE" | awk -F'[: %]+' '{print $3}' | head -1)

if [ -z "$LINES_PCT" ] || [ -z "$METHODS_PCT" ]; then
  printf '%s\n' "${RED}✗${RESET}  Could not parse coverage from $COVERAGE_FILE"
  cat "$COVERAGE_FILE" | head -10
  exit 1
fi

# awk comparison since bash can't compare floats natively.
lines_ok=$(awk -v a="$LINES_PCT" -v b="$FLOOR_LINES" 'BEGIN{print (a+0 >= b+0) ? 1 : 0}')
methods_ok=$(awk -v a="$METHODS_PCT" -v b="$FLOOR_METHODS" 'BEGIN{print (a+0 >= b+0) ? 1 : 0}')

if [ "$lines_ok" = "1" ] && [ "$methods_ok" = "1" ]; then
  printf '%s\n' "${GREEN}✓${RESET}  Coverage floor met — Lines: ${LINES_PCT}% (floor ${FLOOR_LINES}%) · Methods: ${METHODS_PCT}% (floor ${FLOOR_METHODS}%)"
  exit 0
fi

printf '%s\n' "${RED}✗${RESET}  Coverage regression detected:"
[ "$lines_ok"   = "0" ] && printf '    Lines:   %s%% (floor %s%%) — DROPPED\n' "$LINES_PCT"   "$FLOOR_LINES"
[ "$lines_ok"   = "1" ] && printf '    Lines:   %s%% (floor %s%%) — ok\n'      "$LINES_PCT"   "$FLOOR_LINES"
[ "$methods_ok" = "0" ] && printf '    Methods: %s%% (floor %s%%) — DROPPED\n' "$METHODS_PCT" "$FLOOR_METHODS"
[ "$methods_ok" = "1" ] && printf '    Methods: %s%% (floor %s%%) — ok\n'      "$METHODS_PCT" "$FLOOR_METHODS"
echo ""
echo "    Add tests to restore the floor, or update the floor in"
echo "    bin/check-coverage-floor.sh if a refactor genuinely retired"
echo "    code paths that were previously covered."
exit 1
