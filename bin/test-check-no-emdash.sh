#!/usr/bin/env bash
# test-check-no-emdash -- proves bin/check-no-emdash.sh can fail: plants one em-dash in a temp copy.
set -uo pipefail
cd "$(dirname "$0")/.."

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
mkdir -p "$work/docs"
for p in wb-gamification.php uninstall.php src integrations templates assets readme.txt CHANGELOG.md; do
  [ -e "$p" ] && cp -R "$p" "$work/"
done
cp -R docs/website "$work/docs/"

if ! NO_EMDASH_ROOT="$work" bash bin/check-no-emdash.sh >/dev/null; then
  echo "FAIL: the unbroken copy does not pass, so the case below proves nothing"; exit 1
fi
printf 'Planted \xe2\x80\x94 here\n' >> "$work/templates/emails/level-up.php"
if NO_EMDASH_ROOT="$work" bash bin/check-no-emdash.sh >/dev/null; then
  echo "FAIL: planted em-dash was not caught"; exit 1
fi
echo "ok: clean copy passes, planted em-dash fails the gate"
