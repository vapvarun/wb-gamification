#!/usr/bin/env bash
# check-no-emdash -- no em-dash (U+2014) in anything the plugin ships as source.
#
# Owner rule: customer-facing text uses a plain hyphen ( - ), never the em-dash character.
# Scanned paths are the shipped SOURCE; generated output (build/, dist/), vendored code (libs/,
# vendor/, node_modules/) and translation files (languages/) are not scanned.
#
# NO_EMDASH_ROOT=/some/copy bash bin/check-no-emdash.sh   # scan another tree (used by the self-test)
# Proof it can fail: bash bin/test-check-no-emdash.sh

set -uo pipefail
cd "${NO_EMDASH_ROOT:-$(dirname "$0")/..}"

PATHS=(wb-gamification.php uninstall.php src integrations templates assets docs/website readme.txt CHANGELOG.md)

existing=()
for p in "${PATHS[@]}"; do [ -e "$p" ] && existing+=("$p"); done

hits="$(grep -rInF $'\xe2\x80\x94' "${existing[@]}" 2>/dev/null)"
if [ -n "$hits" ]; then
  printf '\033[31mFAIL\033[0m em-dash (U+2014) found in shipped source (use " - "):\n'
  printf '%s\n' "$hits" | head -20 | sed 's/^/       /'
  printf '       %s hit(s) in total\n' "$(printf '%s\n' "$hits" | wc -l | tr -d ' ')"
  exit 1
fi
printf '\033[32mok\033[0m   no em-dash in %s\n' "${PATHS[*]}"
