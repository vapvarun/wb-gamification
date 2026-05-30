# Realtime transport — Heartbeat vs Server-Sent Events

WB Gamification ships two realtime transports for toast notifications,
live leaderboards, and other "push" events. The transport is chosen by
the `wb_gam_realtime_transport` site option.

```bash
wp option get wb_gam_realtime_transport      # default: heartbeat
wp option update wb_gam_realtime_transport auto
```

| Value | What happens |
|---|---|
| `heartbeat` | WP Heartbeat poll at 5-second intervals. Works everywhere. Default. |
| `sse` | Server-Sent Events stream. Sub-second receiver-side latency. Requires host support (see below). |
| `auto` | Client tries SSE first; falls back to heartbeat on connection error. Best of both when SSE works. |

## When to enable SSE / auto

- **Cross-user notifications matter** — e.g. "Alice gave Bob kudos"
  should show on Bob's screen in <1 second, not <5 seconds.
- **Your host supports long-polling PHP** — shared cPanel without
  PHP-FPM tuning usually doesn't. Managed WordPress hosts (Kinsta,
  WP Engine, Pressable) typically do.
- **No layer-7 proxy is buffering responses** — see "Host requirements"
  below.

For most installs `heartbeat` is the right choice. SSE is an
optimisation for community-heavy sites where the sub-second receiver
toast measurably improves engagement.

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
// Override the heartbeat polling interval (default 'fast' = 5s).
// Use 'standard' (15s) to reduce server tick load on busy installs.
add_filter( 'wb_gam_realtime_interval', static function () {
    return 'standard';
} );
```
