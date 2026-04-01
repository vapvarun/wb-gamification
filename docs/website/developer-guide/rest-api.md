# REST API Reference

Base URL: `/wp-json/wb-gamification/v1/`

## Authentication

Two methods are supported:

| Method | How | When to use |
|--------|-----|-------------|
| Cookie + nonce | Standard `X-WP-Nonce` header | Same-site JavaScript requests |
| API key | `X-WB-Gam-Key` header or `?api_key=` query param | Remote sites, mobile apps, Zapier/Make |

See [Cross-Site API](cross-site-api.md) for API key creation and remote site setup.

---

## Members Controller

### `GET /members/{id}`

Full gamification profile for one member.

**Permission:** Public (unauthenticated returns public data). Self or admin returns full private data.

**Response:**

```json
{
  "id": 42,
  "display_name": "Jane Smith",
  "avatar_url": "https://...",
  "points": 1250,
  "level": {
    "id": 3,
    "name": "Contributor",
    "min_points": 500,
    "progress_pct": 75,
    "next_threshold": 1500,
    "next_level_name": "Regular"
  },
  "badges_count": 8,
  "preferences": {
    "show_rank": true,
    "leaderboard_opt_out": false,
    "notification_mode": "smart"
  }
}
```

### `GET /members/{id}/points`

Paginated points history for a member.

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Rows per page (max 100) |

**Response headers:** `X-WP-Total`, `X-WP-TotalPages`

**Response body:**

```json
{
  "total": 1250,
  "history": [
    { "id": 99, "event_id": "uuid", "action_id": "publish_post", "points": 10, "object_id": 55, "created_at": "2026-03-18 12:00:00" }
  ]
}
```

### `GET /members/{id}/level`

Current level and full level ladder with progress.

### `GET /members/{id}/badges`

All badges earned by the member, ordered by `earned_at` DESC.

### `GET /members/{id}/events`

Paginated raw event log. Parameters: `page`, `per_page` (max 100).

### `GET /members/{id}/streak`

Streak data with optional contribution heatmap.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `heatmap_days` | int | 0 | Include N days of contribution data. 0 = skip |

### `GET /members/me/toasts`

Read and flush pending toast notifications for the current user. Requires authentication.

---

## Points Controller

### `POST /points/award`

Manually award points to a member. Bypasses cooldown and cap checks.

**Permission:** `manage_options` or `wb_gam_award_manual`

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Target user ID |
| `points` | int | Yes | Points to award (1–100,000) |
| `reason` | string | No | Action ID label. Default: `manual_award` |
| `note` | string | No | Admin note stored in event metadata |

**Response (201):**

```json
{ "awarded": true, "user_id": 42, "points": 100, "reason": "manual_award" }
```

### `DELETE /points/{id}`

Revoke a specific points ledger row. The event record is preserved (events are immutable); only the points side-effect is removed.

**Permission:** `manage_options`

**Response:**

```json
{ "deleted": true, "id": 99, "user_id": 42, "points": 10 }
```

---

## Badges Controller

### `GET /badges`

All badge definitions with optional earned status and rarity scores.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `user_id` | int | current user | Include earned status for this user. 0 = skip |
| `category` | string | — | Filter by category slug |

**Permission:** Public

### `GET /badges/{id}`

Single badge definition with rarity percentage and earner count.

**Permission:** Public

### `PUT /badges/{id}`

Update badge definition fields (`name`, `description`, `image_url`, `category`).

**Permission:** `manage_options`

### `DELETE /badges/{id}`

Delete a badge definition. Cascades to `wb_gam_user_badges` and associated rules.

**Permission:** `manage_options`

### `POST /badges/{id}/award`

Manually award a badge to a user.

**Permission:** `manage_options`

**Body:** `{ "user_id": 42 }`

**Response:**

```json
{ "awarded": true, "badge_id": "top_contributor", "user_id": 42, "message": "Badge awarded successfully." }
```

---

## Leaderboard Controller

### `GET /leaderboard`

Top-N members for a given period.

**Permission:** Public. Opt-out members excluded from results.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `all` | `all`, `month`, `week`, `day` |
| `limit` | int | 10 | 1–100 |
| `scope_type` | string | — | Scope type (e.g. `bp_group`) |
| `scope_id` | int | 0 | Scope object ID |

**Response:**

```json
{
  "period": "week",
  "scope": { "type": "", "id": 0 },
  "rows": [
    { "rank": 1, "user_id": 42, "display_name": "Jane Smith", "avatar_url": "https://...", "points": 320 }
  ]
}
```

### `GET /leaderboard/group/{group_id}`

BuddyPress group-scoped leaderboard. Accepts same `period` and `limit` params.

### `GET /leaderboard/me`

Current user's private rank (visible even when opted out of public display).

**Permission:** Must be logged in

---

## Kudos Controller

### `GET /kudos`

Recent kudos feed.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 20 | Max entries (1–50) |

**Permission:** Public

### `POST /kudos`

Give kudos to another member.

**Permission:** Must be logged in

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `receiver_id` | int | Yes | User ID of recipient |
| `message` | string | No | Optional message (max 255 chars) |

**Response (201):**

```json
{ "success": true, "receiver_id": 55, "daily_remaining": 4 }
```

### `GET /kudos/me`

Current user's kudos stats: `received_total`, `daily_limit`, `sent_today`, `daily_remaining`.

**Permission:** Must be logged in

---

## Actions Controller

### `GET /actions`

All registered gamification actions with labels, categories, point values, and enabled state.

**Permission:** `manage_options`

---

## Challenges Controller

Endpoints follow the pattern `/challenges` and `/challenges/{id}`. Full documentation at `/wp-json/wb-gamification/v1/challenges` schema endpoint.

---

## Events Controller

### `GET /events`

Site-wide event log with filtering. Admin only.

**Permission:** `manage_options`

---

## Webhooks Controller

### `GET /webhooks`

List registered webhook endpoints.

**Permission:** `manage_options`

### `POST /webhooks`

Register a new webhook.

**Body:** `{ "url": "https://...", "secret": "...", "events": ["points_awarded", "badge_awarded"] }`

### `DELETE /webhooks/{id}`

Delete a webhook registration.

---

## Rules Controller

Manage stored rule configurations (badge conditions, point multipliers).

**Permission:** `manage_options` for all write operations.

---

## Credential Controller (OpenBadges 3.0)

### `GET /credentials/{badge_id}/{user_id}`

Returns an OpenBadges 3.0 JSON-LD document. This endpoint is **public** — no authentication required — so credential URLs can be verified by LinkedIn and other services.

Returns HTTP 410 Gone for expired credentials.

```json
{
  "@context": ["https://www.w3.org/2018/credentials/v1", "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"],
  "type": ["VerifiableCredential", "OpenBadgeCredential"],
  "issuer": { "id": "https://example.com", "name": "My Community" },
  "credentialSubject": {
    "id": "https://example.com/members/jane-smith/",
    "achievement": { "name": "Top Contributor", "description": "..." }
  }
}
```

---

## Redemption Controller

Endpoints for the rewards store: list items, redeem points, view redemption history.

**Permission:** Authenticated members can redeem. `manage_options` for catalog management.

---

## Recap Controller

Year-in-review data for a member or site-wide.

---

## Badge Share Controller

Public badge share page data (OG metadata for social sharing).

---

## Levels Controller

### `GET /levels`

All configured levels with thresholds and icons.

---

## Capabilities Controller

### `GET /capabilities`

Discovery endpoint for mobile apps and remote sites. Returns authentication status, permissions map, feature flags, plugin version, and all endpoint URLs.

**Permission:** Public

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

---

## Error Responses

All errors use the standard WordPress REST error format:

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to manage points.",
  "data": { "status": 403 }
}
```

| Status | Meaning |
|--------|---------|
| 400 | Bad request — missing or invalid parameters |
| 401 | Not authenticated |
| 403 | Insufficient capability |
| 404 | Resource not found |
| 410 | Gone — credential has expired |
| 422 | Unprocessable — business rule violation (e.g. kudos daily limit) |
