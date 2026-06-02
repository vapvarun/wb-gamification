# Realtime transport - Heartbeat (default) vs Server-Sent Events

WB Gamification ships two realtime transports for toast notifications,
live leaderboards, and other "push" events. **WP Heartbeat is the
shipped default and the only transport most sites should use.** Server-Sent
Events is an opt-in optimisation gated behind a filter (see below) because
the SSE long-poll pins one PHP-FPM worker per connected member, which does
not scale on a standard pool.

The transport is selected by the `wb_gam_realtime_transport` site option,
but SSE only actually runs when the `wb_gam_sse_allowed` filter also
returns `true`.

```bash
wp option get wb_gam_realtime_transport      # default: heartbeat
wp option update wb_gam_realtime_transport auto
```

| Value | What happens |
|---|---|
| `heartbeat` | WP Heartbeat poll. Works everywhere. **Default.** |
| `sse` | Server-Sent Events stream. Sub-second receiver-side latency. Only runs when `wb_gam_sse_allowed` returns `true` and the host supports it (see below); otherwise falls back to heartbeat. |
| `auto` | Client tries SSE first (when `wb_gam_sse_allowed` permits it), falls back to heartbeat on connection error. |

## Heartbeat intervals

The Heartbeat transport (`assets/js/heartbeat.js`) adapts its polling rate
so it feels live without flooding `admin-ajax.php`:

| State | Interval | Why |
|---|---|---|
| Steady | 15s (`standard`) | One shared, throttled tick. Realtime feedback rarely matters between actions. |
| Burst | 5s (`fast`) for ~30s | Triggered right after the member takes a point-earning action, then eases back to steady. |
| Hidden tab | 120s | Near-suspend when the tab is backgrounded so a left-open tab stops costing ticks. |

Override the steady-state interval client-side by setting
`window.wbGamRealtimeInterval` to a Heartbeat speed string (`'standard'`,
`'fast'`, or a number of seconds) before `heartbeat.js` runs:

```html
<script>window.wbGamRealtimeInterval = 'standard';</script>
```

## Enabling SSE

SSE is **off by default**. To turn it on you need BOTH:

1. The transport option set to `sse` or `auto`, AND
2. The `wb_gam_sse_allowed` filter returning `true`:

```php
// Only enable on infrastructure provisioned for long-lived streaming
// (dedicated worker pool, no proxy buffering).
add_filter( 'wb_gam_sse_allowed', '__return_true' );
```

When `wb_gam_sse_allowed` is `false` (the default), the stream endpoint
declines and clients run on WP Heartbeat instead.

## When to enable SSE / auto

- **Cross-user notifications matter** — e.g. "Alice gave Bob kudos"
  should show on Bob's screen in <1 second, not on the next heartbeat tick.
- **Your host supports long-polling PHP** — shared cPanel without
  PHP-FPM tuning usually doesn't. Managed WordPress hosts (Kinsta,
  WP Engine, Pressable) with a dedicated worker pool typically do.
- **No layer-7 proxy is buffering responses** — see "Host requirements"
  below.

For most installs `heartbeat` is the right choice. SSE is an
optimisation for community-heavy sites on infrastructure built for
long-lived connections, where the sub-second receiver toast measurably
improves engagement.

## Host requirements (when using `sse` or `auto`)

Three host-level configurations can silently break SSE. The transport
controller emits the correct headers but downstream proxies may buffer.

### nginx

Add to the site's location block (or the entire server block):

```nginx
location /wp-json/wb-gamification/v1/events/stream {
    proxy_buffering off;
    proxy_cache off;
    proxy_set_header X-Accel-Buffering no;
}
```

The plugin sends `X-Accel-Buffering: no` as a response header, which
nginx honours when proxying — but it doesn't help if nginx is the
ORIGIN. The above location block ensures buffering is off either way.

### Cloudflare

Cloudflare will hold response bytes until the connection closes if
"Auto Minify" is enabled for the stream URL. Either:

1. Disable Auto Minify globally (Speed → Optimization → Auto Minify
   → uncheck HTML), OR
2. Add a Page Rule for
   `*/wp-json/wb-gamification/v1/events/stream*` with Cache Level
   set to "Bypass" and Auto Minify disabled.

### PHP-FPM

The controller calls `session_write_close()` before the long-polling
loop to release the session lock. If your host has aggressive
output buffering enabled in php.ini (`output_buffering = On` with a
non-zero buffer), the response can stall. The controller drains
all buffers with `ob_end_flush()` + `ob_implicit_flush(true)` —
but if buffering is enabled at the FastCGI level, drop a `.htaccess`
or `php.ini` directive:

```ini
output_buffering = Off
```

## Verifying SSE works on your install

Open the browser DevTools Network tab on any frontend page where the
toast renderer is loaded. Look for a request to
`/wp-json/wb-gamification/v1/events/stream`.

**Healthy SSE:**
- Status: 200
- Type: `eventsource`
- Time: pending (connection stays open)
- Response: bytes streaming in, including `: keepalive` comments every
  2 seconds

**Broken SSE — fallback active:**
- Status: 503 with `Retry-After` header → transport flag is set to
  `heartbeat`, no SSE attempted. Working as designed.
- Status: 200 but EventSource emits an error within 10 seconds →
  one of the host issues above. Browser auto-falls back to heartbeat;
  check the Network tab for `wp-admin/admin-ajax.php?action=heartbeat`
  requests appearing instead.

## What about WebSockets?

We don't ship a WebSocket transport. Reasons documented in
[`plan/REAL-TIME-TRANSPORT.md`](../../../plan/REAL-TIME-TRANSPORT.md)
§ "Why SSE, not WebSockets". TL;DR: nothing in this plugin needs
client→server messaging that REST + ping() can't already handle,
and WebSocket adds two layers of host compatibility friction (mod
proxy wstunnel, sticky sessions) that SSE doesn't.

## Filters

```php
// Gate the SSE long-poll transport (default false). SSE only runs when
// this returns true AND the transport option is 'sse' or 'auto'.
add_filter( 'wb_gam_sse_allowed', '__return_true' );
```

The steady-state Heartbeat polling interval is **not** a PHP filter - set
the `window.wbGamRealtimeInterval` JavaScript global (see "Heartbeat
intervals" above) to change it. The default is `'standard'` (15s) with an
automatic 5s burst for ~30s after each member action.

See the [Filters reference](14-filters-reference.md) for `wb_gam_sse_allowed`
and the related toast-placement filter `wb_gam_toast_position`.
