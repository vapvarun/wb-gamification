# Cross-Site API

## Overview

WB Gamification can act as a centralized gamification server. A dedicated WordPress site runs the plugin and holds all gamification data. Remote sites — BuddyPress communities, WooCommerce stores, headless frontends, mobile apps — authenticate via API keys and submit events to the central server.

This model is useful when you run multiple sites and want a single leaderboard, unified badge library, and one admin interface.

## Deployment Modes

| Mode | How | When to use |
|------|-----|-------------|
| **Local** | Plugin installed on the same site. Uses WordPress cookie/nonce auth | Single-site installs, the default |
| **Remote** | Plugin on a dedicated gamification center site. Remote clients authenticate via `X-WB-Gam-Key` | Multi-site setups, mobile apps, external services |

In remote mode the `capabilities` response includes `"mode": "remote"` and `"site_id"` is populated for all events.

## Creating API Keys

API keys are created and managed at **WP Admin → Gamification → API Keys**. Each key is associated with a WordPress user (whose capabilities determine what the key can do) and optionally a `site_id` string for attribution.

You can also create keys programmatically:

```php
use WBGam\API\ApiKeyAuth;

$key = ApiKeyAuth::create_key(
    label:   'My Remote Store',     // Human-readable label shown in admin.
    user_id: 1,                     // User whose capabilities the key inherits.
    site_id: 'my-store'             // Identifier embedded in all events from this key.
);

// $key = 'wbgam_AbCdEfGhIjKlMnOpQrStUvWxYz...' (40-char random string)
```

Keys start with `wbgam_`. Store them securely — they cannot be retrieved after creation.

### Revoking and Deleting Keys

```php
// Deactivate (keeps the key record for audit purposes).
ApiKeyAuth::revoke_key( 'wbgam_...' );

// Permanently delete.
ApiKeyAuth::delete_key( 'wbgam_...' );
```

## Authenticating Requests

Include the API key in every request to the gamification center.

### Option 1: HTTP Header (recommended)

```http
GET /wp-json/wb-gamification/v1/members/42
X-WB-Gam-Key: wbgam_AbCdEfGhIjKlMnOpQrStUvWxYz...
```

### Option 2: Query Parameter

```http
GET /wp-json/wb-gamification/v1/members/42?api_key=wbgam_AbCdEfGhIjKlMnOpQrStUvWxYz...
```

The header approach is preferred — query params can appear in server logs.

### Authentication Priority

API key auth runs at `rest_authentication_errors` priority 20, after WordPress's own cookie/nonce auth. If a valid cookie session already exists, the API key is ignored. This means the same endpoint works for both browser sessions and remote API callers.

## Site ID Tracking

When a request is authenticated via an API key that has a `site_id` set, that value is injected into every event's metadata under the `_site_id` key and stored in the `wb_gam_events.site_id` column.

You can filter by `site_id` when querying the event log to audit activity per remote site.

The `site_id` is also exposed in the `capabilities` response so remote clients can confirm their identity:

```json
{
  "authenticated": true,
  "mode": "remote",
  "site_id": "my-store",
  "can": { "submit_events": true, "award_points": false }
}
```

## CORS Headers

When a request is authenticated via API key, the plugin automatically adds CORS headers allowing cross-origin requests:

```
Access-Control-Allow-Origin: <request origin>
Access-Control-Allow-Credentials: true
Access-Control-Allow-Headers: X-WB-Gam-Key, Content-Type, Authorization, X-WP-Nonce
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
```

This enables browser-based JavaScript on remote origins to call the gamification center directly.

## Capabilities Discovery

Before making calls, a remote site should fetch the `capabilities` endpoint to learn what it is allowed to do:

```http
GET /wp-json/wb-gamification/v1/capabilities
X-WB-Gam-Key: wbgam_...
```

**Response:**

```json
{
  "authenticated": true,
  "user_id": 1,
  "site_id": "my-store",
  "mode": "remote",
  "can": {
    "read_leaderboard": true,
    "read_badges": true,
    "read_own_profile": true,
    "read_any_profile": false,
    "award_points": false,
    "submit_events": true,
    "give_kudos": true
  },
  "features": { "cohort_leagues": false, "redemption_store": true },
  "version": "1.0.0",
  "endpoints": {
    "members":     "https://gam.example.com/wp-json/wb-gamification/v1/members",
    "leaderboard": "https://gam.example.com/wp-json/wb-gamification/v1/leaderboard",
    "badges":      "https://gam.example.com/wp-json/wb-gamification/v1/badges",
    "kudos":       "https://gam.example.com/wp-json/wb-gamification/v1/kudos",
    "capabilities":"https://gam.example.com/wp-json/wb-gamification/v1/capabilities"
  }
}
```

Use the `endpoints` map to resolve URLs dynamically rather than hardcoding paths.

## Connecting a Remote Site

1. On the gamification center site, go to **WP Admin → Gamification → API Keys** and create a key. Set the `Site ID` field to something that identifies your remote site (e.g. `store-site-1`).

2. Copy the generated key.

3. On the remote site, install WB Gamification and add the key to your `wp-config.php` or your plugin's settings:

```php
define( 'WB_GAM_REMOTE_CENTER_URL', 'https://gam.example.com' );
define( 'WB_GAM_REMOTE_API_KEY',    'wbgam_...' );
```

4. Use the helper functions or REST API to forward events:

```php
// Forward a points event to the central server.
$response = wp_remote_post(
    WB_GAM_REMOTE_CENTER_URL . '/wp-json/wb-gamification/v1/points/award',
    [
        'headers' => [
            'X-WB-Gam-Key' => WB_GAM_REMOTE_API_KEY,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode( [
            'user_id' => $user_id,
            'points'  => 10,
            'reason'  => 'remote_purchase',
        ] ),
    ]
);
```

## Multi-Site Use Case

**Example architecture:**

```
                ┌─────────────────────────────────┐
                │  gamification.example.com        │
                │  (WB Gamification center site)   │
                │  - All points, badges, levels    │
                │  - Single leaderboard            │
                │  - Admin dashboard               │
                └───────────┬─────────────────────┘
                            │ REST API + API keys
            ┌───────────────┼────────────────────┐
            │               │                    │
     community.example.com  store.example.com   app.example.com
     (BuddyPress)           (WooCommerce)       (React Native)
     site_id: "community"   site_id: "store"    site_id: "mobile"
```

Each remote site uses its own API key with a distinct `site_id`. The gamification center can filter its event log and analytics by `site_id` to see which site generated which activity.
