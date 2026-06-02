# Admin: Rules, Webhooks, API Keys, Actions, Capabilities

Administrative endpoints for managing rules, outbound webhooks, API keys, action configuration, and the capabilities discovery endpoint. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](15-rest-overview.md) for authentication and error formats. Most endpoints here require the `manage_options` capability.

## Actions

Registered gamification actions with their labels, point values, and rate-limit configuration.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/actions` | `manage_options` |
| `GET` | `/actions/{id}` | Public |
| `POST` | `/actions/{id}/overrides` | `manage_options` |
| `PUT` `DELETE` | `/actions/{id}/overrides` | `manage_options` |

### GET /actions

All registered gamification actions with labels, categories, point values, and enabled state.

```bash
curl https://example.com/wp-json/wb-gamification/v1/actions \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

### GET /actions/{id}

A single action by ID, with all admin overrides applied to `cooldown`, `daily_cap`, and `weekly_cap`.

```bash
curl https://example.com/wp-json/wb-gamification/v1/actions/bp_activity_update
```

### POST /actions/{id}/overrides

Added in 1.4.0. Set per-action overrides for `cooldown`, `daily_cap`, and `weekly_cap` without touching the manifest. Stored in the `wb_gam_action_overrides` site option, keyed by action ID. The engine reads these on every rate-limit check, so the override takes effect immediately for new awards. All fields are optional; omit a field to leave it unchanged.

| Field | Type | Description |
|-------|------|-------------|
| `cooldown` | int (>= 0) | Minimum seconds between awards. `0` disables the cooldown |
| `daily_cap` | int (>= 0) | Daily cap per user per action. `0` allows unlimited |
| `weekly_cap` | int (>= 0) | Weekly cap per user per action. `0` allows unlimited |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/actions/bp_activity_update/overrides \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "cooldown": 120, "daily_cap": 5 }'
```

```json
{
  "action_id": "bp_activity_update",
  "overrides": { "cooldown": 120, "daily_cap": 5 },
  "effective": { "cooldown": 120, "daily_cap": 5, "weekly_cap": 0 }
}
```

### DELETE /actions/{id}/overrides

Added in 1.4.0. Reset overrides for one action. The next read falls back to the manifest values.

```bash
curl -X DELETE https://example.com/wp-json/wb-gamification/v1/actions/bp_activity_update/overrides \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{ "action_id": "bp_activity_update", "reset": true }
```

## Rules

Stored rule configurations (badge conditions, point multipliers, level thresholds).

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/rules` | `manage_options` |
| `POST` | `/rules` | `manage_options` |
| `GET` `POST` `PUT` `PATCH` `DELETE` | `/rules/{id}` | `manage_options` |

```bash
curl https://example.com/wp-json/wb-gamification/v1/rules \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Webhooks

Outbound webhook registrations. See the [Webhooks Overview](20-webhooks-overview.md) for payload shapes, signing, and retries.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/webhooks` | `manage_options` |
| `POST` | `/webhooks` | `manage_options` |
| `GET` `PUT` `DELETE` | `/webhooks/{id}` | `manage_options` |
| `GET` | `/webhooks/{id}/log` | `manage_options` |
| `DELETE` | `/webhooks/{id}/log` | `manage_options` |

### POST /webhooks

Register a new webhook. The response includes the generated `secret`, which is only returned on creation. Store it securely.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `url` | string | Yes | Destination URL for delivery |
| `events` | array | Yes | Event names to subscribe to |
| `secret` | string | No | Custom signing secret. Generated if omitted |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/webhooks \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{
    "url": "https://hooks.zapier.com/hooks/catch/123/abc/",
    "events": ["points_awarded", "badge_earned", "level_changed"]
  }'
```

### GET /webhooks/{id}/log

Inspect delivery attempts for a webhook. A `status_code` of `0` indicates a connection-level failure (DNS, timeout).

```bash
curl https://example.com/wp-json/wb-gamification/v1/webhooks/1/log \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "webhook_id": 1,
  "entries": [
    { "event": "points_awarded", "status_code": 200, "success": true, "timestamp": "2026-04-12 14:30:05" },
    { "event": "badge_earned", "status_code": 0, "success": false, "timestamp": "2026-04-12 14:29:58" }
  ],
  "count": 2
}
```

## API Keys

Keys for remote-site and mobile-app authentication. See [Cross-Site API](10-cross-site-api.md) for the full remote setup flow.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/api-keys` | `manage_options` |
| `POST` | `/api-keys` | `manage_options` |
| `DELETE` | `/api-keys/{id}` | `manage_options` |
| `POST` `PUT` `PATCH` | `/api-keys/{id}/revoke` | `manage_options` |

### POST /api-keys

Create an API key. The full key value is returned only once on creation.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `label` | string | Yes | Human-readable key label |
| `site_id` | string | No | Identifier for the remote site this key serves |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/api-keys \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "label": "Mobile app", "site_id": "ios-prod" }'
```

### POST /api-keys/{id}/revoke

Revoke a key without deleting its record. Revoked keys stop authenticating immediately.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/api-keys/5/revoke \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Capabilities

Discovery endpoint for mobile apps and remote sites. Returns authentication status, a permissions map, feature flags, plugin version, and all endpoint URLs.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/capabilities` | Public |

```bash
curl https://example.com/wp-json/wb-gamification/v1/capabilities \
  -H "X-WB-Gam-Key: YOUR_API_KEY"
```

```json
{
  "authenticated": true,
  "user_id": 42,
  "site_id": "",
  "mode": "local",
  "can": {
    "read_leaderboard": true,
    "award_points": false,
    "give_kudos": true
  },
  "features": { "cohort_leagues": false },
  "version": "1.0.0",
  "endpoints": {
    "members": "https://example.com/wp-json/wb-gamification/v1/members",
    "leaderboard": "https://example.com/wp-json/wb-gamification/v1/leaderboard"
  }
}
```

## Events

Site-wide raw event log with filtering.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `POST` | `/events` | `manage_options` |
| `GET` | `/events/stream` | `manage_options` |

The `/events/stream` endpoint is a Server-Sent Events stream of live events for admin dashboards.

```bash
curl https://example.com/wp-json/wb-gamification/v1/events/stream \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Members roster

Site-owner member management (Gamification > Members). Added in 1.5.3. All admin-only.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/members` | `manage_options` |
| `POST` | `/members/{id}/exclude` | `manage_options` |
| `POST` | `/members/{id}/reset-points` | `manage_options` |

### GET /members

A paginated, searchable roster of every member with their points, level, and badges. The query primes points and badges per page so the listing stays N+1-free. Each row reports whether the member is currently excluded from earning.

| Field | Type | Description |
|-------|------|-------------|
| `page` | int | Page number. Default `1` |
| `per_page` | int | Rows per page. Default `20`, max `100` |
| `search` | string | Match against username, display name, email, and nicename |

```bash
curl "https://example.com/wp-json/wb-gamification/v1/members?search=jane&per_page=20" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

### POST /members/{id}/exclude

Toggle the per-user earning veto (`wb_gam_sandboxed` meta). An excluded member keeps their points but stops earning and is hidden from leaderboards.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `excluded` | boolean | No | `true` to exclude (default), `false` to include |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/members/42/exclude \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "excluded": true }'
```

### POST /members/{id}/reset-points

Zero a member's balance. The reset is recorded as a balancing debit so the points ledger keeps a full audit trail.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/members/42/reset-points \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{ "user_id": 42, "reset_from": 1200, "new_balance": 0 }
```

## Bulk award

Award the same points to a whole role or to all members at once. Added in 1.5.3.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `POST` | `/points/bulk` | `manage_options` |

### POST /points/bulk

Routes through the exclusion-aware batch award, so accounts excluded in Settings > Access are skipped automatically. Points must be positive.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `target` | string | Yes | `all` for every member, or a role slug |
| `points` | int | Yes | Points to grant each member (`1` to `100000`) |
| `point_type` | string | No | Currency slug. Defaults to the primary currency |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/points/bulk \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "target": "all", "points": 50 }'
```

```json
{ "awarded": 318, "target": "all", "points": 50 }
```

## Tools

Settings portability and maintenance (Settings > Tools). Added in 1.5.3. All admin-only.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/tools/export-settings` | `manage_options` |
| `POST` | `/tools/import-settings` | `manage_options` |
| `POST` | `/tools/recompute-leaderboard` | `manage_options` |
| `POST` | `/tools/reset-progress` | `manage_options` |

### GET /tools/export-settings

Download the plugin configuration as a JSON document of every `wb_gam_*` configuration option. Runtime, derived, and schema state (database version, feature schema gates, caches, snapshots, flush markers, wizard flags) is deliberately excluded so an import never corrupts the target site.

```bash
curl https://example.com/wp-json/wb-gamification/v1/tools/export-settings \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

### POST /tools/import-settings

Apply a document produced by `export-settings`. Only keys that start with `wb_gam_` and are not on the exclusion list are written.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `document` | object | Yes | A document produced by export-settings |

### POST /tools/recompute-leaderboard

Rebuild the leaderboard snapshot and clear its caches. This is the admin equivalent of `wp wb-gamification doctor --recompute-leaderboard`.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/tools/recompute-leaderboard \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

### POST /tools/reset-progress

Permanently clear all member progress (points, events, totals, earned badges, streaks, kudos, leaderboard cache, challenge logs, cohort membership, community-challenge contributions, redemptions, submissions, and per-user progress meta) while keeping every configuration and definition. Requires `confirm: true` on top of the admin capability check; the call is rejected without it.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `confirm` | boolean | Yes | Must be `true`. The reset is rejected otherwise |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/tools/reset-progress \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "confirm": true }'
```
