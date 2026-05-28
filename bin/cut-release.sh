#!/usr/bin/env bash
#
# bin/cut-release.sh — release-prep orchestrator.
#
# One command that brings the codebase to a tag-ready state. Replaces the
# ~10-spot manual edit ritual that used to drift between version constants,
# changelogs, and inventory artefacts.
#
# Usage:
#     bash bin/cut-release.sh 1.6.0          Prepare a 1.6.0 release
#     bash bin/cut-release.sh --check        Dry-run; regenerate artefacts
#                                            and exit non-zero if the result
#                                            differs from what's on disk.
#                                            Use this to prove the generators
#                                            stay idempotent against a clean
#                                            release state.
#
# What it does (in order):
#   1. Parse NEW_VERSION (or --check). Validate semver shape.
#   2. Bump version in (skip on --check):
#        - wb-gamification.php (Version: header AND WB_GAM_VERSION constant)
#        - package.json
#        - readme.txt (Stable tag)
#        - audit/manifest.json (.plugin.version)
#        - audit/manifest.summary.json (.plugin.version)
#   3. Run bin/build-readme.php       — inline feature counts from manifest
#   4. Run bin/build-docs-config.php  — regen docs_config.json from disk
#   5. On a real cut (not --check): insert empty changelog stubs:
#        - readme.txt   "= NEW_VERSION - <Month Year> ="
#        - CHANGELOG.md "## [NEW_VERSION] - <YYYY-MM-DD>"
#   6. Print the diff summary + next steps.
#
# Anti-drift contract:
#   `bin/cut-release.sh --check` MUST exit 0 against a clean release state.
#   That proves the generators reconstruct what humans wrote (no false-drift)
#   AND that there's no pending hand-edit waiting to be picked up.
#
# Exit codes:
#   0   ok
#   1   bad arg / bad semver / target version <= current
#   2   generator failed
#   3   --check mode: regenerated artefacts differ from disk (drift present)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

MAIN_FILE="wb-gamification.php"
SLUG="wb-gamification"

# ─── Args ───────────────────────────────────────────────────────────────────
CHECK_MODE=0
NEW_VERSION=""
case "${1:-}" in
    "")          echo "Usage: bash bin/cut-release.sh <NEW_VERSION>   |   --check" >&2; exit 1 ;;
    --check)     CHECK_MODE=1 ;;
    -h|--help)   sed -n '3,40p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 0 ;;
    *)           NEW_VERSION="$1" ;;
esac

CURRENT_VERSION="$(grep -oE '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "${MAIN_FILE}" | awk '{print $NF}')"
if [ -z "${CURRENT_VERSION}" ]; then
    echo "ERROR: could not read Version: from ${MAIN_FILE}" >&2
    exit 1
fi

if [ "${CHECK_MODE}" -eq 1 ]; then
    NEW_VERSION="${CURRENT_VERSION}"
    echo "→ cut-release --check (current: ${CURRENT_VERSION})"
else
    if ! [[ "${NEW_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "ERROR: NEW_VERSION '${NEW_VERSION}' is not semver (X.Y.Z)" >&2
        exit 1
    fi
    # Refuse to go backwards or sideways.
    LOWER_OF_TWO="$(printf '%s\n%s\n' "${CURRENT_VERSION}" "${NEW_VERSION}" | sort -V | head -1)"
    if [ "${NEW_VERSION}" = "${CURRENT_VERSION}" ] || [ "${LOWER_OF_TWO}" != "${CURRENT_VERSION}" ]; then
        echo "ERROR: NEW_VERSION (${NEW_VERSION}) must be greater than current (${CURRENT_VERSION})" >&2
        exit 1
    fi
    echo "→ cut-release ${CURRENT_VERSION} → ${NEW_VERSION}"
fi

# ─── 1. Version bump (skip on --check) ──────────────────────────────────────
if [ "${CHECK_MODE}" -eq 0 ]; then
    # wb-gamification.php — header + constant
    sed -i.bak \
        -e "s/^ \* Version:     ${CURRENT_VERSION}$/ * Version:     ${NEW_VERSION}/" \
        -e "s/define( 'WB_GAM_VERSION', '${CURRENT_VERSION}' );/define( 'WB_GAM_VERSION', '${NEW_VERSION}' );/" \
        "${MAIN_FILE}"
    rm -f "${MAIN_FILE}.bak"

    # package.json
    sed -i.bak "s/\"version\": \"${CURRENT_VERSION}\"/\"version\": \"${NEW_VERSION}\"/" package.json
    rm -f package.json.bak

    # readme.txt — Stable tag
    sed -i.bak "s/^Stable tag: ${CURRENT_VERSION}$/Stable tag: ${NEW_VERSION}/" readme.txt
    rm -f readme.txt.bak

    # audit manifests — jq in-place
    if ! command -v jq >/dev/null 2>&1; then
        echo "ERROR: jq required for manifest bump" >&2
        exit 1
    fi
    jq --arg v "${NEW_VERSION}" '.plugin.version = $v' audit/manifest.json         > audit/manifest.json.tmp         && mv audit/manifest.json.tmp         audit/manifest.json
    jq --arg v "${NEW_VERSION}" '.plugin.version = $v' audit/manifest.summary.json > audit/manifest.summary.json.tmp && mv audit/manifest.summary.json.tmp audit/manifest.summary.json
    echo "  ✓  Version bumped (5 spots)"
fi

# ─── 2. Inventory-driven regeneration ───────────────────────────────────────
# Generators read audit/manifest.json + on-disk artefacts, write canonical
# output. They are the single source of truth for the surfaces they own.

if [ "${CHECK_MODE}" -eq 1 ]; then
    # Snapshot the pre-regen state so we can diff after the generators run.
    PRE_README_HASH="$(shasum -a 256 readme.txt | awk '{print $1}')"
    PRE_CONFIG_HASH="$(shasum -a 256 docs/website/docs_config.json | awk '{print $1}')"
fi

if [ -f bin/build-readme.php ]; then
    php bin/build-readme.php || { echo "ERROR: bin/build-readme.php failed" >&2; exit 2; }
    echo "  ✓  readme.txt feature counts regenerated"
else
    echo "  WARN: bin/build-readme.php not present yet — skipping"
fi

if [ -f bin/build-docs-config.php ]; then
    php bin/build-docs-config.php || { echo "ERROR: bin/build-docs-config.php failed" >&2; exit 2; }
    echo "  ✓  docs/website/docs_config.json regenerated"
else
    echo "  WARN: bin/build-docs-config.php not present yet — skipping"
fi

# OpenAPI spec — requires WP-CLI because it walks the REST_Server's
# registered routes. Skip the step (with a clear note) when wp isn't on
# PATH so this script still runs in CI containers that may not have it.
if command -v wp >/dev/null 2>&1; then
    if [ "${CHECK_MODE}" -eq 1 ]; then
        if ! wp wb-gamification openapi export --check >/dev/null 2>&1; then
            echo "  ✗  audit/openapi.json drift detected (controllers changed)" >&2
            wp wb-gamification openapi export --check 2>&1 | sed 's/^/      /' >&2
            exit 3
        fi
        echo "  ✓  audit/openapi.json in sync with controllers"
    else
        if wp wb-gamification openapi export 2>&1 | sed 's/^/      /'; then
            echo "  ✓  audit/openapi.json refreshed from controllers"
        else
            echo "ERROR: wp wb-gamification openapi export failed" >&2
            exit 2
        fi
    fi
else
    echo "  WARN: wp-cli not on PATH — skipping audit/openapi.json refresh."
    echo "         Run 'wp wb-gamification openapi export' from a WP-CLI host before tagging."
fi

# ─── 3. --check mode: drift detection ──────────────────────────────────────
if [ "${CHECK_MODE}" -eq 1 ]; then
    POST_README_HASH="$(shasum -a 256 readme.txt | awk '{print $1}')"
    POST_CONFIG_HASH="$(shasum -a 256 docs/website/docs_config.json | awk '{print $1}')"

    DRIFT=0
    if [ "${PRE_README_HASH}" != "${POST_README_HASH}" ]; then
        echo "  ✗  drift: readme.txt feature counts differ from manifest" >&2
        DRIFT=1
    fi
    if [ "${PRE_CONFIG_HASH}" != "${POST_CONFIG_HASH}" ]; then
        echo "  ✗  drift: docs/website/docs_config.json differs from on-disk layout" >&2
        DRIFT=1
    fi
    if [ "${DRIFT}" -eq 1 ]; then
        echo "" >&2
        echo "Run 'bash bin/cut-release.sh ${CURRENT_VERSION}' style without --check to update," >&2
        echo "or revert the offending hand-edits. See bin/build-*.php for the contract." >&2
        exit 3
    fi
    echo "→ cut-release --check: clean"
    exit 0
fi

# ─── 4. Changelog stubs (real cut only) ─────────────────────────────────────
MONTH_YEAR="$(date '+%B %Y')"
ISO_DATE="$(date '+%Y-%m-%d')"

# readme.txt — insert "= NEW_VERSION - Month Year =" right after the
# "== Changelog ==" header so the latest version is always top of section.
python3 - "${NEW_VERSION}" "${MONTH_YEAR}" <<'PY'
import sys, pathlib, re
new_v, month_year = sys.argv[1], sys.argv[2]
path = pathlib.Path("readme.txt")
text = path.read_text()
stub = f"\n= {new_v} - {month_year} =\n\nRelease summary goes here. Replace before tagging.\n\n* New      - Describe new capability.\n* Improve  - Describe improvement.\n* Fix      - Describe fix.\n"
if f"= {new_v} -" in text:
    print(f"  ·  readme.txt already has = {new_v} - … = block; skipped")
else:
    text = re.sub(r"(== Changelog ==\n)", r"\1" + stub + "\n", text, count=1)
    path.write_text(text)
    print(f"  ✓  readme.txt: added stub for = {new_v} - {month_year} =")
PY

# CHANGELOG.md — insert after the existing [Unreleased] line.
python3 - "${NEW_VERSION}" "${ISO_DATE}" <<'PY'
import sys, pathlib, re
new_v, iso_date = sys.argv[1], sys.argv[2]
path = pathlib.Path("CHANGELOG.md")
if not path.exists():
    print("  ·  CHANGELOG.md missing; skipped")
    raise SystemExit(0)
text = path.read_text()
if f"## [{new_v}]" in text:
    print(f"  ·  CHANGELOG.md already has [{new_v}] section; skipped")
else:
    stub = f"## [{new_v}] - {iso_date}\n\nRelease summary goes here. Replace before tagging.\n\n### Added\n\n- TODO\n\n### Changed\n\n- TODO\n\n### Fixed\n\n- TODO\n\n"
    text = re.sub(r"(## \[Unreleased\]\n)", r"\1\n" + stub, text, count=1)
    path.write_text(text)
    print(f"  ✓  CHANGELOG.md: added [{new_v}] section dated {iso_date}")
PY

# ─── 5. Done ───────────────────────────────────────────────────────────────
echo ""
echo "Release prep done. Next:"
echo "  1. Edit readme.txt and CHANGELOG.md — replace the TODOs with the real release notes."
echo "  2. Review the diff with 'git diff' and run 'bash bin/local-ci.sh --quick'."
echo "  3. Commit with a 'release: v${NEW_VERSION} - <summary>' message."
echo "  4. Tag when ready: git tag -a v${NEW_VERSION} -m \"…\""
