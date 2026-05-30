# Members and Points

Endpoints for member profiles, the points ledger, point types, and currency conversions. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](15-rest-overview.md) for authentication and error formats.

## Members

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/members/{id}` | Public (full private data for self or admin) |
| `GET` | `/members/{id}/points` | Self or admin |
| `GET` | `/members/{id}/level` | Public |
| `GET` | `/members/{id}/badges` | Public |
| `GET` | `/members/{id}/events` | Self or admin |
| `GET` | `/members/{id}/streak` | Public |
| `GET` | `/members/me/toasts` | Must be logged in |

### GET /members/{id}

Full gamification profile for one member. Unauthenticated requests return public data only; self or admin returns full private data.

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/42 \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "id": 42,
  "display_name": "Jane Smith",
  "avatar_url": "https://...",
  "points": 1250,
  "points_by_type": { "default": 1250 },
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

### GET /members/{id}/points

Paginated points history for a member.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Rows per page (max 100) |

Response headers: `X-WP-Total`, `X-WP-TotalPages`.

```bash
curl "https://example.com/wp-json/wb-gamification/v1/members/42/points?per_page=20" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "total": 1250,
  "history": [
    {
      "id": 99,
      "event_id": "uuid",
      "action_id": "publish_post",
      "points": 10,
      "object_id": 55,
      "created_at": "2026-03-18 12:00:00"
    }
  ]
}
```

### GET /members/{id}/level

Current level and full level ladder with progress.

### GET /members/{id}/badges

All badges earned by the member, ordered by `earned_at` descending.

### GET /members/{id}/events

Paginated raw event log.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Rows per page (max 100) |

### GET /members/{id}/streak

Streak data with an optional contribution heatmap.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `heatmap_days` | int | 0 | Include N days of contribution data. 0 = skip |

### GET /members/me/toasts

Read and flush pending toast notifications for the current user. Requires authentication.

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/me/toasts \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Points

| Method | Endpoint | Permission |
|--------|----------|------------|
| `POST` | `/points/award` | `manage_options` or `wb_gam_award_manual` |
| `DELETE` | `/points/{id}` | `manage_options` |

### POST /points/award

Manually award points to a member. Bypasses cooldown and cap checks.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Target user ID |
| `points` | int | Yes | Points to award (1 to 100,000) |
| `reason` | string | No | Action ID label. Default `manual_award` |
| `note` | string | No | Admin note stored in event metadata |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/points/award \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "user_id": 42, "points": 100, "reason": "manual_award" }'
```

```json
{ "awarded": true, "user_id": 42, "points": 100, "reason": "manual_award" }
```

Returns HTTP 201 on success.

### DELETE /points/{id}

Revoke a specific points ledger row. The event record is preserved (events are immutable); only the points side-effect is removed.

```bash
curl -X DELETE https://example.com/wp-json/wb-gamification/v1/points/99 \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{ "deleted": true, "id": 99, "user_id": 42, "points": 10 }
```

## Point Types

Multi-currency support. Each site can define several point types (e.g. XP, coins, gems) with one default.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/point-types` | Public |
| `POST` | `/point-types` | `manage_options` |
| `POST` `PUT` `PATCH` | `/point-types/{slug}` | `manage_options` |
| `DELETE` | `/point-types/{slug}` | `manage_options` |
| `POST` | `/point-types/{from}/convert` | Must be logged in |

### POST /point-types

Create a point type.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `slug` | string | Yes | Machine slug for the currency |
| `label` | string | Yes | Display label |
| `description` | string | No | Description shown in admin |
| `icon` | string | No | Icon identifier |
| `is_default` | boolean | No | Mark as the site default currency |
| `position` | int | No | Sort order |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/point-types \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "slug": "coins", "label": "Coins", "is_default": false }'
```

### POST /point-types/{from}/convert

Convert a member's balance from one currency to another. The conversion is atomic: a debit and a credit ledger row share one event ID.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `to_type` | string | Yes | Target currency slug |
| `amount` | int | Yes | Amount of the source currency to convert |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/point-types/coins/convert \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "to_type": "gems", "amount": 100 }'
```

## Point Type Conversions

Conversion rules that define exchange rates and limits between point types.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/point-type-conversions` | Public |
| `POST` | `/point-type-conversions` | `manage_options` |
| `POST` `PUT` `PATCH` | `/point-type-conversions/{id}` | `manage_options` |
| `DELETE` | `/point-type-conversions/{id}` | `manage_options` |

### POST /point-type-conversions

Define a conversion rate between two currencies.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_type` | string | Yes | Source currency slug |
| `to_type` | string | Yes | Target currency slug |
| `from_amount` | int | Yes | Source units in the exchange ratio |
| `to_amount` | int | Yes | Target units in the exchange ratio |
| `min_convert` | int | No | Minimum amount per conversion |
| `cooldown_seconds` | int | No | Minimum seconds between conversions |
| `max_per_day` | int | No | Daily conversion cap per user |
| `is_active` | boolean | No | Whether the rule is enabled |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/point-type-conversions \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "from_type": "coins", "to_type": "gems", "from_amount": 100, "to_amount": 1 }'
```
