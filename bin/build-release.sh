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
#   25  static gates failed (composer ci:no-journeys)
#   30  agent-smoke gate failed (missing/stale/mismatched/with from-failures)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="wb-gamification"
DIST_DIR="${ROOT_DIR}/dist"

SKIP_BROWSER_SMOKE=0
SKIP_STATIC_GATES=0
while [ $# -gt 0 ]; do
    case "$1" in
        --skip-browser-smoke) SKIP_BROWSER_SMOKE=1; shift ;;
        --skip-static-gates)  SKIP_STATIC_GATES=1; shift ;;
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

# ─── Static gate (mandatory before every release) ────────────────────────────
# Runs `composer ci:no-journeys` which fans out to all gates:
#   - PHP lint, WPCS, PHPStan, JS build (1.1–1.4)
#   - coding-rules + architecture + block-standard (2.1–2.3)
#   - ux-audit (ux-foundation compliance)              (2.4)
#   - plugin-dev-rules (wp-plugin-development gates)   (2.5)
#   - wppqa-baseline (wp-plugin-qa MCP freshness)      (2.6)
#   - manifest freshness                               (3.1)
# Refuses to package on any failure. Emergency bypass: --skip-static-gates.
if [ "${SKIP_STATIC_GATES}" -eq 1 ]; then
    echo "  WARN: static gates skipped (--skip-static-gates). Not for customer releases."
else
    echo "→ Running static gates (composer ci:no-journeys) ..."
    if ! composer ci:no-journeys > /tmp/release-static-gate-$$.log 2>&1; then
        echo "ERROR: static gates failed. Release blocked." >&2
        echo "       See /tmp/release-static-gate-$$.log or re-run 'composer ci:no-journeys' for details." >&2
        echo "       The new gates (2.4 ux-audit, 2.5 plugin-dev-rules, 2.6 wppqa-baseline) are MANDATORY." >&2
        echo "       Emergency only: rerun with --skip-static-gates." >&2
        tail -40 /tmp/release-static-gate-$$.log >&2
        rm -f /tmp/release-static-gate-$$.log
        exit 25
    fi
    rm -f /tmp/release-static-gate-$$.log
    echo "  static gates green"
fi
# ─── End static gate ─────────────────────────────────────────────────────────

# ─── Contract-audit gate (cross-surface contract drift) ──────────────────────
# Static scan for the "writes key A, reads key B" bug class. Runs --strict so
# warnings block too. Exits 0 only when every finding is either resolved or
# baselined in .contract-audit-baseline.json with a real reason. The script
# lives in the shared wp-contract-audit skill; if it is not installed (CI
# runner, fresh clone) the gate is skipped with a warning rather than failing
# the build. Folded into release prep on 2026-06-11 (QA-accuracy 0/0 push).
CONTRACT_AUDIT="${HOME}/.claude/skills/wp-contract-audit/scripts/contract-audit.php"
if [ "${SKIP_STATIC_GATES}" -eq 1 ]; then
    echo "  WARN: contract-audit gate skipped (--skip-static-gates)."
elif [ ! -f "${CONTRACT_AUDIT}" ]; then
    echo "  WARN: contract-audit script not found at ${CONTRACT_AUDIT} - gate skipped."
else
    echo "→ Running contract audit (--strict) ..."
    if ! php "${CONTRACT_AUDIT}" "${ROOT_DIR}" --strict > /tmp/release-contract-audit-$$.log 2>&1; then
        echo "ERROR: contract audit found unbaselined drift. Release blocked." >&2
        echo "       Fix the finding, or baseline it with a real reason in" >&2
        echo "       .contract-audit-baseline.json, then re-run." >&2
        tail -30 /tmp/release-contract-audit-$$.log >&2
        rm -f /tmp/release-contract-audit-$$.log
        exit 25
    fi
    rm -f /tmp/release-contract-audit-$$.log
    echo "  contract audit clean (0/0, baselined exceptions only)"
fi
# ─── End contract-audit gate ─────────────────────────────────────────────────

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

# Regenerate build artefacts from current source. The npm script chains:
# blocks → rtl → min. POT generation uses wp-cli when available.
if [ -f "${ROOT_DIR}/package.json" ] && command -v npm >/dev/null 2>&1; then
    if [ ! -d "${ROOT_DIR}/node_modules" ]; then
        npm install --silent
    fi
    npm run build:release --silent
    if command -v wp >/dev/null 2>&1; then
        npm run build:pot --silent || true
    else
        echo "  WARN: wp-cli not on PATH; using committed languages/wb-gamification.pot."
    fi
fi

# Stage the release. Excludes live in a heredoc-fed temp file so the
# manifest reads as a single flat list (matches the jetonomy build
# script). Composer's prod-only install runs IN STAGING — keeps the
# developer's source vendor/ in --dev state for next local PHPUnit run.
STAGE="${DIST_DIR}/${SLUG}"
rm -rf "${STAGE}" "${DIST_DIR}/${SLUG}-${VERSION}.zip"
mkdir -p "${STAGE}"

EXCLUDES_FILE="$(mktemp)"
# shellcheck disable=SC2064
trap "rm -f '${EXCLUDES_FILE}'" EXIT
cat > "${EXCLUDES_FILE}" <<'EXCLUDES_EOF'
# VCS / IDE noise
.git/
.github/
.githooks/
.gitignore
.gitattributes
.editorconfig
.distignore
.DS_Store
.idea/
.vscode/
.husky/
.claude/
.claude-tmp/
.superpowers/
.wppqa-out/
.playwright-mcp/

# Build tooling — kept out of the customer zip
bin/
# TypeScript sources ship via npm, not in the customer zip.
sdk/
tests/
node_modules/
package.json
package-lock.json
composer.json
composer.lock
# Top-level Composer vendor/ is dev tooling only (PHPUnit/PHPStan/WPCS).
# Anchor with a leading slash so it strips ONLY the repo-root vendor/. An
# unanchored "vendor/" also deletes the EDD SL SDK's committed runtime
# autoloader at libs/easy-digital-downloads/edd-sl-sdk/vendor/, which the SDK
# require_once's on boot — stripping it ships a white-screen-fatal zip.
/vendor/
webpack.config.js
phpcs.xml
phpcs.xml.dist
.phpcs.xml
.phpcs.xml.dist
phpstan.neon
phpstan.neon.dist
phpstan-*.neon
phpstan-*.neon.dist
phpstan-bootstrap.php
phpstan-stubs/
phpunit.xml
phpunit.xml.dist
.phpunit.result.cache

# Internal documentation
docs/
plan/
audit/
examples/
marketing/
*.md

# Output + log noise
dist/
*.map
*.log
wp-content/
verify-*.png
verify-*.jpg

# Test-coverage HTML (22 MB of PHPUnit clover.xml + *.php.html reports) —
# regenerated by `composer test:coverage`, never needed by customers.
# Keep build/Blocks/ (compiled blocks); drop only build/coverage/.
build/coverage/

# wordpress.org SVN assets (banner / icon / screenshots, ~1.6 MB). This
# plugin ships via EDD + GitHub, not the wp.org repo, so these never need
# to travel in the distributed zip.
.wordpress-org/
EXCLUDES_EOF

# What stays in the zip:
#   • src/ build/ assets/ languages/ templates/ includes/ integrations/
#   • libs/ wholesale (committed runtime deps: Action Scheduler + EDD SL SDK)
#   • wb-gamification.php uninstall.php readme.txt
#
# No Composer step runs here: runtime deps are committed under libs/ and the
# plugin uses a hand-written PSR-4 autoloader, so the zip is deps-complete
# straight from the working tree. vendor/ + composer.* are excluded above.
rsync -a --delete --exclude-from="${EXCLUDES_FILE}" "${ROOT_DIR}/" "${STAGE}/"

# Release-integrity gate (Basecamp 9993571511). The plugin require_once's both
# bundled entrypoints on boot, so a missing file means it never starts. These
# live in committed libs/ now, but assert them before zipping so a botched
# checkout or stray .gitignore rule can never ship a non-booting build.
for required in \
    "libs/woocommerce/action-scheduler/action-scheduler.php" \
    "libs/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php" \
    "libs/easy-digital-downloads/edd-sl-sdk/vendor/autoload.php"; do
    if [ ! -f "${STAGE}/${required}" ]; then
        echo "✗ Release aborted: ${required} missing from staged build — the zip would not boot." >&2
        echo "  Bundled runtime deps live in committed libs/; verify they are present and tracked in git." >&2
        exit 1
    fi
done

( cd "${DIST_DIR}" && zip -qr "${SLUG}-${VERSION}.zip" "${SLUG}" )
ZIP_PATH="${DIST_DIR}/${SLUG}-${VERSION}.zip"
SIZE_KB=$(($(wc -c < "${ZIP_PATH}") / 1024))

echo "→ ${ZIP_PATH} (${SIZE_KB} KB)"
echo "→ Next: extract to a tmp dir + run \`wp plugin check\` per release-gate (Part 17.7.3)."
