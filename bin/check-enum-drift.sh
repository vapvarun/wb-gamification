#!/usr/bin/env bash
# bin/check-enum-drift.sh — catch cross-layer enum drift.
#
# The plugin has multiple controller-side validator constants (VALID_*)
# whose values must match what the corresponding engine switches handle.
# When a controller adds a case the engine forgets about (or vice versa),
# the symptom is a generic 400 / unhandled value at runtime — and
# typically a Basecamp card a week later. This gate diffs each known
# enum pair at build time so the drift fails the build, not production.
#
# The pairs were seeded by the 2026-05-27 stability audit
# (audit/STABILITY-2026-05-27.md §2). Add a new pair below when a
# controller adds a VALID_* the engine consumes elsewhere.
#
# Exit 0 = aligned; non-zero = drift detected.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

RED="\033[0;31m"; GREEN="\033[0;32m"; YELLOW="\033[0;33m"; DIM="\033[2m"; RESET="\033[0m"

FAILED=0

# Extract array string-values from a `const FOO = array( 'a', 'b', ... )`
# declaration in a PHP file. Returns one value per line, sorted unique.
# Stops at the closing `);` of the declaration so it doesn't bleed into
# later constants in the same file.
extract_const_values() {
  local file="$1" const="$2"
  awk -v c="$const" '
    $0 ~ ("const[[:space:]]+" c "[[:space:]]*=[[:space:]]*array") { capturing=1 }
    capturing {
      while (match($0, /'\''[^'\'']+'\''/) > 0) {
        print substr($0, RSTART + 1, RLENGTH - 2)
        $0 = substr($0, RSTART + RLENGTH)
      }
      if (/\);/) { exit }
    }
  ' "$file" | sort -u
}

# Extract `case 'value':` literals from a PHP file, optionally limited to
# a specific method. Returns one value per line, sorted unique.
extract_case_values() {
  local file="$1"
  grep -oE "case '[^']+'" "$file" | sed "s/case '//;s/'//" | sort -u
}

# Extract literal string values that appear as `'reason' => 'X'` so we
# can compare engine-emitted reasons to controller error-code maps.
extract_reason_values() {
  local file="$1"
  grep -oE "'reason'[[:space:]]*=>[[:space:]]*'[^']+'" "$file" \
    | sed -E "s/.*=> '([^']+)'.*/\1/" \
    | sort -u
}

# Diff two sorted-unique enum lists; report drift in either direction.
diff_enums() {
  local pair_name="$1" left_label="$2" right_label="$3"
  local left right only_left only_right

  left="$(mktemp)"; right="$(mktemp)"
  printf '%s' "$4" > "$left"
  printf '%s' "$5" > "$right"

  only_left="$(comm -23 "$left" "$right")"
  only_right="$(comm -13 "$left" "$right")"
  rm -f "$left" "$right"

  if [ -z "$only_left" ] && [ -z "$only_right" ]; then
    printf "${GREEN}✓${RESET}  %s — aligned\n" "$pair_name"
    return 0
  fi

  printf "${RED}✗${RESET}  %s — drift detected\n" "$pair_name"
  if [ -n "$only_left" ]; then
    printf "    %s has values the %s doesn't handle:\n" "$left_label" "$right_label"
    echo "$only_left" | sed 's/^/      - /'
  fi
  if [ -n "$only_right" ]; then
    printf "    %s handles values the %s doesn't allow:\n" "$right_label" "$left_label"
    echo "$only_right" | sed 's/^/      - /'
  fi
  FAILED=1
}

echo "=== enum-drift gate ==="
echo ""

# ─── Pair 1 — RedemptionController VALID_REWARD_TYPES vs RedemptionEngine ────
# Drift here caused Basecamp #9927682021 (Free Shipping → HTTP 400 from the
# controller because the validator didn't list `free_shipping` even though
# the engine switch had handled it for two releases).
LEFT_VALUES="$(extract_const_values \
  src/API/RedemptionController.php VALID_REWARD_TYPES)"
RIGHT_VALUES="$(extract_case_values src/Engine/RedemptionEngine.php)"
# The engine has cases for other constants too (e.g. point_type). Restrict
# the right-hand set to the union with the controller's surface — drift is
# only meaningful for values the controller validates. Items the engine
# uses internally and the controller never sees are out of scope.
RIGHT_RESTRICTED="$(comm -12 <(echo "$LEFT_VALUES") <(echo "$RIGHT_VALUES"); \
  comm -23 <(echo "$LEFT_VALUES") <(echo "$RIGHT_VALUES") \
  | xargs -I{} grep -l "case '{}':" src/Engine/RedemptionEngine.php 2>/dev/null \
  | sort -u)"
# Assert every controller-allowed reward_type appears at least once as a
# literal in the engine file (case branch or string compare). Values
# intentionally handled by the engine's default branch (admin-defined,
# no built-in coupon) are listed in REWARD_TYPE_DEFAULT_BRANCH below to
# suppress false positives.
REWARD_TYPE_DEFAULT_BRANCH="custom"
MISSING_IN_ENGINE=""
while IFS= read -r value; do
  [ -z "$value" ] && continue
  if echo "$REWARD_TYPE_DEFAULT_BRANCH" | grep -qwx "$value"; then
    continue
  fi
  if ! grep -q "'$value'" src/Engine/RedemptionEngine.php; then
    MISSING_IN_ENGINE="${MISSING_IN_ENGINE}${value}\n"
  fi
done <<< "$LEFT_VALUES"

if [ -z "$MISSING_IN_ENGINE" ]; then
  printf "${GREEN}✓${RESET}  RedemptionController::VALID_REWARD_TYPES ↔ RedemptionEngine — aligned\n"
else
  printf "${RED}✗${RESET}  RedemptionController::VALID_REWARD_TYPES — drift detected\n"
  printf "    Reward types the controller allows but the engine never references:\n"
  printf "$MISSING_IN_ENGINE" | sed 's/^/      - /'
  FAILED=1
fi

# ─── Pair 2 — RedemptionEngine reasons ↔ RedemptionController error-map ──────
# Drift here caused Basecamp #9927027277 (engine returned 'not_found' but
# the controller's match() arm only mapped 'inactive' / 'insufficient' →
# generic redemption_failed for the not_found case).
ENGINE_REASONS="$(extract_reason_values src/Engine/RedemptionEngine.php)"
CONTROLLER_REASON_MATCHES="$(grep -oE "'[a-z_]+'\s*=>\s*'wb_gam_redemption_[a-z_]+'" \
  src/API/RedemptionController.php \
  | grep -oE "^'[a-z_]+'" \
  | tr -d "'" \
  | sort -u)"

MISSING_IN_CONTROLLER=""
while IFS= read -r reason; do
  [ -z "$reason" ] && continue
  if ! echo "$CONTROLLER_REASON_MATCHES" | grep -qx "$reason"; then
    MISSING_IN_CONTROLLER="${MISSING_IN_CONTROLLER}${reason}\n"
  fi
done <<< "$ENGINE_REASONS"

if [ -z "$MISSING_IN_CONTROLLER" ]; then
  printf "${GREEN}✓${RESET}  RedemptionEngine reasons ↔ RedemptionController error map — aligned\n"
else
  printf "${YELLOW}!${RESET}  RedemptionEngine reasons — controller doesn't map every reason to a specific error code\n"
  printf "    Reasons returned by the engine that the controller falls through on:\n"
  printf "$MISSING_IN_CONTROLLER" | sed 's/^/      - /'
  printf "    These will surface as the generic wb_gam_redemption_failed code.\n"
  printf "    Add a match() arm in RedemptionController::redeem(). Not a build-fail.\n"
fi

# ─── Pair 3 — WebhooksController VALID_EVENTS ↔ engine event emitters ────────
# Drift here caused Basecamp #1.2.0 dev-loop bug (webhook subscribers
# couldn't subscribe to `badge_awarded` because controller called it
# `badge_earned`; fixed by aligning names).
EVENT_NAMES="$(extract_const_values \
  src/API/WebhooksController.php VALID_EVENTS)"
MISSING_EMITTER=""
while IFS= read -r event; do
  [ -z "$event" ] && continue
  # The webhook dispatcher names the event verbatim; match against any
  # `'event' => 'X'` or `'event_name' => 'X'` or do_action argument.
  if ! grep -rq "['\"]$event['\"]" src/Engine/ 2>/dev/null; then
    MISSING_EMITTER="${MISSING_EMITTER}${event}\n"
  fi
done <<< "$EVENT_NAMES"

if [ -z "$MISSING_EMITTER" ]; then
  printf "${GREEN}✓${RESET}  WebhooksController::VALID_EVENTS ↔ engine emitters — aligned\n"
else
  printf "${RED}✗${RESET}  WebhooksController::VALID_EVENTS — drift detected\n"
  printf "    Events the controller allows subscribing to but no engine emits:\n"
  printf "$MISSING_EMITTER" | sed 's/^/      - /'
  FAILED=1
fi

# ─── Pair 4 — RulesController VALID_RULE_TYPES ↔ RuleEngine ──────────────────
# Defensive — the rule engine is new and the surface is small (2 values).
# Drift here would silently disable a feature.
RULE_TYPES="$(extract_const_values \
  src/API/RulesController.php VALID_RULE_TYPES)"
MISSING_RULE_TYPE=""
while IFS= read -r rt; do
  [ -z "$rt" ] && continue
  # RuleEngine reads rule_type from the row; expect it to be referenced
  # as a string literal somewhere in the engine layer.
  if ! grep -rq "['\"]$rt['\"]" src/Engine/ 2>/dev/null; then
    MISSING_RULE_TYPE="${MISSING_RULE_TYPE}${rt}\n"
  fi
done <<< "$RULE_TYPES"

if [ -z "$MISSING_RULE_TYPE" ]; then
  printf "${GREEN}✓${RESET}  RulesController::VALID_RULE_TYPES ↔ RuleEngine — aligned\n"
else
  printf "${RED}✗${RESET}  RulesController::VALID_RULE_TYPES — drift detected\n"
  printf "    Rule types the controller allows but no engine consumes:\n"
  printf "$MISSING_RULE_TYPE" | sed 's/^/      - /'
  FAILED=1
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
  printf "${GREEN}enum-drift gate green${RESET}\n"
  exit 0
else
  printf "${RED}enum-drift gate failed${RESET} — fix the drift above before pushing.\n"
  printf "${DIM}To add a new enum pair, edit bin/check-enum-drift.sh.${RESET}\n"
  exit 1
fi
