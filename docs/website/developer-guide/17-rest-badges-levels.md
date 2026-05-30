# Badges, Levels, Leaderboard, Recap

Endpoints for badge definitions, the level ladder, leaderboards, and year-in-review recap data. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](15-rest-overview.md) for authentication and error formats.

## Badges

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/badges` | Public |
| `POST` | `/badges` | `manage_options` |
| `GET` | `/badges/{id}` | Public |
| `PUT` `PATCH` | `/badges/{id}` | `manage_options` |
| `DELETE` | `/badges/{id}` | `manage_options` |
| `POST` | `/badges/{id}/award` | `manage_options` |

### GET /badges

All badge definitions with optional earned status and rarity scores.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `user_id` | int | current user | Include earned status for this user. 0 = skip |
| `category` | string | (none) | Filter by category slug |

```bash
curl "https://example.com/wp-json/wb-gamification/v1/badges?category=writing"
```

### GET /badges/{id}

Single badge definition with rarity percentage and earner count.

```bash
curl https://example.com/wp-json/wb-gamification/v1/badges/top_contributor
```

### PUT /badges/{id}

Update badge definition fields (`name`, `description`, `image_url`, `category`).

```bash
curl -X PUT https://example.com/wp-json/wb-gamification/v1/badges/top_contributor \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "name": "Top Contributor", "description": "Earned for outstanding contributions." }'
```

### DELETE /badges/{id}

Delete a badge definition. Cascades to `wb_gam_user_badges` and associated rules.

### POST /badges/{id}/award

Manually award a badge to a user.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Target user ID |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/award \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "user_id": 42 }'
```

```json
{
  "awarded": true,
  "badge_id": "top_contributor",
  "user_id": 42,
  "message": "Badge awarded successfully."
}
```

## Levels

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/levels` | Public |
| `POST` | `/levels` | `manage_options` |
| `PUT` `PATCH` | `/levels/{id}` | `manage_options` |
| `DELETE` | `/levels/{id}` | `manage_options` |

### GET /levels

All configured levels with thresholds and icons.

```bash
curl https://example.com/wp-json/wb-gamification/v1/levels
```

### POST /levels

Create a level. Requires `manage_options`.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/levels \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "name": "Regular", "threshold": 1500 }'
```

## Leaderboard

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/leaderboard` | Public (opt-out members excluded) |
| `GET` | `/leaderboard/group/{group_id}` | Public |
| `GET` | `/leaderboard/me` | Must be logged in |

### GET /leaderboard

Top-N members for a given period. Opt-out members are excluded from results.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `all` | `all`, `month`, `week`, `day` |
| `limit` | int | 10 | 1 to 100 |
| `scope_type` | string | (none) | Scope type (e.g. `bp_group`) |
| `scope_id` | int | 0 | Scope object ID |

```bash
curl "https://example.com/wp-json/wb-gamification/v1/leaderboard?period=week&limit=10"
```

```json
{
  "period": "week",
  "scope": { "type": "", "id": 0 },
  "rows": [
    {
      "rank": 1,
      "user_id": 42,
      "display_name": "Jane Smith",
      "avatar_url": "https://...",
      "points": 320
    }
  ]
}
```

### GET /leaderboard/group/{group_id}

BuddyPress group-scoped leaderboard. Accepts the same `period` and `limit` parameters.

```bash
curl "https://example.com/wp-json/wb-gamification/v1/leaderboard/group/7?period=month"
```

### GET /leaderboard/me

Current user's private rank, visible even when opted out of public display. Requires login.

```bash
curl https://example.com/wp-json/wb-gamification/v1/leaderboard/me \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Recap

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/members/{id}/recap` | Self or admin |

### GET /members/{id}/recap

Year-in-review recap data for a member: points earned, badges unlocked, top actions, and milestones over the period.

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/42/recap \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```
