#!/usr/bin/env bash
# bin/check-event-wiring.sh — assert critical internal events are both
# fired AND subscribed.
#
# The redemption-email bug (Basecamp #9927383947) had this root cause:
# RedemptionEngine::redeem() fires `wb_gam_points_redeemed` correctly,
# but TransactionalEmailEngine forgot to subscribe — so the engine's
# UI promised "Check your email" while no email ever sent. Fix shipped
# in commit ab7e79e.
#
# This gate maintains a list of "critical internal events" — hooks that
# MUST have at least one in-plugin listener for the feature to work.
# For each entry, the gate verifies:
#   1. The event is fired somewhere via `do_action('<event>', ...)`.
#   2. At least one `add_action('<event>', ...)` exists in src/.
#
# Both halves matter: a listener with no firer is dead code; a firer
# with no listener is the redemption-email bug.
#
# Extension-point hooks (filters / fired-for-third-parties events) are
# NOT in scope here — the wb_gamification_* surface intentionally fires
# many hooks for ecosystem consumers. This gate is about contracts
# inside the plugin where both sides exist in this repo.
#
# Adding a new critical event: append to CRITICAL_EVENTS below + commit
# the listener at the same time.
#
# Exit 0 = every critical event has a firer + a listener; exit 1 = at
# least one half is missing.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; DIM=$'\033[2m'; RESET=$'\033[0m'

# Critical events — both sides MUST exist. Add a new entry when a new
# internal event-driven contract lands. Comment column is for grep
# friendliness, not parsed.
CRITICAL_EVENTS=(
  # event                              # why it's critical
  "wb_gam_level_changed"               # triggers level-up email + leaderboard cache bust
  "wb_gam_badge_awarded"               # triggers badge-earned email + OG share image
  "wb_gam_challenge_completed"         # triggers challenge-completed email
  "wb_gam_points_redeemed"             # triggers redemption-confirmed email — was the bug
  "wb_gam_streak_milestone"            # triggers streak milestone notice
  "wb_gam_kudos_given"                 # cooldown enforcement + notification
  "wb_gam_points_awarded"              # triggers leaderboard cache invalidation
)

FAILED=0
MISSING_FIRER=()
MISSING_LISTENER=()

for event in "${CRITICAL_EVENTS[@]}"; do
  # Strip any inline `# comment` if shellcheck'd in different ways.
  event="${event%% *}"
  [ -z "$event" ] && continue

  firer_count=$(grep -rh "do_action[[:space:]]*([[:space:]]*['\"]${event}['\"]" \
                  src/ integrations/ --include='*.php' 2>/dev/null \
                  | grep -v '^[[:space:]]*//' \
                  | wc -l | tr -d ' ')
  listener_count=$(grep -rh "add_action[[:space:]]*([[:space:]]*['\"]${event}['\"]" \
                     src/ integrations/ --include='*.php' 2>/dev/null \
                     | grep -v '^[[:space:]]*//' \
                     | wc -l | tr -d ' ')

  if [ "$firer_count" -eq 0 ]; then
    MISSING_FIRER+=("$event")
    FAILED=1
  fi
  if [ "$listener_count" -eq 0 ]; then
    MISSING_LISTENER+=("$event")
    FAILED=1
  fi
done

if [ "$FAILED" -eq 0 ]; then
  printf '%s\n' "${GREEN}✓${RESET}  Event-wiring gate green — ${#CRITICAL_EVENTS[@]} critical event(s) have firers + listeners."
  exit 0
fi

printf '%s\n' "${RED}✗${RESET}  Event-wiring gate failed — critical contracts broken:"

if [ ${#MISSING_FIRER[@]} -gt 0 ]; then
  echo "    Events declared critical but never fired:"
  for e in "${MISSING_FIRER[@]}"; do
    printf '      - %s\n' "$e"
  done
  echo "    ${DIM}Either fire the event from the engine, or remove it from CRITICAL_EVENTS in bin/check-event-wiring.sh.${RESET}"
fi

if [ ${#MISSING_LISTENER[@]} -gt 0 ]; then
  echo "    Events fired but no in-plugin listener (the redemption-email bug class):"
  for e in "${MISSING_LISTENER[@]}"; do
    printf '      - %s\n' "$e"
  done
  echo "    ${DIM}Add an add_action() in the matching engine class. See TransactionalEmailEngine::init() for the pattern.${RESET}"
fi

exit 1
