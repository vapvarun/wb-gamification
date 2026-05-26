#!/usr/bin/env bash
# bin/plugin-dev-rules-check.sh — enforces wp-plugin-development gates.
#
# Complements bin/coding-rules-check.sh (which carries plugin-specific
# domain rules) with portfolio-wide rules from the wp-plugin-development
# skill: no jQuery on frontend, no admin-ajax on frontend, no $.ajax /
# XMLHttpRequest in customer-facing JS, every block depends on
# wb-gam-tokens, ProfileIntegration boots via bp_loaded (not init).
#
# Each rule emits a `violation` line on failure. Exit 0 = clean,
# exit 1 = at least one violation.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

VIOLATIONS_FILE="$(mktemp)"
trap 'rm -f "$VIOLATIONS_FILE"' EXIT

violation() {
    echo "✗ $*"
    echo "v" >> "$VIOLATIONS_FILE"
}
ok() { echo "✓ $*"; }
violations_count() { wc -l < "$VIOLATIONS_FILE" | tr -d ' '; }

echo "=== WB Gamification plugin-dev-rules check ==="
echo "Plugin: $PLUGIN_DIR"
echo ""

# ----------------------------------------------------------------------------
# Rule A — No jQuery on the customer-facing frontend.
#
# Frontend stack is Interactivity API + ES modules. Frontend handles must not
# declare `jquery` as a dep. Admin handles are exempt (admin still uses
# legacy jQuery in some screens). The audit reads wp-gamification.php +
# every src/**/*.php for wp_enqueue_script() that includes 'jquery' AND
# emits to a non-admin screen.
# ----------------------------------------------------------------------------
check_no_jquery_frontend() {
    local hits
    hits=$(grep -rEn "wp_enqueue_script\([^)]*'jquery'" "$PLUGIN_DIR/src/" "$PLUGIN_DIR/wb-gamification.php" 2>/dev/null \
            | grep -vE 'Admin|admin_enqueue_scripts|/Admin/|/tests/' \
            || true)
    if [ -n "$hits" ]; then
        violation "Rule A — jQuery enqueued on a non-admin handle:"
        echo "$hits" | sed 's/^/    /'
        echo "    Fix: customer-facing JS uses Interactivity API + ES modules."
        echo "         Drop the 'jquery' dep. Admin-only screens may keep it."
    else
        ok "Rule A — no jQuery on the customer-facing frontend"
    fi
}

# ----------------------------------------------------------------------------
# Rule B — No admin-ajax on the customer-facing frontend.
#
# Customer-facing state changes go through /wb-gamification/v1/* REST.
# admin-ajax.php is allowed only inside src/Admin/* for legacy screens that
# haven't migrated yet. Any other reference is drift.
# ----------------------------------------------------------------------------
check_no_admin_ajax_frontend() {
    local hits
    hits=$(grep -rEn "admin-ajax\.php\|wp_ajax_" "$PLUGIN_DIR/src/" "$PLUGIN_DIR/assets/js/" "$PLUGIN_DIR/assets/interactivity/" 2>/dev/null \
            | grep -vE '/Admin/|admin-rest|/tests/|\.min\.js' \
            || true)
    if [ -n "$hits" ]; then
        violation "Rule B — admin-ajax / wp_ajax_ on a customer-facing surface:"
        echo "$hits" | sed 's/^/    /'
        echo "    Fix: use a REST endpoint under /wb-gamification/v1/* and apiFetch in JS."
    else
        ok "Rule B — no admin-ajax / wp_ajax_ on customer-facing surfaces"
    fi
}

# ----------------------------------------------------------------------------
# Rule C — No raw $.ajax / XMLHttpRequest in customer-facing JS.
#
# All HTTP from customer-facing JS goes through @wordpress/api-fetch or the
# Interactivity API's actions store. Raw jQuery $.ajax or XMLHttpRequest is
# a portfolio anti-pattern; the audit catches it in src/Blocks/* and
# assets/interactivity/* (these are customer-surfaces). Admin JS may still
# have legacy $.ajax until migrated.
# ----------------------------------------------------------------------------
check_no_raw_xhr_frontend() {
    local hits
    hits=$(grep -rEn "\\\$\.ajax\(|XMLHttpRequest\(" \
                "$PLUGIN_DIR/src/Blocks/" \
                "$PLUGIN_DIR/assets/interactivity/" 2>/dev/null \
            | grep -vE '\.min\.js|/build/|/dist/' \
            || true)
    if [ -n "$hits" ]; then
        violation "Rule C — raw \$.ajax / XMLHttpRequest in customer-facing JS:"
        echo "$hits" | sed 's/^/    /'
        echo "    Fix: use wp.apiFetch or the Interactivity actions store."
    else
        ok "Rule C — no raw \$.ajax / XMLHttpRequest in customer-facing JS"
    fi
}

# ----------------------------------------------------------------------------
# Rule D — Design-tokens handle is registered and depended-on.
#
# Per-block stylesheets read var(--wb-gam-*) values, which only resolve when
# the `wb-gam-tokens` handle (src/shared/design-tokens.css) is enqueued. We
# verify (a) the handle is registered in wb-gamification.php, and (b) at
# least one per-block / frontend style declares it as a dep so the tokens
# load before any block CSS.
# ----------------------------------------------------------------------------
check_blocks_token_dep() {
    local entry="$PLUGIN_DIR/wb-gamification.php"
    if [ ! -f "$entry" ]; then
        violation "Rule D — wb-gamification.php missing"
        return
    fi
    # Match the literal 'wb-gam-tokens' OR "wb-gam-tokens" anywhere in the
    # entry file — wp_register_style() commonly spans multiple lines, with
    # the handle on its own line. A single-line regex anchored to
    # wp_register_style misses the multi-line form.
    local registered
    registered=$(grep -E "['\"]wb-gam-tokens['\"]" "$entry" 2>/dev/null || true)
    if [ -z "$registered" ]; then
        violation "Rule D — 'wb-gam-tokens' style handle not registered in wb-gamification.php"
        echo "    Fix: wp_register_style( 'wb-gam-tokens', WB_GAM_URL . 'src/shared/design-tokens.css', ... )."
        return
    fi
    local depended
    depended=$(grep -rE "array\(\s*['\"]wb-gam-tokens['\"]" "$PLUGIN_DIR/wb-gamification.php" "$PLUGIN_DIR/src/" 2>/dev/null || true)
    if [ -z "$depended" ]; then
        violation "Rule D — 'wb-gam-tokens' is registered but no style declares it as a dep"
        echo "    Fix: at least one front-facing wp_register_style() must list 'wb-gam-tokens' in its deps array."
        return
    fi
    ok "Rule D — wb-gam-tokens handle registered + depended on by downstream styles"
}

# ----------------------------------------------------------------------------
# Rule E — Blocks ship a per-block style.css when they have runtime CSS.
#
# Phase F migration retired the monolithic assets/css/frontend.css for
# block-styles. New blocks must own their CSS under src/Blocks/<slug>/style.css.
# Block-shaped folders missing a style.css get flagged so the regression
# doesn't sneak back in.
# ----------------------------------------------------------------------------
check_blocks_own_style_css() {
    # Blocks that intentionally share an admin/host stylesheet instead of
    # shipping their own. `hub` is the admin Hub shell (loads assets/css/hub.css).
    # `give-kudos` is the kudos modal partial that piggybacks on hub.css since
    # it only renders inside the hub. Both are explicitly documented in
    # CLAUDE.md and not in violation.
    local allowed_blocks_no_style=(
        "hub"
        "give-kudos"
    )

    local missing=""
    while IFS= read -r block_json; do
        local slug
        slug="$(basename "$(dirname "$block_json")")"
        local style_css
        style_css="$(dirname "$block_json")/style.css"
        # Allowlist
        local skip=0
        for allow in "${allowed_blocks_no_style[@]}"; do
            if [ "$slug" = "$allow" ]; then skip=1; break; fi
        done
        if [ "$skip" = "1" ]; then continue; fi
        # block.json explicitly opts out via "style": false
        if grep -q '"style"\s*:\s*false' "$block_json" 2>/dev/null; then
            continue
        fi
        if [ ! -f "$style_css" ]; then
            missing="${missing}    Block '$slug' has no src/Blocks/$slug/style.css\n"
        fi
    done < <(find "$PLUGIN_DIR/src/Blocks" -name 'block.json' -not -path '*/build/*' 2>/dev/null)

    if [ -n "$missing" ]; then
        violation "Rule E — blocks missing their per-block style.css:"
        printf "%b" "$missing"
        echo "    Fix: each block ships its own stylesheet under src/Blocks/<slug>/style.css,"
        echo "         OR add the slug to allowed_blocks_no_style[] in this script if it"
        echo "         intentionally shares a host stylesheet (document why in CLAUDE.md)."
    else
        ok "Rule E — every block has its own style.css (or is explicitly allowlisted)"
    fi
}

# ----------------------------------------------------------------------------
# Rule F — ProfileIntegration boots on bp_loaded (not init).
#
# BuddyPress integrations that touch BP globals (profile templates, member
# directory hooks) MUST hook on bp_loaded, not init — at init the BP API
# isn't fully ready and integration silently no-ops. Found via grep against
# the BuddyPress namespace; any add_action('init', ...) in BuddyPress/* is
# suspicious.
# ----------------------------------------------------------------------------
check_bp_integration_boot_order() {
    local hits
    hits=$(grep -rEn "add_action\(\s*['\"]init['\"]" "$PLUGIN_DIR/src/BuddyPress/" 2>/dev/null || true)
    if [ -n "$hits" ]; then
        violation "Rule F — BuddyPress integration hooks on 'init' (should be 'bp_loaded'):"
        echo "$hits" | sed 's/^/    /'
        echo "    Fix: BP globals aren't ready at init. Use 'bp_loaded' (or 'bp_init') for member/profile/group hooks."
    else
        ok "Rule F — BuddyPress integrations boot on bp_loaded"
    fi
}

# ----------------------------------------------------------------------------

check_no_jquery_frontend
check_no_admin_ajax_frontend
check_no_raw_xhr_frontend
check_blocks_token_dep
check_blocks_own_style_css
check_bp_integration_boot_order

echo ""
COUNT=$(violations_count)
if [ "$COUNT" -eq 0 ]; then
    echo "All plugin-dev rules pass."
    exit 0
else
    echo "$COUNT violation(s). See ~/.claude/skills/wp-plugin-development/ + this script's header."
    exit 1
fi
