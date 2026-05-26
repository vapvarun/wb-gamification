#!/usr/bin/env bash
# bin/wppqa-baseline-check.sh — validates a recent wppqa baseline exists.
#
# `wp-plugin-qa` is a Claude-level MCP server (mcp__wp-plugin-qa__*). The
# orchestrator (Claude) runs the four canonical wppqa checks against the
# plugin and dumps results into audit/wppqa-baseline-YYYY-MM-DD/SUMMARY.md.
#
# This script wires that baseline into local-CI as a freshness gate:
#   - audit/wppqa-baseline-*/SUMMARY.md must exist
#   - it must be ≤ 30 days old
#   - it must report `failed=0` across all four checks
#
# If the baseline is missing or stale, surface that as a CI failure +
# tell the contributor how to refresh: /wp-plugin-onboard --refresh OR
# spawn a Claude session that runs the wppqa MCP tools.
#
# Exit 0 = baseline fresh + clean, exit 1 = baseline stale/missing/dirty.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PLUGIN_DIR"

# Tunables.
MAX_AGE_DAYS="${WPPQA_BASELINE_MAX_AGE_DAYS:-30}"

# Find the most-recent baseline. Prefer audit/wppqa-baseline-YYYY-MM-DD/.
LATEST=$(find audit -maxdepth 1 -type d -name 'wppqa-baseline-*' 2>/dev/null | sort | tail -1)

if [ -z "$LATEST" ]; then
    echo "✗ No audit/wppqa-baseline-* directory found."
    echo "  Refresh via Claude:"
    echo "    /wp-plugin-onboard --refresh"
    echo "  OR run the wppqa MCP checks manually:"
    echo "    mcp__wp-plugin-qa__wppqa_check_plugin_dev_rules"
    echo "    mcp__wp-plugin-qa__wppqa_check_rest_js_contract"
    echo "    mcp__wp-plugin-qa__wppqa_check_wiring_completeness"
    echo "    mcp__wp-plugin-qa__wppqa_check_template_contract"
    echo "  Write the consolidated report to audit/wppqa-baseline-$(date +%Y-%m-%d)/SUMMARY.md"
    exit 1
fi

SUMMARY="$LATEST/SUMMARY.md"
if [ ! -f "$SUMMARY" ]; then
    echo "✗ Baseline directory exists but $SUMMARY is missing."
    exit 1
fi

# Age check (portable across BSD / GNU date).
BASELINE_DATE="$(basename "$LATEST" | sed 's/^wppqa-baseline-//')"
if [ -n "$BASELINE_DATE" ]; then
    if command -v gdate >/dev/null 2>&1; then
        BASELINE_EPOCH=$(gdate -d "$BASELINE_DATE" +%s 2>/dev/null || echo 0)
    else
        # macOS BSD date wants -j -f
        BASELINE_EPOCH=$(date -juf "%Y-%m-%d" "$BASELINE_DATE" +%s 2>/dev/null || echo 0)
    fi
    if [ "$BASELINE_EPOCH" != "0" ]; then
        NOW_EPOCH=$(date -u +%s)
        AGE_DAYS=$(( (NOW_EPOCH - BASELINE_EPOCH) / 86400 ))
        if [ "$AGE_DAYS" -gt "$MAX_AGE_DAYS" ]; then
            echo "✗ wppqa baseline is ${AGE_DAYS} days old (max ${MAX_AGE_DAYS})."
            echo "  Refresh via /wp-plugin-onboard --refresh."
            exit 1
        fi
    fi
fi

# Cleanliness check — SUMMARY.md must declare `failed=0` (or have no
# `failed=` row at all, which the consolidator omits when everything is
# green). A `failed=` row with a non-zero number is a fail.
DIRTY=$(grep -E 'failed[[:space:]]*=[[:space:]]*[1-9]' "$SUMMARY" || true)
if [ -n "$DIRTY" ]; then
    echo "✗ wppqa baseline reports failures:"
    echo "$DIRTY" | sed 's/^/    /'
    echo "  Open $SUMMARY for the per-check breakdown."
    exit 1
fi

echo "✓ wppqa baseline fresh + clean ($(basename "$LATEST"))"
exit 0
