#!/usr/bin/env bash
# bin/check-action-async.sh — every repeatable action manifest must declare
# `async` explicitly.
#
# The WooCommerce-events bug (Basecamp #9925589914) had this root cause:
# every WC action manifest set `repeatable=true` and let `async` default-
# derive (Registry uses null → true). That routed wc_order_completed
# through Action Scheduler even though the user expects points the moment
# checkout completes. Fix (commit 5928f95) was explicit `'async' => false`
# on user-initiated actions.
#
# This gate makes the choice mandatory — `repeatable=true` MUST be paired
# with an explicit `async` line in the same manifest entry. Default-routing
# is the smell.
#
# Baseline-driven via audit/action-async-baseline.txt — same shape as
# bin/check-css-orphans.sh. Existing implicit-async actions are recorded
# at gate-introduction time so the build stays green; new ones fail.
#
# Exit 0 = no new implicit-async entries; exit 1 = at least one new.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

BASELINE_FILE="audit/action-async-baseline.txt"
RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'

# Extract every action-manifest trigger entry that sets repeatable=true
# but does NOT declare async explicitly. Manifest files live under
# integrations/ and return arrays shaped like:
#
#   [
#       'id'         => 'wc_order_completed',
#       'repeatable' => true,
#       'async'      => false,    // ← optional; default-derived if absent
#       ...
#   ],
#
# The parser is a per-file awk state machine: it tracks the `'id' =>`
# line as the start of a trigger block, then for each subsequent line
# until the closing `],` records whether `'repeatable' => true` and
# `'async'` appeared. A trigger with `repeatable=true` and no `async`
# key is emitted as `<file>:<line> <id>`.
find_implicit_async() {
  find integrations \
    -name '*.php' \
    -not -path '*/vendor/*' \
    -not -path '*/node_modules/*' \
    -print0 2>/dev/null \
    | xargs -0 awk '
      function flush() {
        if (in_trigger && rep && !async && id != "") {
          print FILENAME ":" id_line " " id
        }
        in_trigger=0; id=""; rep=0; async=0; id_line=0
      }
      # New trigger block starts at the line containing an 'id' => key
      # (no nesting — the block ends at the next '],' on its own).
      match($0, /[\x27"]id[\x27"][[:space:]]*=>[[:space:]]*[\x27"][^\x27"]+[\x27"]/) {
        flush()  # close any open trigger first
        s = substr($0, RSTART, RLENGTH)
        sub(/^[^\x27"]*[\x27"]id[\x27"][[:space:]]*=>[[:space:]]*[\x27"]/, "", s)
        sub(/[\x27"].*/, "", s)
        id = s
        id_line = NR
        in_trigger = 1
        next
      }
      in_trigger && /[\x27"]repeatable[\x27"][[:space:]]*=>[[:space:]]*true/ { rep = 1 }
      in_trigger && /[\x27"]async[\x27"][[:space:]]*=>/                       { async = 1 }
      # Closing of a trigger block: `],` on its own (or near-on-own) line.
      in_trigger && /^[[:space:]]*\],?[[:space:]]*$/ { flush() }
      END { flush() }
    ' \
    | sort -u
}

CURRENT=$(find_implicit_async)
CURRENT_COUNT=$(printf '%s\n' "$CURRENT" | grep -c '.' || true)
[ -z "$CURRENT_COUNT" ] && CURRENT_COUNT=0

if [ ! -f "$BASELINE_FILE" ]; then
  printf '%s\n' "${YELLOW}!${RESET}  No baseline found at $BASELINE_FILE"
  printf '    %s\n' "Seed it: bin/check-action-async.sh --update-baseline"
  if [ "${1:-}" = "--update-baseline" ]; then
    mkdir -p "$(dirname "$BASELINE_FILE")"
    {
      echo "# bin/check-action-async.sh baseline — register_action() call sites that"
      echo "# set repeatable=true but don't declare async explicitly. Each non-comment"
      echo "# line is '<file>:<line> <action_id>'. New entries fail the gate; legitimate"
      echo "# fixes (explicit 'async' => true|false added) should DELETE the line in the"
      echo "# same commit."
      echo "# Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
      echo ""
      printf '%s\n' "$CURRENT"
    } > "$BASELINE_FILE"
    printf '%s\n' "${GREEN}✓${RESET}  Baseline written with $CURRENT_COUNT implicit-async action(s)."
    exit 0
  fi
  exit 1
fi

BASELINE=$(grep -v '^#\|^$' "$BASELINE_FILE" | sort -u)
NEW=$(comm -23 <(printf '%s\n' "$CURRENT") <(printf '%s\n' "$BASELINE"))
FIXED=$(comm -13 <(printf '%s\n' "$CURRENT") <(printf '%s\n' "$BASELINE"))
NEW_COUNT=$(printf '%s\n' "$NEW" | grep -c '.' || true)
[ -z "$NEW_COUNT" ] && NEW_COUNT=0
FIXED_COUNT=$(printf '%s\n' "$FIXED" | grep -c '.' || true)
[ -z "$FIXED_COUNT" ] && FIXED_COUNT=0

if [ "${1:-}" = "--update-baseline" ]; then
  mkdir -p "$(dirname "$BASELINE_FILE")"
  {
    echo "# bin/check-action-async.sh baseline — register_action() call sites that"
    echo "# set repeatable=true but don't declare async explicitly. Each non-comment"
    echo "# line is '<file>:<line> <action_id>'. New entries fail the gate; legitimate"
    echo "# fixes (explicit 'async' => true|false added) should DELETE the line in the"
    echo "# same commit."
    echo "# Generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo ""
    printf '%s\n' "$CURRENT"
  } > "$BASELINE_FILE"
  printf '%s\n' "${GREEN}✓${RESET}  Baseline refreshed ($CURRENT_COUNT implicit-async action(s))."
  exit 0
fi

if [ "$NEW_COUNT" -eq 0 ]; then
  if [ "$FIXED_COUNT" -gt 0 ]; then
    printf '%s\n' "${GREEN}✓${RESET}  Action async gate green — $FIXED_COUNT entry(ies) made explicit since baseline:"
    printf '%s\n' "$FIXED" | sed 's/^/      ✓ /'
    printf '    %s\n' "${DIM}Refresh baseline: bin/check-action-async.sh --update-baseline${RESET}"
  else
    printf '%s\n' "${GREEN}✓${RESET}  Action async gate green — no new implicit-async actions."
  fi
  exit 0
fi

printf '%s\n' "${RED}✗${RESET}  Action async gate failed — $NEW_COUNT new register_action() entry(ies) set repeatable=true without declaring async:"
printf '%s\n' "$NEW" | sed 's/^/      - /'
echo ""
echo "    The Registry defaults async to derive from repeatable (true → async)."
echo "    That routes the action through Action Scheduler — fine for high-volume"
echo "    background work, wrong for user-initiated actions where the customer"
echo "    expects an immediate award (see Basecamp #9925589914 — WooCommerce"
echo "    events not firing was this exact bug)."
echo ""
echo "    Fix: declare 'async' explicitly in the affected manifest entry."
echo "      'async' => true  → defer through Action Scheduler (high-volume bg)"
echo "      'async' => false → award in-request (user-initiated, low-frequency)"
exit 1
