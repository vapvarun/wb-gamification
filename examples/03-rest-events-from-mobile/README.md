# Example 03 — REST Events Ingestion (mobile / headless / external services)

Fire gamification events from outside WordPress: a mobile app, headless frontend, third-party SaaS, IoT device, or any HTTP client.

## The endpoint

```
POST /wp-json/wb-gamification/v1/events
Content-Type: application/json
Authorization: <see Auth section below>

{
  "action_id": "wp_post_receives_comment",
  "user_id":   42,
  "metadata":  { "source": "mobile_app", "post_id": 123 }
}
```

**Important.** The points value is NOT in the request body. Points come from the action's configured `default_points` (or `wb_gam_points_<action_id>` option override). The endpoint validates that `action_id` is a registered action.

## Files in this example

- [`curl.sh`](curl.sh) — bash one-liners for every auth mode.
- [`fetch.js`](fetch.js) — vanilla JS / Node / React Native / browser fetch examples.
- [`python-client.py`](python-client.py) — Python `requests` example.
- [`postman-collection.json`](postman-collection.json) — Postman / Insomnia collection.

## Auth modes

### 1. Cookie + `wp_rest` nonce — best for in-WP frontend code

```js
fetch( '/wp-json/wb-gamification/v1/events', {
  method:  'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce':   wpApiSettings.nonce,  // localized via wp_localize_script
  },
  body: JSON.stringify({
    action_id: 'yourplugin_button_clicked',
    metadata:  { campaign: 'spring-2026' }
  }),
} );
```

### 2. Application Password — best for first-party server-to-server on the same WP install

```bash
USER_LOGIN="api-bot"
APP_PASSWORD="abcd 1234 efgh 5678 ijkl 9012"

curl -X POST https://your-site.com/wp-json/wb-gamification/v1/events \
  -H "Content-Type: application/json" \
  -u "$USER_LOGIN:$APP_PASSWORD" \
  -d '{"action_id":"yourplugin_event","user_id":42}'
```

### 3. Plugin-issued API Key — best for external systems (mobile apps, SaaS)

Issue a key in **WP Admin → Gamification → API Keys**. Each key has a label (for audit) and optional site_id (for multi-site reporting).

```bash
API_KEY="wbgam_live_abc123def456..."

curl -X POST https://your-site.com/wp-json/wb-gamification/v1/events \
  -H "Content-Type: application/json" \
  -H "X-WB-Gam-Key: $API_KEY" \
  -d '{"action_id":"yourplugin_event","user_id":42}'
```

API keys are validated by `src/API/ApiKeyAuth.php`. Each request runs through `RateLimiter` (per-action daily caps) before the engine accepts it.

## Response shape

```json
{
  "processed": true,
  "event_id":  "550e8400-e29b-41d4-a716-446655440000",
  "action_id": "yourplugin_event",
  "user_id":   42
}
```

The `event_id` is a UUID v4. Use it to correlate with downstream effects (the points row will FK back to this event_id; webhook deliveries reference it).

## Async pipeline — what happens after 200

1. Event written to `wb_gam_events` (immutable, source of truth).
2. Action Scheduler queues `wb_gam_process_event_async` for this event.
3. Async job runs (within 1-30 seconds, depending on cron health):
   - Rule engine evaluates the event against active rules.
   - Points awarded → `wb_gam_points` row written, `wb_gam_points_awarded` action fires.
   - Badges checked → `wb_gam_user_badges` updated if conditions met.
   - Streaks updated → `wb_gam_streaks` row written.
   - Webhooks dispatched → outbound HTTP calls to subscribers.

The 200 response means "event accepted", not "processing complete". For consumers who need to wait for the side effects, poll `GET /members/{id}/points` or subscribe to a webhook.

## Discovering valid `action_id` values

```bash
# List every registered action
curl http://your-site/wp-json/wb-gamification/v1/actions \
  | jq '.[].id'

# Get the full OpenAPI spec (39 endpoints, all schemas)
curl http://your-site/wp-json/wb-gamification/v1/openapi.json
```

The OpenAPI spec is the canonical contract — generate typed clients from it via `openapi-generator-cli` or similar.

## Rate limits

`RateLimiter` enforces per-user, per-action daily caps if the action defines `daily_cap > 0`. Hit the cap and the engine silently skips awarding (the event still gets recorded for audit). The HTTP response stays 200 — observers who care should listen for `wb_gam_points_awarded` rather than assuming success from the REST status.

## Common errors

| Code | Why | Fix |
|---|---|---|
| 401 `rest_not_logged_in` | No auth, or auth failed | Add a valid auth header (see above) |
| 400 `rest_missing_callback_param` | `action_id` field missing | Always include `action_id` |
| 400 `rest_invalid_param` | `action_id` is not a registered action | Check `GET /actions` for valid values |
| 403 `rest_forbidden` | Authenticated user lacks permission | Default policy: any logged-in user can ingest events for THEMSELVES. Use Application Password / API key for cross-user submission. |

## Related

- Webhooks for the reverse direction (WB Gamification → external) → [Example 04](../04-listen-via-webhook/)
- Adding a new event before posting it → [Example 01](../01-track-event-via-manifest/) or [Example 02](../02-programmatic-register/)
