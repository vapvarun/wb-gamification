#!/usr/bin/env bash
# test-check-i18n -- proves bin/check-i18n.sh can fail, one defect at a time.
#
# A gate that has only ever printed "passed" has not been shown to check anything. This copies the
# real languages dir, breaks one thing per case, and requires the gate to exit non-zero and name the
# problem. The unbroken copy must pass first, so a failure below is caused by the break and nothing else.
#
# Usage: bash bin/test-check-i18n.sh

set -uo pipefail
cd "$(dirname "$0")/.."

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

run_gate() { I18N_LANG_DIR="$1" bash bin/check-i18n.sh 2>&1; }

failures=0
expect_fail() {
  local name="$1" dir="$2" needle="$3" out rc
  out="$(run_gate "$dir")"; rc=$?
  if [ "$rc" -ne 0 ] && grep -q "$needle" <<<"$out"; then
    printf '  \033[32mok\033[0m   %s: gate failed with "%s"\n' "$name" "$needle"
  else
    printf '  \033[31mFAIL\033[0m %s: expected a failure containing "%s" (exit %s)\n' "$name" "$needle" "$rc"
    printf '%s\n' "$out" | tail -8 | sed 's/^/       /'
    failures=$((failures + 1))
  fi
}
fresh_copy() { rm -rf "$work/$1"; cp -R languages "$work/$1"; }

echo "i18n gate self-test"

# Precondition: the real tree passes, or no case below proves anything.
if ! out="$(run_gate languages)"; then
  printf '  \033[31mFAIL\033[0m the unbroken languages dir does not pass the gate, so the cases below prove nothing\n'
  printf '%s\n' "$out" | tail -8 | sed 's/^/       /'
  exit 1
fi
printf '  \033[32mok\033[0m   unbroken copy passes\n'

PO="wb-gamification-de_DE.po"

# 1. An empty translation.
fresh_copy c1
python3 - "$work/c1/$PO" <<'EOF'
import re, sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
s = re.sub(r'(msgid "Daily limit reached"\nmsgstr )"[^"\n]*"', r'\1""', s, count=1)
open(p, 'w', encoding='utf-8').write(s)
EOF
expect_fail "empty msgstr" "$work/c1" "untranslated"

# 2. A fuzzy entry.
fresh_copy c2
python3 - "$work/c2/$PO" <<'EOF'
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
s = s.replace('msgid "Daily limit reached"', '#, fuzzy\nmsgid "Daily limit reached"', 1)
open(p, 'w', encoding='utf-8').write(s)
EOF
expect_fail "fuzzy entry" "$work/c2" "fuzzy"

# 3. A placeholder that does not match the msgid.
fresh_copy c3
python3 - "$work/c3/$PO" <<'EOF'
import re, sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
s = re.sub(r'(msgid "You reached %s!"\nmsgstr )"[^"\n]*"', r'\1"Du hast es geschafft!"', s, count=1)
open(p, 'w', encoding='utf-8').write(s)
EOF
expect_fail "placeholder dropped" "$work/c3" "placeholder"

# 4. A string in the code that is not in the .pot (here: removed from the .pot).
fresh_copy c4
python3 - "$work/c4/wb-gamification.pot" <<'EOF'
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
i = s.index('msgid "Daily limit reached"'); j = s.index('\n\n', i)
open(p, 'w', encoding='utf-8').write(s[:i] + s[j + 2:])
EOF
expect_fail "string missing from the .pot" "$work/c4" "POT drift"

# 5. A string in the .pot that a .po lacks.
fresh_copy c5
python3 - "$work/c5/$PO" <<'EOF'
import sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
i = s.index('msgid "Daily limit reached"'); j = s.index('\n\n', i)
open(p, 'w', encoding='utf-8').write(s[:i] + s[j + 2:])
EOF
expect_fail "string missing from a .po" "$work/c5" "missing from the .po"

# 6. A stale .mo (another locale's file under this name).
fresh_copy c6
cp "$work/c6/wb-gamification-fr_FR.mo" "$work/c6/wb-gamification-de_DE.mo"
expect_fail "stale .mo" "$work/c6" "not built from the current .po"

# 7. A missing script translation file.
fresh_copy c7
rm -f "$(ls "$work"/c7/wb-gamification-de_DE-*.json | head -1)"
expect_fail "missing .json" "$work/c7" "not shipped"

# 8. A stale .l10n.php.
fresh_copy c8
printf '<?php\nreturn array();\n' > "$work/c8/wb-gamification-de_DE.l10n.php"
expect_fail "stale .l10n.php" "$work/c8" "differ from the .po build"

# 9. A .json that belongs to no script.
fresh_copy c9
cp "$(ls "$work"/c9/wb-gamification-de_DE-*.json | head -1)" "$work/c9/wb-gamification-de_DE-00000000000000000000000000000000.json"
expect_fail "orphan .json" "$work/c9" "belong to no script"

echo
if [ "$failures" -gt 0 ]; then
  printf '\033[31m%d case(s) did not fail the gate\033[0m\n' "$failures"
  exit 1
fi
printf '\033[32mAll 9 cases fail the gate as they should\033[0m\n'
