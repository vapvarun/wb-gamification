# Outbound Webhooks

WB Gamification can notify external services whenever gamification events occur. Configure a destination URL and subscribe to the events you care about -- the plugin sends an HMAC-signed JSON `POST` for every matching event.

Webhooks are ideal for connecting to automation platforms like **Zapier**, **Make** (Integromat), or **n8n**.

---

## Supported Event Types

| Event | Fired When | Key Data Fields |
|---|---|---|
| `points_awarded` | A user earns points from any action | `action_id`, `event_id`, `points` |
| `badge_earned` | A badge rule is satisfied and awarded | `badge_id`, `badge_name` |
| `level_changed` | A user's level increases after earning points | `new_level_id`, `new_level_name`, `old_level_id`, `old_level_name` |
| `streak_milestone` | A user hits a streak milestone (7, 14, 30... days) | `streak_days` |
| `challenge_completed` | A user finishes an individual challenge | `challenge_id`, `challenge_name` |
| `kudos_given` | A user sends peer kudos | `receiver_id`, `message` |

---

## Payload Format

Every webhook delivery is an HTTP `POST` with `Content-Type: application/json`. The body follows this structure:

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

### Example Payloads per Event Type

**badge_earned**

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

**level_changed**

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

**streak_milestone**

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

**challenge_completed**

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

**kudos_given**

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

---

## HMAC Signature Verification

Every delivery includes an `X-WB-Gam-Signature` header with the format:

```
X-WB-Gam-Signature: sha256=<hex-encoded HMAC-SHA256>
```

The HMAC is computed over the raw JSON body using the webhook secret as the key. Always verify the signature before processing the payload.

### PHP Verification

```php
$secret    = 'your_webhook_secret_here';
$payload   = file_get_contents( 'php://input' );
$header    = $_SERVER['HTTP_X_WB_GAM_SIGNATURE'] ?? '';
$expected  = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

if ( ! hash_equals( $expected, $header ) ) {
    http_response_code( 401 );
    exit( 'Invalid signature.' );
}

$data = json_decode( $payload, true );
// Process $data...
```

### Node.js Verification

```javascript
const crypto = require('crypto');

function verifyWebhook(req, secret) {
  const payload   = JSON.stringify(req.body);
  const signature = req.headers['x-wb-gam-signature'] || '';
  const expected  = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(payload, 'utf8')
    .digest('hex');

  return crypto.timingSafeEqual(
    Buffer.from(expected),
    Buffer.from(signature)
  );
}

// Express middleware example
app.post('/webhook', express.json({ verify: (req, res, buf) => {
  req.rawBody = buf;
}}), (req, res) => {
  const payload   = req.rawBody.toString('utf8');
  const signature = req.headers['x-wb-gam-signature'] || '';
  const expected  = 'sha256=' + crypto
    .createHmac('sha256', process.env.WEBHOOK_SECRET)
    .update(payload, 'utf8')
    .digest('hex');

  if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) {
    return res.status(401).send('Invalid signature');
  }

  console.log('Event:', req.body.event, 'User:', req.body.user_id);
  res.status(200).send('OK');
});
```

### Python Verification

```python
import hmac
import hashlib

def verify_webhook(body: bytes, header: str, secret: str) -> bool:
    expected = 'sha256=' + hmac.new(
        secret.encode(), body, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, header)
```

---

## Retry Behaviour

Failed deliveries (HTTP errors or status codes >= 400) are retried automatically up to **3 times** using exponential back-off:

| Attempt | Delay |
|---|---|
| 1st retry | 2 minutes |
| 2nd retry | 4 minutes |
| 3rd retry | 8 minutes |

Retries are scheduled via **Action Scheduler**, so they survive page loads and cron interruptions. After 3 failed retries the delivery is abandoned and logged.

---

## Delivery Log

Every delivery attempt (success or failure) is recorded. You can inspect the log via the REST API:

```
GET /wp-json/wb-gamification/v1/webhooks/{id}/log
```

Response:

```json
{
  "webhook_id": 1,
  "entries": [
    {
      "event": "points_awarded",
      "status_code": 200,
      "success": true,
      "timestamp": "2026-04-12 14:30:05"
    },
    {
      "event": "badge_earned",
      "status_code": 0,
      "success": false,
      "timestamp": "2026-04-12 14:29:58"
    }
  ],
  "count": 2
}
```

A `status_code` of `0` indicates a connection-level failure (DNS, timeout, etc.).

To clear the log:

```
DELETE /wp-json/wb-gamification/v1/webhooks/{id}/log
```

---

## REST API Quick Reference

All endpoints require the `manage_options` capability (admin only).

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/wb-gamification/v1/webhooks` | List all webhooks |
| `POST` | `/wb-gamification/v1/webhooks` | Register a new webhook |
| `GET` | `/wb-gamification/v1/webhooks/{id}` | Get a single webhook |
| `PUT` | `/wb-gamification/v1/webhooks/{id}` | Update a webhook |
| `DELETE` | `/wb-gamification/v1/webhooks/{id}` | Delete a webhook |
| `GET` | `/wb-gamification/v1/webhooks/{id}/log` | View delivery log |
| `DELETE` | `/wb-gamification/v1/webhooks/{id}/log` | Clear delivery log |

### Create Webhook

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/webhooks \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{
    "url": "https://hooks.zapier.com/hooks/catch/123/abc/",
    "events": ["points_awarded", "badge_earned", "level_changed"]
  }'
```

The response includes the `secret` -- store it securely. It is only returned on creation.

---

## Platform Setup Guides

### Zapier

1. Create a new Zap and choose **Webhooks by Zapier** as the trigger.
2. Select **Catch Hook** and copy the webhook URL (starts with `https://hooks.zapier.com/`).
3. In your WordPress admin, go to **Gamification > Settings > API** and register the webhook URL with your desired events.
4. Copy the returned `secret` for signature verification (optional in Zapier but recommended).
5. Send a test event from WB Gamification and check the Zap trigger for the payload.
6. Add your action steps (send email, update Google Sheet, post to Slack, etc.).

### Make (Integromat)

1. Create a new Scenario and add a **Webhooks > Custom Webhook** module.
2. Click **Add** to create a new webhook and copy the URL.
3. Register the URL in WB Gamification with the events you want.
4. Click **Run once** in Make, then trigger an event from your site.
5. Make will detect the payload structure automatically. Map the fields to your next module.
6. To verify signatures, use a **Tools > Set variable** module with `sha256=` + HMAC-SHA256 of the body, then compare with the `X-WB-Gam-Signature` header.

### n8n

1. Add a **Webhook** node to your workflow.
2. Set the HTTP Method to `POST` and copy the production webhook URL.
3. Register the URL in WB Gamification with your desired event subscriptions.
4. To verify signatures, add a **Code** node after the Webhook node:

```javascript
const crypto = require('crypto');
const secret  = $env.WEBHOOK_SECRET;
const payload = JSON.stringify($input.first().json);
const sig     = $input.first().headers['x-wb-gam-signature'];
const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(payload).digest('hex');

if (sig !== expected) {
  throw new Error('Invalid webhook signature');
}

return $input.all();
```

5. Connect subsequent nodes to process the event data (send notifications, update CRM, trigger workflows, etc.).

---

## Headers Sent

Every webhook delivery includes these custom headers:

| Header | Value | Purpose |
|---|---|---|
| `Content-Type` | `application/json` | Payload format |
| `X-WB-Gam-Signature` | `sha256=<hex>` | HMAC-SHA256 signature for verification |
| `X-WB-Gam-Site` | `https://your-site.com` | Origin site URL (useful for multi-site setups) |
