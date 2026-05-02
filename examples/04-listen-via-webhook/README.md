# Example 04 — Listen via Webhook (WB Gamification → external)

Receive notifications when something happens in WB Gamification — points, badges, level changes, kudos, streaks, challenges — and forward to Slack, Discord, Zapier, your CRM, an internal rewards system, or anywhere reachable via HTTP.

**No PHP needed.** Webhooks are configured entirely through the WP admin UI (or REST `/webhooks` endpoint).

## Setup

1. **Run a webhook receiver somewhere reachable by the WP install.** This example uses Node + Express; any HTTP server works (Lambda, Cloudflare Worker, Zapier webhook step, etc.). For Slack/Discord/Zapier integrations, you don't even need your own server — point the webhook directly at the platform's incoming webhook URL.

2. **Configure the webhook in WP Admin → Gamification → Webhooks**:
   - URL: where to POST events
   - Secret (optional): used for HMAC signature verification (recommended — see below)
   - Events: pick which events trigger this webhook (multi-select)

3. **WB Gamification's `WebhookDispatcher`** queues async POST calls via Action Scheduler. Failures retry up to 3 times with exponential backoff.

## Files in this example

- [`express-server.js`](express-server.js) — full Node/Express receiver with signature verification, event routing, and example forwarders.

## Available events

| Event | Fires when |
|---|---|
| `points_awarded` | Points written to ledger (every event) |
| `points_revoked` | Admin revoked a point row |
| `badge_awarded` | User earned a badge |
| `level_changed` | User crossed a level threshold |
| `streak_milestone` | User hit a streak milestone (7d/30d/etc.) |
| `streak_broken` | User's streak reset |
| `kudos_given` | Peer-to-peer kudos transaction |
| `challenge_completed` | Individual challenge target reached |
| `community_challenge_completed` | Community-wide challenge completed |
| `personal_record` | User hit a new personal best |
| `cosmetic_granted` | Cosmetic frame/decoration awarded |

Full list in `audit/manifest.json#/hooks_fired` (43 actions total — webhooks dispatch a subset of customer-relevant ones).

## Payload shape

```json
{
  "event":       "points_awarded",
  "timestamp":   "2026-05-02T14:32:18Z",
  "site_id":     "primary",
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "user_id":     42,
  "data": {
    "points":   25,
    "reason":   "wp_publish_post",
    "event_id": "9c2a1b... (the originating wb_gam_events row)"
  }
}
```

`delivery_id` is a UUID per delivery attempt — use it for dedup if your downstream is not idempotent. Retries reuse the same `delivery_id`.

## Signature verification

If you set a webhook secret in the admin UI, every POST gets an HMAC signature header:

```
X-WB-Gam-Signature: sha256=abc123def456...
```

Verify by computing `hmac_sha256(secret, raw_request_body)` and comparing in constant time. The Express example does this; do NOT skip in production.

## Recommended response

```
HTTP 200 OK
{ "received": true }
```

WB Gamification cares about the status code, not the body. Anything 2xx = success. Anything else = retry.

**Don't block on slow downstream calls** — return fast and process asynchronously (e.g. push to a queue, return 200, work the queue separately). The dispatcher's default timeout is 30 seconds; exceeding it counts as failure.

## Idempotency

`delivery_id` is your retry-safety key. If your handler is not idempotent (e.g. it grants a separate internal reward per call), de-dup on `delivery_id` before processing. The `wb_gam_webhook_log_*` options table tracks delivery state but only WB-side; your downstream should track its own.

## Testing locally

1. Run `node express-server.js` with `PORT=3000`.
2. Use `ngrok http 3000` to expose to the internet (or use a local-network IP if WB Gamification runs on the same LAN).
3. Add the ngrok URL as a webhook in WP Admin → Gamification → Webhooks, subscribed to `points_awarded`.
4. Trigger an event: `wp wb-gamification points award --user=42 --points=10`.
5. Watch the Express server log the inbound POST.

## What if you don't want PHP at all?

This is the recommended integration path for **all** non-PHP integrations. Slack / Discord / Zapier / Make / n8n all accept incoming webhooks directly — point the WB Gamification webhook at the platform's URL and skip the Express middleman entirely.

Example direct Slack subscription:

1. Create a Slack incoming webhook → get a URL like `https://hooks.slack.com/services/T00000000/B00000000/XXX`.
2. In WP Admin → Gamification → Webhooks, create a webhook with that URL, subscribed to `badge_awarded`.
3. Each badge unlock posts a JSON payload to Slack — Slack will treat it as a message.

(Slack's webhook expects a specific payload shape, so you'll either want a small middleman like `express-server.js` to reshape the payload, or use a Slack Workflow to handle the parse on Slack's side.)

## Related

- Firing events INTO WB Gamification → [Example 03](../03-rest-events-from-mobile/)
- Reacting to events in PHP (same plugin install) → use `add_action( 'wb_gamification_points_awarded', ... )` directly; no webhook needed.
