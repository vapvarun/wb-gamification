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

See [Cross-Site API](90-cross-site-api.md) for API key creation and remote site setup.

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

All `*_at` values are UTC `Y-m-d H:i:s` (since 1.6.5). Convert to the viewer's or the site's time zone for display.

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

## Discovery

Machine-readable endpoints for AI agents, SDK generators, and API explorers.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/abilities` | Public |
| `GET` | `/openapi.json` | Public |

### GET /abilities

Lists every gamification ability the WP Abilities API (6.9+) exposes, with its label, description, endpoint, HTTP methods, parameters, and auth level. On WP versions without the Abilities API this route is the only way to discover the same catalog; each ability proxies to its documented REST route under the hood, so calling an ability enforces that route's own permission check.

```bash
curl https://example.com/wp-json/wb-gamification/v1/abilities
```

```json
{
  "plugin": "wb-gamification",
  "version": "1.6.5",
  "description": "Complete gamification engine for WordPress - points, badges, levels, leaderboards, challenges, streaks.",
  "abilities": {
    "wb-gamification/read-leaderboard": { "label": "Read gamification leaderboard", "endpoint": "https://example.com/wp-json/wb-gamification/v1/leaderboard", "methods": [ "GET" ], "auth": "none" }
  }
}
```

### GET /openapi.json

Auto-generates a full OpenAPI 3.0.3 specification from every registered route on this namespace, including schemas and argument definitions. Public and unauthenticated so Swagger UI, Postman, and AI agents can import the API surface directly. See [Getting Started](00-getting-started.md) for where this fits in the SDK/tooling workflow.

```bash
curl https://example.com/wp-json/wb-gamification/v1/openapi.json
```

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

- [Members, Points, Point Types, Conversions](150-rest-members-points.md)
- [Badges, Levels, Leaderboard, Recap](160-rest-badges-levels.md)
- [Challenges, Community Challenges, Kudos, Submissions, Redemption](170-rest-challenges-kudos.md)
- [Admin: Rules, Webhooks, API Keys, Actions, Capabilities](180-rest-admin-webhooks.md)
