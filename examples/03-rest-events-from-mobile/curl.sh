#!/usr/bin/env bash
# WB Gamification — REST events ingestion via curl
#
# Run any of these against a real site to fire a gamification event.

SITE="${SITE:-http://wb-gamification.local}"

# ───────────────────────────────────────────────────────────────────────
# 1. Cookie + wp_rest nonce (in-browser fetch already handles this; the
#    curl version requires a logged-in cookie jar)
# ───────────────────────────────────────────────────────────────────────

# Step 1: log in (or use ?autologin= mu-plugin in dev)
curl -s -c /tmp/wbg-cookies.txt -L -o /dev/null \
  "$SITE/?autologin=admin"

# Step 2: get a wp_rest nonce
NONCE=$(curl -s -b /tmp/wbg-cookies.txt \
  "$SITE/wp-admin/admin-ajax.php?action=rest-nonce")

# Step 3: post the event
curl -s -X POST \
  -b /tmp/wbg-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "action_id": "wp_post_receives_comment",
    "metadata":  { "source": "curl_example_cookie_auth" }
  }' \
  "$SITE/wp-json/wb-gamification/v1/events"
echo

# ───────────────────────────────────────────────────────────────────────
# 2. Application Password
# ───────────────────────────────────────────────────────────────────────

# Generate one in WP Admin → Users → Your Profile → Application Passwords
USER_LOGIN="${USER_LOGIN:-api-bot}"
APP_PASSWORD="${APP_PASSWORD:-abcd 1234 efgh 5678 ijkl 9012}"

curl -s -X POST \
  -u "$USER_LOGIN:$APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "action_id": "wp_post_receives_comment",
    "user_id":   42,
    "metadata":  { "source": "curl_example_app_password" }
  }' \
  "$SITE/wp-json/wb-gamification/v1/events"
echo

# ───────────────────────────────────────────────────────────────────────
# 3. Plugin-issued API key (X-WB-Gam-Key header)
# ───────────────────────────────────────────────────────────────────────

# Issue one in WP Admin → Gamification → API Keys
API_KEY="${API_KEY:-wbgam_live_abc123def456}"

curl -s -X POST \
  -H "X-WB-Gam-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "action_id": "wp_post_receives_comment",
    "user_id":   42,
    "metadata":  { "source": "curl_example_api_key", "device": "iphone-15" }
  }' \
  "$SITE/wp-json/wb-gamification/v1/events"
echo

# ───────────────────────────────────────────────────────────────────────
# Read-side examples (no auth needed for public endpoints)
# ───────────────────────────────────────────────────────────────────────

# List all valid action_ids you can use above
curl -s "$SITE/wp-json/wb-gamification/v1/actions" | jq '.[].id'

# Get the OpenAPI spec for typed-client generation
curl -s "$SITE/wp-json/wb-gamification/v1/openapi.json" | jq '.info'

# Fetch a member's current points + history
curl -s "$SITE/wp-json/wb-gamification/v1/members/1/points" | jq '.'
