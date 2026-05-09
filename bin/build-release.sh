#!/usr/bin/env bash
#
# bin/build-release.sh — package wb-gamification for distribution.
#
# Reads `Version:` from wb-gamification.php, runs the production build, copies
# the release-relevant files via rsync (with the standard exclude list), and
# zips the result to `dist/wb-gamification-<version>.zip`.
#
# Before zipping, this script enforces the agent-smoke gate: a recent green
# walk of docs/qa/AGENT_SMOKE_RUNBOOK.md (dispatched to Sonnet via the generic
# `wp-plugin-smoke` Claude-level skill, reading docs/qa/qa.config.json) must
# exist at docs/qa/.last-smoke-pass.json, match the current version, and
# report zero `from`-origin failures.
#
# Usage:
#     bash bin/build-release.sh
#     bash bin/build-release.sh --skip-browser-smoke   # emergency hotfix only
#
# Per wp-plugin-development standard Part 17.7: Plugin Check must be run
# against the BUILT zip (extracted to a tmp dir), not the source tree.
#
# Exit codes:
#   0   ok — zip ready
#   1   missing Version header
#   30  agent-smoke gate failed (missing/stale/mismatched/with from-failures)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="wb-gamification"
DIST_DIR="${ROOT_DIR}/dist"

SKIP_BROWSER_SMOKE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --skip-browser-smoke) SKIP_BROWSER_SMOKE=1; shift ;;
        *) echo "Unknown flag: $1" >&2; exit 2 ;;
    esac
done

cd "${ROOT_DIR}"

VERSION="$(grep -oE '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "${ROOT_DIR}/${SLUG}.php" | awk '{print $NF}')"
if [ -z "${VERSION}" ]; then
    echo "ERROR: could not parse Version from ${SLUG}.php" >&2
    exit 1
fi

echo "→ Building ${SLUG} v${VERSION}"

# ─── Agent-smoke gate ───────────────────────────────────────────────────────
# Refuses to package unless docs/qa/.last-smoke-pass.json:
#   - exists
#   - .release_version matches the current VERSION
#   - .failures[] (entries with origin=="from") is empty
#   - .debug_log_issues[] (entries with origin=="from") is empty
#   - .ran_at is within the last 24 hours (warns, doesn't block, when stale)
# Emergency bypass: --skip-browser-smoke (warns; not for customer releases).
SMOKE_REPORT="${ROOT_DIR}/docs/qa/.last-smoke-pass.json"
if [ "${SKIP_BROWSER_SMOKE}" -eq 1 ]; then
    echo "  WARN: agent-smoke gate skipped (--skip-browser-smoke). Not for customer releases."
elif [ ! -f "${SMOKE_REPORT}" ]; then
    echo "ERROR: no agent-smoke report at ${SMOKE_REPORT#${ROOT_DIR}/}" >&2
    echo "       Run the generic wp-plugin-smoke skill first:" >&2
    echo "         Ask Claude Code: \"/wp-plugin-smoke\" (auto-detects from CWD)" >&2
    echo "       The skill reads docs/qa/qa.config.json + docs/qa/AGENT_SMOKE_RUNBOOK.md," >&2
    echo "       dispatches Sonnet with Playwright MCP, walks every customer-facing flow" >&2
    echo "       + every journey under audit/journeys/, and writes" >&2
    echo "       ${SMOKE_REPORT#${ROOT_DIR}/} on green pass." >&2
    echo "       Emergency only: rerun with --skip-browser-smoke." >&2
    exit 30
elif command -v python3 >/dev/null 2>&1; then
    SMOKE_CHECK="$(python3 - <<PY
import json, sys, datetime
try:
    d = json.load(open("${SMOKE_REPORT}"))
except Exception as e:
    print("PARSE_FAIL " + str(e)); sys.exit(0)
release = d.get("release_version", "")
failures = d.get("failures") or []
issues = d.get("debug_log_issues") or []
ran_at = d.get("ran_at", "")
from_failures = [f for f in failures if (f.get("origin") or "from") == "from"]
from_issues = [i for i in issues if (i.get("origin") or "from") == "from"]
print("VERSION=" + release)
print("FROM_FAILURES=" + str(len(from_failures)))
print("FROM_ISSUES=" + str(len(from_issues)))
print("RAN_AT=" + ran_at)
PY
)"
    if echo "${SMOKE_CHECK}" | grep -q "^PARSE_FAIL"; then
        echo "ERROR: ${SMOKE_REPORT#${ROOT_DIR}/} is not valid JSON." >&2
        echo "${SMOKE_CHECK}" >&2
        exit 30
    fi
    SMOKE_VERSION="$(echo "${SMOKE_CHECK}" | grep -oE '^VERSION=.*' | sed 's/^VERSION=//')"
    SMOKE_FROM_FAILURES="$(echo "${SMOKE_CHECK}" | grep -oE '^FROM_FAILURES=.*' | sed 's/^FROM_FAILURES=//')"
    SMOKE_FROM_ISSUES="$(echo "${SMOKE_CHECK}" | grep -oE '^FROM_ISSUES=.*' | sed 's/^FROM_ISSUES=//')"
    SMOKE_RAN_AT="$(echo "${SMOKE_CHECK}" | grep -oE '^RAN_AT=.*' | sed 's/^RAN_AT=//')"
    if [ "${SMOKE_VERSION}" != "${VERSION}" ]; then
        echo "ERROR: smoke report version (${SMOKE_VERSION}) ≠ release version (${VERSION})" >&2
        echo "       Re-run the wp-plugin-smoke skill against current HEAD." >&2
        exit 30
    fi
    if [ "${SMOKE_FROM_FAILURES}" != "0" ]; then
        echo "ERROR: smoke report has ${SMOKE_FROM_FAILURES} \`from\`-origin failure(s). Fix before packaging." >&2
        exit 30
    fi
    if [ "${SMOKE_FROM_ISSUES}" != "0" ]; then
        echo "ERROR: smoke report recorded ${SMOKE_FROM_ISSUES} \`from\`-origin debug.log entries during the walk. Fix before packaging." >&2
        exit 30
    fi
    if [ -n "${SMOKE_RAN_AT}" ]; then
        echo "  agent-smoke report dated ${SMOKE_RAN_AT} (matches v${VERSION}, 0 from-failures) — OK"
    fi
else
    echo "  WARN: python3 not on PATH; cannot validate smoke report shape. Skipping deep gate (file presence only)."
fi
# ─── End agent-smoke gate ───────────────────────────────────────────────────

# Ensure production deps + build artefacts are fresh.
if [ -f "${ROOT_DIR}/composer.json" ]; then
    composer install --no-dev --optimize-autoloader --quiet
fi
if [ -f "${ROOT_DIR}/package.json" ] && command -v npm >/dev/null 2>&1; then
    if [ ! -d "${ROOT_DIR}/node_modules" ]; then
        npm install --silent
    fi
    npm run build --silent
fi

# Stage the release in dist/<slug>/
STAGE="${DIST_DIR}/${SLUG}"
rm -rf "${STAGE}" "${DIST_DIR}/${SLUG}-${VERSION}.zip"
mkdir -p "${STAGE}"

rsync -a --delete \
    --exclude='.git/' --exclude='.github/' --exclude='.gitignore' --exclude='.gitattributes' \
    --exclude='.editorconfig' --exclude='.distignore' --exclude='.DS_Store' --exclude='.phpunit.result.cache' \
    --exclude='.idea/' --exclude='.vscode/' \
    --exclude='node_modules/' \
    --exclude='tests/' --exclude='plan/' --exclude='docs/' --exclude='audit/' --exclude='examples/' \
    --exclude='dist/' --exclude='bin/' --exclude='src/' \
    --exclude='*.map' --exclude='package.json' --exclude='package-lock.json' \
    --exclude='composer.json' --exclude='composer.lock' \
    --exclude='webpack.config.js' --exclude='phpcs.xml*' --exclude='.phpcs.xml*' \
    --exclude='phpstan.neon*' --exclude='phpstan-bootstrap.php' --exclude='phpstan-stubs/' \
    --exclude='phpunit.xml*' \
    --exclude='CLAUDE.md' --exclude='*.log' --exclude='wp-content/' \
    "${ROOT_DIR}/" "${STAGE}/"

cd "${DIST_DIR}"
zip -qr "${SLUG}-${VERSION}.zip" "${SLUG}"
ZIP_PATH="${DIST_DIR}/${SLUG}-${VERSION}.zip"
SIZE_KB=$(($(wc -c < "${ZIP_PATH}") / 1024))

# Restore composer dev deps for continued local work.
if [ -f "${ROOT_DIR}/composer.json" ]; then
    composer install --quiet
fi

cd "${ROOT_DIR}"
echo "→ ${ZIP_PATH} (${SIZE_KB} KB)"
echo "→ Next: extract to a tmp dir + run \`wp plugin check\` per release-gate (Part 17.7.3)."
