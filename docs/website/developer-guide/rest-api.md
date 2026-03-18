# REST API Reference

All endpoints live under `/wp-json/wb-gamification/v1/`.

Authentication uses WordPress cookie auth for logged-in requests, or Application Passwords for external clients.

---

## Members

### Get member summary

```
GET /members/{user_id}
```

Returns a member's points total, current level, badge count, streak, and profile links.

**Response:**

```json
{
  "user_id": 42,
  "display_name": "Jane Smith",
  "avatar_url": "https://example.com/...",
  "points": 1250,
  "level": { "level": 5, "name": "Active", "min_points": 1000 },
  "badge_count": 8,
  "streak": 14
}
```

---

## Points

### Get member points

```
GET /members/{user_id}/points
```

**Query params:**

| Param | Default | Description |
|---|---|---|
| `period` | `all` | `all`, `month`, `week`, `day` |
| `limit` | `20` | Max transactions |
| `offset` | `0` | Pagination offset |

**Response:**

```json
{
  "total": 1250,
  "history": [
    {
      "id": 99,
      "action_id": "publish_post",
      "label": "Published a post",
      "points": 10,
      "created_at": "2026-03-18T12:00:00Z"
    }
  ]
}
```

### Award or deduct points

```
POST /members/{user_id}/points
```

Requires `wb_gam_award_manual` capability.

**Body:** `{ "points": 50, "note": "Speaker bonus" }`

---

## Badges

### List all badges

```
GET /badges
```

Returns the full badge library with criteria and enabled state.

### Get member badges

```
GET /members/{user_id}/badges
```

Returns badges the member has earned with award date.

### Award a badge

```
POST /members/{user_id}/badges
```

Requires `wb_gam_award_manual`.

**Body:** `{ "badge_id": 5 }`

### Revoke a badge

```
DELETE /members/{user_id}/badges/{badge_id}
```

Requires `wb_gam_award_manual`.

---

## Leaderboard

### Get leaderboard

```
GET /leaderboard
```

**Query params:**

| Param | Default | Description |
|---|---|---|
| `period` | `all` | `all`, `month`, `week`, `day` |
| `limit` | `10` | 1–100 |
| `scope_type` | — | Scope type (e.g. `bp_group`) |
| `scope_id` | `0` | Scope object ID |

**Response:**

```json
[
  {
    "rank": 1,
    "user_id": 42,
    "display_name": "Jane Smith",
    "avatar_url": "https://example.com/...",
    "points": 3200
  }
]
```

---

## Actions

### List registered actions

```
GET /actions
```

Returns the full action manifest with labels, point values, and enabled states.

---

## Challenges

### List challenges

```
GET /challenges
```

### Get a single challenge

```
GET /challenges/{id}
```

---

## Kudos

### Send kudos

```
POST /kudos
```

Requires authentication.

**Body:** `{ "recipient_id": 42, "message": "Great post on async hooks!" }`

### Get kudos received by a member

```
GET /members/{user_id}/kudos
```

---

## Credentials (OpenBadges 3.0)

### Get verifiable credential

```
GET /credentials/{badge_id}/{user_id}
```

Returns an OpenBadges 3.0 JSON-LD document. This endpoint is **public** — no authentication required — so credential URLs can be verified by third parties, including LinkedIn.

Returns HTTP 410 Gone for expired credentials.

**Response:**

```json
{
  "@context": [
    "https://www.w3.org/2018/credentials/v1",
    "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"
  ],
  "type": ["VerifiableCredential", "OpenBadgeCredential"],
  "issuer": {
    "id": "https://example.com",
    "name": "Example Community"
  },
  "credentialSubject": {
    "id": "https://example.com/members/jane-smith/",
    "achievement": {
      "name": "Top Contributor",
      "description": "Awarded to members who reach 5,000 points."
    }
  }
}
```

---

## Webhooks

### Register a webhook

```
POST /webhooks
```

Requires `manage_options`.

**Body:** `{ "event": "points.awarded", "url": "https://yourapp.com/hook", "secret": "optional-hmac-secret" }`

### List webhooks

```
GET /webhooks
```

### Delete a webhook

```
DELETE /webhooks/{id}
```

### Supported webhook events

| Event | Fires When |
|---|---|
| `points.awarded` | Any point transaction recorded |
| `badge.awarded` | A badge is awarded to a member |
| `badge.revoked` | A badge is revoked |
| `level.up` | A member reaches a new level |
| `kudos.sent` | Kudos are sent |
| `challenge.completed` | A member completes a challenge |

### Webhook payload format

```json
{
  "event": "points.awarded",
  "timestamp": "2026-03-18T12:00:00Z",
  "data": {
    "user_id": 42,
    "points": 10,
    "action_id": "publish_post"
  }
}
```

If a `secret` was provided on registration, the request includes an `X-WB-Gam-Signature` header containing an HMAC-SHA256 digest of the payload.

---

## Error Responses

All errors use the standard WordPress REST error format:

```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": { "status": 403 }
}
```

| Status | Meaning |
|---|---|
| 400 | Bad request — missing or invalid parameters |
| 401 | Not authenticated |
| 403 | Insufficient capability |
| 404 | Resource not found |
| 410 | Gone — credential has expired |
