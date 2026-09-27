# Badges, Levels, Leaderboard, Recap

Endpoints for badge definitions, the level ladder, leaderboards, and year-in-review recap data. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](140-rest-overview.md) for authentication and error formats.

## Badges

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/badges` | Public |
| `POST` | `/badges` | `manage_options` |
| `GET` | `/badges/{id}` | Public |
| `PUT` `PATCH` | `/badges/{id}` | `manage_options` |
| `DELETE` | `/badges/{id}` | `manage_options` |
| `POST` | `/badges/{id}/award` | `manage_options` |
| `GET` | `/badges/{id}/credential/{user}` | Public (badge must be published by the member) |
| `POST` | `/badges/{id}/share` | Must be logged in (self only) |
| `DELETE` | `/badges/{id}/share` | Must be logged in (self only) |
| `GET` | `/badges/{id}/share/{user}` | Public (badge must be published by the member) |

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

### POST /badges/{id}/share

Publish the current member's own badge so it can be viewed without login and shared to LinkedIn. Only the member who earned the badge can publish it - there is no `user_id` parameter, deliberately, so an admin cannot publish somebody else's achievement on their behalf. Fails with 403 if the caller has not earned the badge.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/share \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{ "shared": true, "share_url": "https://example.com/badge/top_contributor/42" }
```

### DELETE /badges/{id}/share

Unpublish the current member's own badge. Always operates on the caller.

```bash
curl -X DELETE https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/share \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{ "shared": false }
```

### GET /badges/{id}/share/{user}

Public share card for a badge award: the badge definition, the earner's display name/avatar/profile URL, `earned_at`, site info, a LinkedIn share URL, and Open Graph tags for server-side meta rendering. Returns 404 unless the member has published this badge via `POST /badges/{id}/share` - self and admins can preview the card before publishing.

```bash
curl https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/share/42
```

```json
{
  "badge": { "id": "top_contributor", "name": "Top Contributor", "description": "...", "image_url": "https://...", "is_credential": true, "category": "writing" },
  "earner": { "user_id": 42, "display_name": "Jane Smith", "avatar_url": "https://...", "profile_url": "https://..." },
  "earned_at": "2026-03-18 12:00:00",
  "site": { "name": "Example", "url": "https://example.com" },
  "share_urls": { "linkedin": "https://www.linkedin.com/shareArticle?...", "self": "https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/share/42" },
  "og": { "title": "Jane Smith earned the Top Contributor badge on Example", "description": "...", "image": "https://...", "url": "https://..." }
}
```

`earned_at` is UTC `Y-m-d H:i:s`.

### GET /badges/{id}/credential/{user}

Public OpenBadges 3.0 verifiable credential (JSON-LD) for a badge award, suitable for LinkedIn's "Add Certification" flow or any OB3-aware wallet. Requires the badge to have `is_credential` set AND the member to have published it via `POST /badges/{id}/share` - the same publish gate as the share card. Returns 404 if the badge is not a credential, not found, not shared, or not earned; returns 410 Gone if the credential has expired (`expires_at` in the past).

```bash
curl https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/credential/42
```

```json
{
  "@context": [ "https://www.w3.org/2018/credentials/v1", "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json" ],
  "id": "https://example.com/wp-json/wb-gamification/v1/badges/top_contributor/credential/42",
  "type": [ "VerifiableCredential", "OpenBadgeCredential" ],
  "issuer": { "id": "https://example.com/wp-json/wb-gamification/v1/issuer", "type": "Profile", "name": "Example", "url": "https://example.com" },
  "issuanceDate": "2026-03-18T12:00:00+00:00",
  "name": "Top Contributor",
  "expirationDate": null,
  "credentialSubject": {
    "id": "https://example.com/?author=42",
    "type": "AchievementSubject",
    "name": "Jane Smith",
    "achievement": { "id": "https://example.com/wp-json/wb-gamification/v1/badges/top_contributor", "type": "Achievement", "name": "Top Contributor", "description": "...", "image": "https://...", "criteria": { "narrative": "..." }, "issuer": { "id": "https://example.com/wp-json/wb-gamification/v1/issuer", "type": "Profile", "name": "Example" } }
  }
}
```

The response is served with `Content-Type: application/ld+json`. `issuanceDate` and `expirationDate` are ISO 8601, converted from the UTC `earned_at`/`expires_at` stored on the badge award.

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
