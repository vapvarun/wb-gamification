# Webhook Events Reference

Every event type WB Gamification can deliver to a registered webhook, with the fields carried in the `data` object. For the envelope shape, signing, and retry behaviour, see the [Webhooks Overview](20-webhooks-overview.md).

## Event Summary

| Event | Fired When | Key `data` Fields |
|-------|-----------|-------------------|
| `points_awarded` | A user earns points from any action | `action_id`, `event_id`, `points` |
| `badge_earned` | A badge rule is satisfied and awarded | `badge_id`, `badge_name` |
| `level_changed` | A user's level increases after earning points | `new_level_id`, `new_level_name`, `old_level_id`, `old_level_name` |
| `streak_milestone` | A user hits a streak milestone (7, 14, 30... days) | `streak_days` |
| `challenge_completed` | A user finishes an individual challenge | `challenge_id`, `challenge_name` |
| `kudos_given` | A user sends peer kudos | `receiver_id`, `message` |

Subscribe to any combination of these event names when registering a webhook via `POST /webhooks`.

## points_awarded

Fired whenever a user earns points from any action.

| `data` Field | Type | Description |
|--------------|------|-------------|
| `action_id` | string | The action that triggered the award |
| `event_id` | string | UUID of the immutable event record |
| `points` | int | Points awarded |

```json
{
  "event": "points_awarded",
  "site_url": "https://community.example.com",
  "timestamp": "2026-04-12T14:30:00Z",
  "user_id": 42,
  "user_email": "jane@example.com",
  "data": {
    "action_id": "bp_new_activity",
    "event_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "points": 10
  }
}
```

## badge_earned

Fired when a badge rule is satisfied and the badge is awarded.

| `data` Field | Type | Description |
|--------------|------|-------------|
| `badge_id` | string | Slug of the earned badge |
| `badge_name` | string | Display name of the badge |

```json
{
  "event": "badge_earned",
  "site_url": "https://community.example.com",
  "timestamp": "2026-04-12T14:30:00Z",
  "user_id": 42,
  "user_email": "jane@example.com",
  "data": {
    "badge_id": "first_post",
    "badge_name": "First Post"
  }
}
```

## level_changed

Fired when a user's level increases after earning points.

| `data` Field | Type | Description |
|--------------|------|-------------|
| `new_level_id` | int | ID of the level just reached |
| `new_level_name` | string | Name of the new level |
| `old_level_id` | int | ID of the previous level |
| `old_level_name` | string | Name of the previous level |

```json
{
  "event": "level_changed",
  "site_url": "https://community.example.com",
  "timestamp": "2026-04-12T14:31:00Z",
  "user_id": 42,
  "user_email": "jane@example.com",
  "data": {
    "new_level_id": 3,
    "new_level_name": "Expert",
    "old_level_id": 2,
    "old_level_name": "Contributor"
  }
}
```

## streak_milestone

Fired when a user hits a streak milestone (7, 14, 30... days).

| `data` Field | Type | Description |
|--------------|------|-------------|
| `streak_days` | int | Number of consecutive days reached |

```json
{
  "event": "streak_milestone",
  "site_url": "https://community.example.com",
  "timestamp": "2026-04-12T08:00:00Z",
  "user_id": 42,
  "user_email": "jane@example.com",
  "data": {
    "streak_days": 30
  }
}
```

## challenge_completed

Fired when a user finishes an individual challenge.

| `data` Field | Type | Description |
|--------------|------|-------------|
| `challenge_id` | int | ID of the completed challenge |
| `challenge_name` | string | Name of the challenge |

```json
{
  "event": "challenge_completed",
  "site_url": "https://community.example.com",
  "timestamp": "2026-04-12T16:45:00Z",
  "user_id": 42,
  "user_email": "jane@example.com",
  "data": {
    "challenge_id": 7,
    "challenge_name": "Week of Learning"
  }
}
```

## kudos_given

Fired when a user sends peer kudos. Here `user_id` is the giver and `data.receiver_id` is the recipient.

| `data` Field | Type | Description |
|--------------|------|-------------|
| `receiver_id` | int | User ID of the kudos recipient |
| `message` | string | Optional message attached to the kudos |

```json
{
  "event": "kudos_given",
  "site_url": "https://community.example.com",
  "timestamp": "2026-04-12T12:00:00Z",
  "user_id": 15,
  "user_email": "bob@example.com",
  "data": {
    "receiver_id": 42,
    "message": "Great contribution to the forum!"
  }
}
```
