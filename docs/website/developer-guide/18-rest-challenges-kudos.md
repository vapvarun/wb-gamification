# Challenges, Kudos, Submissions, Redemption

Endpoints for individual challenges, community challenges, peer kudos, the UGC submission queue, and the rewards redemption store. Base URL is `/wp-json/wb-gamification/v1/`. See [REST API Overview](15-rest-overview.md) for authentication and error formats.

## Challenges

Individual, per-member challenges that track progress toward a target action.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/challenges` | Public |
| `POST` | `/challenges` | `manage_options` |
| `GET` | `/challenges/{id}` | Public |
| `PUT` `PATCH` | `/challenges/{id}` | `manage_options` |
| `DELETE` | `/challenges/{id}` | `manage_options` |
| `POST` | `/challenges/{id}/complete` | Must be logged in |

### POST /challenges

Create a challenge.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | Yes | Challenge title |
| `description` | string | No | Description shown to members |
| `action_id` | string | Yes | Action that advances the challenge |
| `target` | int | No | Number of action occurrences to complete |
| `bonus_points` | int | No | Points awarded on completion |
| `starts_at` | string | No | ISO start datetime |
| `ends_at` | string | No | ISO end datetime |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/challenges \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "title": "Week of Learning", "action_id": "ld_lesson_complete", "target": 5, "bonus_points": 100 }'
```

### PUT /challenges/{id}

Update a challenge. Accepts `title`, `action_id`, `target`, `bonus_points`, `starts_at`, `ends_at`, `status`.

### POST /challenges/{id}/complete

Mark a challenge complete for the current user.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | int | Yes | Challenge ID |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/challenges/7/complete \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "id": 7 }'
```

## Community Challenges

Group-wide challenges where members contribute toward a shared target count.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/community-challenges` | Public |
| `POST` | `/community-challenges` | `manage_options` |
| `GET` | `/community-challenges/{id}` | Public |
| `PUT` `PATCH` | `/community-challenges/{id}` | `manage_options` |
| `DELETE` | `/community-challenges/{id}` | `manage_options` |

### POST /community-challenges

Create a community challenge.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | Yes | Challenge title |
| `description` | string | No | Description shown to members |
| `target_count` | int | Yes | Total contributions needed to complete |
| `target_action` | string | No | Action that contributes to the count |
| `bonus_points` | int | No | Bonus awarded to participants on completion |
| `starts_at` | string | No | ISO start datetime |
| `ends_at` | string | No | ISO end datetime |
| `status` | string | No | Challenge status |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/community-challenges \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "title": "1000 Posts Together", "target_count": 1000, "target_action": "publish_post", "bonus_points": 50 }'
```

## Kudos

Peer-to-peer recognition with a per-user daily limit.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/kudos` | Public |
| `POST` | `/kudos` | Must be logged in |
| `GET` | `/kudos/me` | Must be logged in |

### GET /kudos

Recent kudos feed.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 20 | Max entries (1 to 50) |

```bash
curl "https://example.com/wp-json/wb-gamification/v1/kudos?limit=20"
```

### POST /kudos

Give kudos to another member. One of `receiver_id` or `recipient_login` is required.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `receiver_id` | int | conditional | User ID of recipient |
| `recipient_login` | string | conditional | Added in 1.4.0. User login (username) or email of recipient, resolved server-side. Use this when the giver does not know the recipient's user ID (e.g. the `[wb_gam_give_kudos]` shortcode) |
| `message` | string | No | Optional message (max 255 chars) |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/kudos \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "receiver_id": 55, "message": "Great work on the docs!" }'
```

```json
{ "success": true, "receiver_id": 55, "daily_remaining": 4 }
```

Returns HTTP 201 on success.

### GET /kudos/me

Current user's kudos stats: `received_total`, `daily_limit`, `sent_today`, `daily_remaining`.

```bash
curl https://example.com/wp-json/wb-gamification/v1/kudos/me \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Submissions

The user-generated-content submission queue. Members submit evidence of an achievement; admins approve or reject. Approval routes through the points engine so badges, levels, and totals stay consistent.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `GET` | `/submissions` | `manage_options` |
| `POST` | `/submissions` | Must be logged in |
| `POST` | `/submissions/{id}/approve` | `manage_options` |
| `POST` | `/submissions/{id}/reject` | `manage_options` |

### POST /submissions

Submit an achievement for review. A daily cap applies per user.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action_id` | string | Yes | Action the submission claims |
| `evidence` | string | No | Text description of the evidence |
| `evidence_url` | string | No | URL pointing to evidence |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/submissions \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "action_id": "external_talk", "evidence": "Spoke at WordCamp", "evidence_url": "https://..." }'
```

### GET /submissions

List submissions for review.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | (all) | Filter by status |
| `per_page` | int | 50 | Rows per page (max 100) |

### POST /submissions/{id}/approve

Approve a submission. Optional `notes` field is stored with the decision.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/submissions/12/approve \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "notes": "Verified against the conference schedule." }'
```

### POST /submissions/{id}/reject

Reject a submission. Optional `notes` field is stored with the decision.

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/submissions/12/reject \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "notes": "Could not verify the claim." }'
```

## Redemption

The rewards store. Members spend points on catalog items; admins manage the catalog.

| Method | Endpoint | Permission |
|--------|----------|------------|
| `POST` | `/redemptions` | Must be logged in |
| `GET` | `/redemptions/items` | Public |
| `POST` | `/redemptions/items` | `manage_options` |
| `GET` `POST` `PUT` `PATCH` `DELETE` | `/redemptions/items/{id}` | `manage_options` (write), public (read) |
| `GET` | `/redemptions/me` | Must be logged in |

### POST /redemptions

Redeem a catalog item with the current user's points.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `item_id` | int | Yes | Catalog item to redeem |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/redemptions \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "item_id": 3 }'
```

### POST /redemptions/items

Create a catalog item.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | Yes | Item title |
| `description` | string | No | Item description |
| `points_cost` | int | Yes | Points required to redeem |
| `point_type` | string | No | Currency slug the cost is charged in |
| `reward_type` | string | Yes | Reward fulfilment type |
| `reward_config` | object | No | Type-specific reward configuration |
| `stock` | int | No | Available stock |
| `is_active` | boolean | No | Whether the item is purchasable |

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/redemptions/items \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "title": "Sticker Pack", "points_cost": 500, "reward_type": "manual", "stock": 100 }'
```

### GET /redemptions/me

Current user's redemption history.

```bash
curl https://example.com/wp-json/wb-gamification/v1/redemptions/me \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```
