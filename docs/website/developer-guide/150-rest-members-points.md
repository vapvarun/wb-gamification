# Members and Points

Endpoints for member profiles, the points ledger, point types, and currency conversions. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](140-rest-overview.md) for authentication and error formats.

## Members

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/members` | Admin (`context=edit`, default) or any logged-in member (`context=view`) |
| `GET` | `/members/{id}` | Public (full private data for self or admin) |
| `GET` | `/members/{id}/points` | Self or admin |
| `GET` | `/members/{id}/level` | Public |
| `GET` | `/members/{id}/badges` | Public |
| `GET` | `/members/{id}/events` | Self or admin |
| `GET` | `/members/{id}/streak` | Public |
| `POST` | `/members/{id}/streak` | `wb_gam_manage_members` |
| `DELETE` | `/members/{id}/streak` | `wb_gam_manage_members` |
| `GET` | `/members/{id}/intelligence` | Self or `wb_gam_view_analytics`/admin |
| `GET` | `/members/me/toasts` | Must be logged in |
| `GET` | `/members/me/profile-visibility` | Must be logged in |
| `POST` | `/members/me/profile-visibility` | Must be logged in |

### GET /members

Searchable, paginated member list. `context` picks the shape, as in WP core:

- `context=edit` (default) - the admin roster: points, level, badge count and earning status per member. Needs the manage-members capability.
- `context=view` - a member lookup for any logged-in member (it backs the Give Kudos recipient suggestions). Returns only `id`, `name`, `slug` (user nicename) and `avatar`. Searches display name and nicename, never email or login. Needs 2+ characters in `search`, returns at most 10 rows, and leaves out the caller and anyone who turned off their public profile.

```
GET /wp-json/wb-gamification/v1/members?context=view&search=pri
```

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
    "next_level_name": "Regular",
    "earned_points": 1400
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

`created_at` (and `earned_at` on the badge endpoints) is UTC `Y-m-d H:i:s`.

### GET /members/{id}/level

Current level and full level ladder with progress.

Levels follow `earned_points` (the balance plus points spent on rewards), not `points`, so compute "points to the next level" as `next.min_points - earned_points`. Both this route and `GET /members/{id}` return `earned_points`.

### GET /members/{id}/badges

All badges earned by the member, in display order: by category, then by threshold (1-Year before 2-Year, 100 points before 500). Expired badges are left out. Sort by `earned_at` yourself for most-recent first. Before 1.6.5 this was most-recent first.

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

### POST /members/{id}/streak

Admin adjustment for a member's streak values. A support/moderation surface for fixing a broken or wrong streak; the change is audited to the event log and fires `wb_gam_streak_adjusted`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `current_streak` | int | No | New current streak. Omit to leave unchanged |
| `longest_streak` | int | No | New longest streak. Omit to derive as max(current, existing) |
| `reason` | string | No | Audit reason recorded on the event |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/members/42/streak \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "current_streak": 5, "reason": "Support fix for missed check-in" }'
```

```json
{ "user_id": 42, "current_streak": 5, "longest_streak": 12, "last_active": "2026-03-18 12:00:00" }
```

### DELETE /members/{id}/streak

Admin reset of a member's current streak to 0. The longest streak (all-time record) is preserved. Audited to the event log and fires `wb_gam_streak_reset`.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reason` | string | No | Audit reason recorded on the event |

```bash
curl -X DELETE https://example.com/wp-json/wb-gamification/v1/members/42/streak \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "reason": "Member requested a fresh start" }'
```

```json
{ "user_id": 42, "current_streak": 0, "longest_streak": 12 }
```

### GET /members/{id}/intelligence

Per-member behavioral intelligence signals computed by the daily projection cron: an engagement score, action diversity, recency, event volume, churn risk, and an anomaly flag for bot-like or grinding behavior. Any member can read their own row; reading another member's row needs `wb_gam_view_analytics` or `manage_options`. If no projection exists yet, the endpoint computes one on demand instead of returning a 404.

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/42/intelligence \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "user_id": 42,
  "engagement_score": 1.8,
  "action_diversity": 6,
  "recency_days": 1,
  "events_30d": 42,
  "churn_risk": 0.15,
  "anomaly_flag": false,
  "computed_at": "2026-03-18 12:00:00"
}
```

The projection may lag up to 24 hours behind ground truth on quiet installs. `computed_at` is UTC `Y-m-d H:i:s`.

## Profile Visibility

The member's own choice of whether their gamification profile is publicly visible. Distinct from the site-wide `wb_gam_profile_public_enabled` kill-switch: this endpoint always operates on the current user, so the permission gate is simply being logged in.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/members/me/profile-visibility` | Must be logged in |
| `POST` | `/members/me/profile-visibility` | Must be logged in |

### GET /members/me/profile-visibility

Read the current member's visibility choice.

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/me/profile-visibility \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{ "public": true, "site_enabled": true, "managed_by_host": false }
```

`managed_by_host` is `true` when a community plugin (such as BuddyNext) decides profile privacy through the `wb_gam_can_view_public_profile` filter. The member then changes visibility on their community profile, and `public` here has no effect.

### POST /members/me/profile-visibility

Set the current member's visibility choice.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `public` | boolean | Yes | Whether the member wants their profile publicly visible |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/members/me/profile-visibility \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "public": false }'
```

```json
{ "public": false, "site_enabled": true, "managed_by_host": false }
```

When a community plugin decides profile privacy, the save is refused with `409 wb_gam_privacy_managed_by_host` instead of storing a choice nothing reads.

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
| `reason` | string | No | Why the points were given, shown to the member on the toast ("Won the photo contest"). Empty (default) shows "Manual award". |
| `note` | string | No | Admin note stored in event metadata |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/points/award \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "user_id": 42, "points": 100, "reason": "Won the photo contest" }'
```

```json
{ "awarded": true, "user_id": 42, "points": 100, "reason": "Won the photo contest" }
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
