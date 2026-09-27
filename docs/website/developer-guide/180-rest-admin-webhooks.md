# Admin: Rules, Webhooks, API Keys, Actions, Capabilities

Administrative endpoints for managing rules, outbound webhooks, API keys, action configuration, and the capabilities discovery endpoint. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](140-rest-overview.md) for authentication and error formats. Most endpoints here require the `manage_options` capability.

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

## Cohort Settings

Cohort league tier names, promotion/demotion thresholds, and duration. Stored as a single options document plus the leagues on/off switch (Settings > Modules); the endpoint reads and writes both together.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/cohort-settings` | `manage_options` or `wb_gam_manage_challenges` |
| `POST` | `/cohort-settings` | `manage_options` or `wb_gam_manage_challenges` |

### GET /cohort-settings

Read the current cohort settings document.

```bash
curl https://example.com/wp-json/wb-gamification/v1/cohort-settings \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "tier_1": "Bronze",
  "tier_2": "Silver",
  "tier_3": "Gold",
  "tier_4": "Diamond",
  "tier_5": "Obsidian",
  "promote_pct": 20,
  "demote_pct": 20,
  "duration": "weekly",
  "enabled": false
}
```

### POST /cohort-settings

Save the cohort settings document.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `tier_1` | string | Yes | Name of the lowest tier |
| `tier_2` | string | Yes | Name of the second tier |
| `tier_3` | string | Yes | Name of the third tier |
| `tier_4` | string | Yes | Name of the fourth tier |
| `tier_5` | string | No | Name of the fifth (top) tier |
| `promote_pct` | int | Yes | Percentage promoted to the tier above each period (1 to 50) |
| `demote_pct` | int | Yes | Percentage demoted to the tier below each period (1 to 50) |
| `duration` | string | Yes | `weekly` or `monthly` |
| `enabled` | boolean | No | Turns cohort leagues on or off. Since 1.6.5 the admin form no longer sends this - use Settings > Modules instead - but a client that sends it still flips the switch |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/cohort-settings \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "tier_1": "Bronze", "tier_2": "Silver", "tier_3": "Gold", "tier_4": "Diamond", "promote_pct": 20, "demote_pct": 20, "duration": "weekly" }'
```

## Webhooks

Outbound webhook registrations. See the [Webhooks Overview](190-webhooks-overview.md) for payload shapes, signing, and retries.

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

Keys for remote-site and mobile-app authentication. See [Cross-Site API](90-cross-site-api.md) for the full remote setup flow.

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

Discovery endpoint for mobile apps and remote sites. Returns authentication status, a permissions map, feature flags, plugin version, and all endpoint URLs. Separately, the staff-permissions delegation matrix - which roles hold which `wb_gam_*` capabilities - is managed under `/settings/capabilities`.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/capabilities` | Public |
| `GET` | `/settings/capabilities` | `manage_options` |
| `POST` `PUT` `PATCH` | `/settings/capabilities` | `manage_options` |

### GET /settings/capabilities

Read the staff-permissions delegation matrix: every plugin capability and which roles currently hold it. Deliberately gated to `manage_options` only, never a granular cap - the surface that grants capabilities can never itself be one of the capabilities you can grant.

```bash
curl https://example.com/wp-json/wb-gamification/v1/settings/capabilities \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "capabilities": [ "wb_gam_award_manual", "wb_gam_manage_members", "wb_gam_manage_rewards" ],
  "roles": { "administrator": [ "wb_gam_award_manual", "wb_gam_manage_members" ], "editor": [] }
}
```

### POST /settings/capabilities

Set the delegation matrix. Goes through the same write path as the Settings > Access admin screen, so the two surfaces never disagree about who holds what.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `roles` | object | Yes | Map of role slug to the array of `wb_gam_*` capabilities that role should hold. A role mapped to an empty list loses all plugin capabilities |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/settings/capabilities \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "roles": { "editor": [ "wb_gam_award_manual" ] } }'
```

```json
{
  "capabilities": [ "wb_gam_award_manual", "wb_gam_manage_members", "wb_gam_manage_rewards" ],
  "roles": { "administrator": [ "wb_gam_award_manual", "wb_gam_manage_members" ], "editor": [ "wb_gam_award_manual" ] },
  "applied": true
}
```

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
| `POST` | `/events` | `wb_gam_manage_members` (administrators by default; API keys act as the admin who created them) |
| `POST` | `/events/import` | `wb_gam_manage_members` |
| `GET` | `/events/stream` | `manage_options` |

Members cannot fire events: every action is awarded from the hook that observes the real activity. `POST /events` is for integrations recording something on a member's behalf.

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
| `POST` | `/tools/retry-side-effect/{id}` | `manage_options` |

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

### POST /tools/retry-side-effect/{id}

Re-run one failed background side effect (an email, webhook or notification that failed after the points were already awarded), by its id from the Analytics dead-letter panel. Returns `{ "success": true, "id": 12 }`; a missing id is a 404 and a retry that cannot run is a 409 with `code` `wb_gam_side_effect_handler_unregistered`, `wb_gam_side_effect_event_payload_unparseable` or `wb_gam_side_effect_retry_failed`.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/tools/retry-side-effect/12 \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Import

Migrate points, badges and ranks from another gamification plugin (Settings > Import). Requires `wb_gam_manage_members`. Imports run in import mode, so members are not sent "you earned a badge" messages for history.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/import/sources` | `wb_gam_manage_members` |
| `POST` | `/import/{source}` | `wb_gam_manage_members` |

### GET /import/sources

List the supported sources and whether each has data on this site: `{ "sources": [ { "slug": "gamipress", "label": "GamiPress", "available": true }, ... ] }`. Sources are `gamipress`, `mycred` and `badgeos`.

### POST /import/{source}

Import from one source. Re-running is safe: every imported row carries a stable source key, so nothing is imported twice.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `source` | string | Yes | `gamipress`, `mycred` or `badgeos` (in the URL) |
| `dry_run` | boolean | No | Default `true`: report what would be imported without writing. Send `false` to import |

An unknown source or a source with no data returns 400 (`wb_gam_unknown_source`, `wb_gam_source_unavailable`).

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/import/mycred \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "dry_run": false }'
```

## Email settings

Which transactional emails members receive. Requires `wb_gam_manage_email_settings` (administrators by default).

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/settings/emails` | `wb_gam_manage_email_settings` |
| `POST` | `/settings/emails` | `wb_gam_manage_email_settings` |

### GET /settings/emails

Returns one boolean per email: `{ "level_up": true, "badge_earned": true, "challenge_completed": false, "redemption": true }`.

### POST /settings/emails

Send only the emails you want to change; any key you leave out keeps its current value. Returns `{ "ok": true, "settings": { ... } }` with all four values.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/settings/emails \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "challenge_completed": true }'
```
