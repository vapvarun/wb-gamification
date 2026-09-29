#!/usr/bin/env bash
# bin/check-motion-tokens.sh: keep raw motion values out of component CSS.
#
# The locked motion scale lives in src/shared/design-tokens.css (durations, easing) and its
# keyframes in assets/css/popups.css. Those two files are the ONLY places a raw duration or a
# cubic-bezier may appear; everything else reads a var(--wb-gam-dur*) / var(--wb-gam-ease*) token,
# so one edit retunes every popup and hover in the family and a host theme can override it.
#
# Counts, per file, the lines that set a transition/animation with a literal duration
# (`0.2s`, `300ms`) or any cubic-bezier. Baseline-driven: existing raw lines are recorded in
# audit/motion-token-baseline.txt and the gate fails only when a file's count GROWS or a new file
# appears. Burn the baseline down by migrating a file to tokens and refreshing it in the same commit.
#
# Exit 0 = no new raw motion values; exit 1 = a file grew or a new file has some.
#
#   bin/check-motion-tokens.sh                    run the gate
#   bin/check-motion-tokens.sh --update-baseline  rewrite the baseline from the current tree

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

BASELINE="audit/motion-token-baseline.txt"
RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; DIM=$'\033[2m'; RESET=$'\033[0m'
PATTERN='(transition|animation)[a-z-]*:[^;{}]*[^a-z-][0-9.]+m?s([^a-z]|$)|cubic-bezier'

current() {
  find assets/css src/Blocks src/shared -name '*.css' \
    -not -name '*.min.css' -not -name '*-rtl.css' \
    -not -name popups.css -not -name design-tokens.css 2>/dev/null \
    | sort \
    | while read -r f; do
        n=$(grep -cE "$PATTERN" "$f" 2>/dev/null || true)
        [ "${n:-0}" -gt 0 ] && echo "$n $f"
      done
}

if [ "${1:-}" = "--update-baseline" ]; then
  current > "$BASELINE"
  echo "${GREEN}Baseline written:${RESET} $(wc -l < "$BASELINE" | tr -d ' ') files, $(awk '{s+=$1} END {print s+0}' "$BASELINE") raw lines."
  exit 0
fi

[ -f "$BASELINE" ] || { echo "${RED}FAIL${RESET} missing $BASELINE (run --update-baseline)"; exit 1; }

fail=0
while read -r n f; do
  allowed=$(awk -v f="$f" '$2 == f {print $1}' "$BASELINE")
  allowed=${allowed:-0}
  if [ "$n" -gt "$allowed" ]; then
    echo "${RED}FAIL${RESET} $f: $n raw motion line(s), baseline $allowed. Use var(--wb-gam-dur*) / var(--wb-gam-ease*)."
    grep -nE "$PATTERN" "$f" | sed 's/^/       /'
    fail=1
  fi
done < <(current)

if [ "$fail" -eq 0 ]; then
  echo "${GREEN}PASS${RESET} ${DIM}no new raw motion values ($(awk '{s+=$1} END {print s+0}' "$BASELINE") baselined)${RESET}"
fi
exit "$fail"
