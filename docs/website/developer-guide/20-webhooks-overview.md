# Outbound Webhooks Overview

WB Gamification can notify external services whenever gamification events occur. Configure a destination URL, subscribe to the events you care about, and the plugin sends an HMAC-signed JSON `POST` for every matching event.

Webhooks are ideal for connecting to automation platforms like Zapier, Make (Integromat), or n8n.

## Registering a Webhook

Webhooks are managed through the REST API under `/wp-json/wb-gamification/v1/webhooks`. All webhook endpoints require the `manage_options` capability. See [Admin REST endpoints](19-rest-admin-webhooks.md) for the full endpoint table.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/webhooks` | List all webhooks |
| `POST` | `/webhooks` | Register a new webhook |
| `GET` | `/webhooks/{id}` | Get a single webhook |
| `PUT` | `/webhooks/{id}` | Update a webhook |
| `DELETE` | `/webhooks/{id}` | Delete a webhook |
| `GET` | `/webhooks/{id}/log` | View delivery log |
| `DELETE` | `/webhooks/{id}/log` | Clear delivery log |

### Create a webhook

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

The response includes the `secret`. Store it securely. It is only returned on creation.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `url` | string | Yes | Destination URL for delivery |
| `events` | array | Yes | Event names to subscribe to |
| `secret` | string | No | Custom signing secret. Generated if omitted |

For the full list of subscribable events and their payload fields, see the [Webhook Events Reference](21-webhook-events-reference.md).

## Payload Shape

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

| Field | Type | Description |
|-------|------|-------------|
| `event` | string | The event type that fired |
| `site_url` | string | Origin site URL |
| `timestamp` | string | ISO 8601 UTC timestamp of the event |
| `user_id` | int | ID of the user the event concerns |
| `user_email` | string | Email of that user |
| `data` | object | Event-specific fields. See the events reference |

## Headers Sent

Every webhook delivery includes these custom headers:

| Header | Value | Purpose |
|--------|-------|---------|
| `Content-Type` | `application/json` | Payload format |
| `X-WB-Gam-Signature` | `sha256=<hex>` | HMAC-SHA256 signature for verification |
| `X-WB-Gam-Site` | `https://your-site.com` | Origin site URL, useful for multi-site setups |

## Signing and Verification

Every delivery includes an `X-WB-Gam-Signature` header:

```
X-WB-Gam-Signature: sha256=<hex-encoded HMAC-SHA256>
```

The HMAC is computed over the raw JSON body using the webhook secret as the key. Always verify the signature before processing the payload.

### PHP

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

### Node.js

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

### Python

```python
import hmac
import hashlib

def verify_webhook(body: bytes, header: str, secret: str) -> bool:
    expected = 'sha256=' + hmac.new(
        secret.encode(), body, hashlib.sha256
    ).hexdigest()
    return hmac.compare_digest(expected, header)
```

## Retries

Failed deliveries (HTTP errors or status codes >= 400) are retried automatically up to 3 times using exponential back-off.

| Attempt | Delay |
|---------|-------|
| 1st retry | 2 minutes |
| 2nd retry | 4 minutes |
| 3rd retry | 8 minutes |

Retries are scheduled via Action Scheduler, so they survive page loads and cron interruptions. After 3 failed retries the delivery is abandoned and logged.

## Delivery Log

Every delivery attempt (success or failure) is recorded. Inspect it via the REST API:

```bash
curl https://example.com/wp-json/wb-gamification/v1/webhooks/1/log \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

```json
{
  "webhook_id": 1,
  "entries": [
    { "event": "points_awarded", "status_code": 200, "success": true, "timestamp": "2026-04-12 14:30:05" },
    { "event": "badge_earned", "status_code": 0, "success": false, "timestamp": "2026-04-12 14:29:58" }
  ],
  "count": 2
}
```

A `status_code` of `0` indicates a connection-level failure (DNS, timeout). To clear the log:

```bash
curl -X DELETE https://example.com/wp-json/wb-gamification/v1/webhooks/1/log \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..."
```

## Platform Setup Guides

### Zapier

1. Create a new Zap and choose Webhooks by Zapier as the trigger.
2. Select Catch Hook and copy the webhook URL (starts with `https://hooks.zapier.com/`).
3. In WordPress admin, go to Gamification > Settings > API and register the webhook URL with your desired events.
4. Copy the returned `secret` for signature verification (optional in Zapier but recommended).
5. Send a test event from WB Gamification and check the Zap trigger for the payload.
6. Add your action steps (send email, update Google Sheet, post to Slack, etc.).

### Make (Integromat)

1. Create a new Scenario and add a Webhooks > Custom Webhook module.
2. Click Add to create a new webhook and copy the URL.
3. Register the URL in WB Gamification with the events you want.
4. Click Run once in Make, then trigger an event from your site.
5. Make detects the payload structure automatically. Map the fields to your next module.
6. To verify signatures, use a Tools > Set variable module with `sha256=` plus the HMAC-SHA256 of the body, then compare with the `X-WB-Gam-Signature` header.

### n8n

1. Add a Webhook node to your workflow.
2. Set the HTTP Method to `POST` and copy the production webhook URL.
3. Register the URL in WB Gamification with your desired event subscriptions.
4. To verify signatures, add a Code node after the Webhook node:

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
