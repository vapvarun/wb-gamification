# REST API Overview

WB Gamification ships a full REST API under a single namespace. Every endpoint in this guide lives beneath that base URL.

## Base URL

```
/wp-json/wb-gamification/v1/
```

A full machine-readable spec is served at the namespace root:

```bash
curl https://example.com/wp-json/wb-gamification/v1/
```

## Authentication

Two authentication methods are supported. Public read endpoints (catalogs, leaderboard, OG share pages, OpenBadges credentials, the capabilities discovery endpoint) need no credentials at all.

| Method | How | When to use |
|--------|-----|-------------|
| Cookie + nonce | Standard `X-WP-Nonce` header | Same-site JavaScript requests |
| API key | `X-WB-Gam-Key` header or `?api_key=` query param | Remote sites, mobile apps, Zapier/Make |

See [Cross-Site API](10-cross-site-api.md) for API key creation and remote site setup.

### Cookie and nonce (same-site JS)

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/42 \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

### API key (remote)

```bash
curl https://example.com/wp-json/wb-gamification/v1/leaderboard \
  -H "X-WB-Gam-Key: YOUR_API_KEY"
```

Or as a query parameter:

```bash
curl "https://example.com/wp-json/wb-gamification/v1/leaderboard?api_key=YOUR_API_KEY"
```

## List Envelope Shape

Paginated list endpoints return a count plus the rows for the current page. The total count is also exposed in response headers so clients can build pagination without parsing the body.

| Header | Meaning |
|--------|---------|
| `X-WP-Total` | Total number of rows across all pages |
| `X-WP-TotalPages` | Total number of pages at the current `per_page` |

Standard list query parameters:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Rows per page (max 100) |

Example list body (points history):

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

## Error Format

All errors use the standard WordPress REST error envelope.

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to manage points.",
  "data": { "status": 403 }
}
```

| Status | Meaning |
|--------|---------|
| 400 | Bad request. Missing or invalid parameters |
| 401 | Not authenticated |
| 403 | Insufficient capability |
| 404 | Resource not found |
| 410 | Gone. Credential has expired |
| 422 | Unprocessable. Business rule violation (e.g. kudos daily limit) |

## Making a Request

A complete authenticated read against a member profile:

```bash
curl https://example.com/wp-json/wb-gamification/v1/members/42 \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "id": 42,
  "display_name": "Jane Smith",
  "points": 1250,
  "level": { "id": 3, "name": "Contributor", "progress_pct": 75 },
  "badges_count": 8
}
```

From here, the API is split across focused reference pages:

- [Members, Points, Point Types, Conversions](16-rest-members-points.md)
- [Badges, Levels, Leaderboard, Recap](17-rest-badges-levels.md)
- [Challenges, Community Challenges, Kudos, Submissions, Redemption](18-rest-challenges-kudos.md)
- [Admin: Rules, Webhooks, API Keys, Actions, Capabilities](19-rest-admin-webhooks.md)
