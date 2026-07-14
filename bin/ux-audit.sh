#!/usr/bin/env bash
# UX Audit — scans a Wbcom plugin against ux-foundation rules.
# Usage: ux-audit.sh [plugin_dir]   # defaults to $PWD
#        PREFIX=jt ux-audit.sh ...  # override the plugin CSS prefix

set -uo pipefail

PLUGIN_DIR="${1:-$PWD}"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
PREFIX="${PREFIX:-$(echo "$PLUGIN_NAME" | cut -c1-3)}"

# Skip vendored / built / tests / scratch / docs / examples / marketing.
# `audit/` is a generated inventory dump — not customer-facing code.
# `examples/` ships sample manifests showing common patterns — those legitimately
# contain inline tags and dashicons. `docs/` is markdown + reference templates.
# `marketing/` holds OG cards / one-off HTML demos. `.superpowers/` is local
# brainstorm/scratch dir, never shipped.
FIND_EXCLUDES=(
    -not -path "*/vendor/*"
    -not -path "*/node_modules/*"
    -not -path "*/dist/*"
    -not -path "*/build/*"
    -not -path "*/tests/*"
    -not -path "*/.git/*"
    -not -path "*/.superpowers/*"
    -not -path "*/audit/*"
    -not -path "*/examples/*"
    -not -path "*/docs/*"
    -not -path "*/marketing/*"
)

violation() {
    local severity="$1" rule="$2" file="$3" line="$4" snippet="$5"
    printf '| %s | %s | %s:%s | %s |\n' "$severity" "$rule" "${file#$PLUGIN_DIR/}" "$line" "$snippet"
}

echo "# UX Audit — $PLUGIN_NAME"
echo
echo "_Generated $(date -u +%Y-%m-%dT%H:%M:%SZ)_ — prefix: \`${PREFIX}\`"
echo
echo "| Severity | Rule | Location | Snippet |"
echo "|---|---|---|---|"

BLOCK_COUNT=0
ADVISORY_COUNT=0

# Helper: filter out grep hits that are inside PHP comments or string literals
# (lines starting with //, *, #, or where <style>/<script> appears inside quoted text).
# F2 was over-reporting because it matched comments + XSS-detection code that
# literally talks about <script> as data, not emits it.
real_inline_tag() {
    # stdin: grep -nE output. stdout: same, minus comment/string-literal lines.
    # Filters: leading // * # comments; quoted string literals containing <style/<script;
    # XSS-detection / sanitization code that mentions <script> as data.
    grep -vE '^[^:]+:[0-9]+:\s*(//|\*|#)' \
        | grep -vE "^[^:]+:[0-9]+:.*['\"][^'\"]*<(style|script)[^'\"]*['\"]" \
        | grep -vE '^[^:]+:[0-9]+:.*(strip|reject|filter|escape|kses|sanitize|XSS|xss).*<(style|script)' \
        || true
}

# ── F1: inline <style> in PHP (excluding email templates) ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F1 inline-style" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -not -path '*/email*' -not -path '*/emails*' -print0 2>/dev/null \
         | xargs -0 grep -nE '<style[ >]' 2>/dev/null \
         | real_inline_tag || true)

# ── F2: inline <script> in PHP (excludes email/OAuth + comments + string literals) ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F2 inline-script" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -not -path '*/email*' -not -path '*/emails*' -not -path '*/oauth/*' -print0 2>/dev/null \
         | xargs -0 grep -nE '<script[ >]' 2>/dev/null \
         | grep -vE 'application/(ld\+json|json)' \
         | real_inline_tag || true)

# ── F2b: inline onclick attribute ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F2 inline-onclick" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" \( -name '*.php' -o -name '*.html' \) "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'onclick=' 2>/dev/null || true)

# ── F8: native alert/confirm/prompt in JS (excludes .min.js + eslint-disable lines) ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F8 native-alert-confirm" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.js' -not -name '*.min.js' "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'window\.(alert|confirm|prompt)\s*\(|^\s*(alert|confirm|prompt)\s*\(' 2>/dev/null \
         | grep -v 'eslint-disable' \
         | real_inline_tag || true)

# ── F10: display:none on theme sidebar/widgets ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "F10 hidden-theme-html" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
         | xargs -0 grep -nE '(\.sidebar|#secondary|\.widget-area|\.site-sidebar)[^{]*\{[^}]*display:\s*none' 2>/dev/null || true)

# ── Advisory: raw -1 in number inputs ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "advisory" "Rule 7 raw-minus-one" "$file" "$line" "\`$snippet\`"
    ADVISORY_COUNT=$((ADVISORY_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'type=["'"'"']number["'"'"'][^>]*value=["'"'"']-1' 2>/dev/null || true)

# ── Advisory: Dashicons in new code ──
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "advisory" "Rule 5 use-lucide" "$file" "$line" "\`$snippet\`"
    ADVISORY_COUNT=$((ADVISORY_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.php' "${FIND_EXCLUDES[@]}" -print0 2>/dev/null \
         | xargs -0 grep -nE 'dashicons-[a-z-]+' 2>/dev/null \
         | head -10 || true)

# ── Advisory: outline:none with NO visible focus indicator to replace it ──
#
# WCAG does not require :focus-visible. It requires a VISIBLE FOCUS INDICATOR. Removing the
# browser outline and replacing it with a box-shadow ring or a border change is a correct,
# common, and accessible pattern.
#
# The old rule only accepted the literal string "focus-visible", and only looked 5 lines
# FORWARD. So it flagged every one of these as a violation:
#
#     .wb-gam-give-kudos__input:focus {
#         outline: none;                                   <- flagged
#         border-color: var( --wb-gam-color-accent );      <- the replacement it could not see
#         box-shadow: 0 0 0 3px var( --wb-gam-accent-ring );
#     }
#
# That is correct code. A journey run reported ~23 findings of this shape as a11y failures, and
# a gate that cries wolf is one people stop reading -- which is far more dangerous than the
# handful of real problems it might one day catch.
#
# So: read the whole DECLARATION BLOCK the outline sits in, and only complain when nothing in it
# could possibly render focus.
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue

    # The enclosing block: back to the nearest '{', forward to the nearest '}'.
    open=$(awk -v n="$line" 'NR<=n && /\{/ {l=NR} END {print l+0}' "$file")
    close=$(awk -v n="$line" 'NR>=n && /\}/ {print NR; exit}' "$file")
    [ "${open:-0}" -lt 1 ] && open=$((line > 3 ? line - 3 : 1))
    [ -z "${close:-}" ] && close=$((line + 6))

    block="$(sed -n "${open},${close}p" "$file" 2>/dev/null)"

    # Anything here means focus is still visible: a replacement ring, a border change, an
    # outline that is not "none", or the :focus-visible guard itself.
    # NOTE the character class: [^n0[:space:]]. Written as [^n0] the \s* matches ZERO spaces and
    # the class then matches the SPACE in "outline: none" -- so the rule counted "outline: none"
    # itself as its own replacement and went completely blind. Caught by mutation-testing it
    # against a real violation, which is the only reason anyone would ever notice.
    if echo "$block" | grep -qE 'focus-visible|box-shadow|border-color|border:|outline:[[:space:]]*[^n0[:space:]]'; then
        continue
    fi

    # BLOCK, not advisory.
    #
    # Removing the focus ring and putting nothing in its place makes the plugin unusable by keyboard:
    # the member cannot see where they are. That is a WCAG 2.4.7 failure, and it is not a matter of
    # taste to be tidied "in the next PR".
    #
    # It was advisory, so it could not fail a build -- QA planted an outline:none with no replacement
    # in frontend.css and the gate reported GREEN. A check that reports and cannot fail is a comment
    # with a table around it.
    #
    # It is safe to give it teeth today because the rule is PRECISE: it reads the whole declaration
    # block and accepts any real replacement (a box-shadow ring, a border change, a non-none outline,
    # or the :focus-visible guard), so the codebase currently reports ZERO of these. It fires only on
    # a genuine regression, which is the only condition under which a gate deserves to block.
    snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
    violation "block" "Rule 14 outline-none-no-replacement" "$file" "$line" "\`$snippet\`"
    BLOCK_COUNT=$((BLOCK_COUNT+1))
done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
         | xargs -0 grep -nE 'outline:\s*(none|0)' 2>/dev/null || true)

# ── Advisory: > 3 distinct breakpoints ──
BREAKPOINTS=$(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
              | xargs -0 grep -hE '@media[^{]*\(\s*(min|max)-width:\s*[0-9]+px' 2>/dev/null \
              | grep -oE '[0-9]+px' | sort -u | wc -l | tr -d ' ')
if [ "${BREAKPOINTS:-0}" -gt 3 ]; then
    violation "advisory" "Rule responsive #1 breakpoints>3" "(global)" "0" "\`$BREAKPOINTS distinct breakpoint values — should be ≤3\`"
    ADVISORY_COUNT=$((ADVISORY_COUNT+1))
fi

# ── Advisory: per-component dark-mode rules that style COMPONENTS with raw colors ──
# Three patterns to distinguish:
#   1. Token-define block:        --jt-bg: #1e1e1e;          ← CORRECT (token override)
#   2. Token-aware override:      color: var(--jt-text);     ← CORRECT (defensive re-apply)
#   3. Component drift:           background: #1e1e1e;       ← THIS is the bug we flag
# Only #3 is drift. We detect it by looking for a property declaration (line that
# does NOT start with `--`) with a raw color value, inside a .{prefix}-dark rule body.
while IFS=: read -r file line _; do
    [ -z "$file" ] && continue
    # Check rule body (next 8 lines). Look for a NON-token property with raw color.
    body="$(sed -n "${line},$((line+8))p" "$file" 2>/dev/null)"
    if echo "$body" | grep -qE '^[[:space:]]+[a-z][a-z-]*:[[:space:]]*(#[0-9a-fA-F]{3,6}|rgba?\(|hsla?\()'; then
        snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
        violation "advisory" "Rule dark-mode-raw-color" "$file" "$line" "\`$snippet\`"
        ADVISORY_COUNT=$((ADVISORY_COUNT+1))
    fi
done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -print0 2>/dev/null \
         | xargs -0 grep -nE '\.(jt|jet|wbg|listora|lrn|mvs|bn|bx|bp)-dark[[:space:]]+\.[a-z]+-[a-z]' 2>/dev/null \
         | head -20 || true)

# ── Advisory: raw margin-left/right (RTL-fragile) ──
RTL_RAW=$(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -not -name '*-rtl.css' -print0 2>/dev/null \
          | xargs -0 grep -cE '(margin|padding)-(left|right):' 2>/dev/null \
          | grep -v ':0$' | wc -l | tr -d ' ')
if [ "${RTL_RAW:-0}" -gt 0 ]; then
    # Sample first 5
    while IFS=: read -r file line _; do
        [ -z "$file" ] && continue
        snippet="$(sed -n "${line}p" "$file" 2>/dev/null | head -c 80 | tr '\n' ' ' | sed 's/|/\\|/g')"
        violation "advisory" "RTL raw-margin-left-right" "$file" "$line" "\`$snippet\`"
        ADVISORY_COUNT=$((ADVISORY_COUNT+1))
    done < <(find "$PLUGIN_DIR" -name '*.css' "${FIND_EXCLUDES[@]}" -not -name '*.min.css' -not -name '*-rtl.css' -print0 2>/dev/null \
             | xargs -0 grep -nE '(margin|padding)-(left|right):' 2>/dev/null \
             | grep -v ':[[:space:]]*0;' \
             | head -10 || true)
fi

echo
echo "---"
echo "**Block-severity violations: $BLOCK_COUNT** | **Advisory: $ADVISORY_COUNT**"
echo
if [ "$BLOCK_COUNT" -eq 0 ]; then
    echo "✅ No block-severity violations. Advisory issues should be cleaned in next PR."
    exit 0
else
    echo "❌ Fix every \`block\` row before merging. \`advisory\` rows can ship with next PR."
    exit 1
fi
