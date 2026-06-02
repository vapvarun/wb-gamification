# Realtime and Notification Settings

Go to **WB Gamification > Settings > Realtime** in your admin sidebar.

This section controls how live reward feedback reaches members - the transport that delivers updates, and where on screen reward toasts appear.

## Toast Position

**Default: Bottom right (recommended)**

Reward toasts (points, badges, kudos) slide in from the corner you pick here. The toast is corner-aware, so a bottom corner slides up and a top corner slides down.

| Option | Stored value |
|---|---|
| Bottom right (recommended) | `bottom-right` |
| Bottom left | `bottom-left` |
| Top right | `top-right` |
| Top center | `top-center` |

Bottom right is recommended because it never overlaps your theme header or navigation. Choose a top position only if a chat or support widget already sits in the bottom corner.

The selection is stored in the `wb_gam_toast_position` option. Developers can override it per request with the `wb_gam_toast_position` filter - see the [Filters reference](../developer-guide/14-filters-reference.md).

## Realtime Transport

**Default: Heartbeat**

The transport decides how the browser receives live updates. WP Heartbeat is the shipped default and is right for most sites - it polls on a shared, throttled tick (15s steady, a 5s burst for ~30s after a member action, near-suspend on hidden tabs).

| Option | When to use |
|---|---|
| Heartbeat | Default. Works on every host. |
| SSE | Server-Sent Events stream for sub-second toasts. Opt-in: it also requires the `wb_gam_sse_allowed` filter to return `true` and a host built for long-lived connections. |
| Auto | Client tries SSE first (when permitted) and falls back to Heartbeat. |

SSE is gated because its long-poll pins one PHP-FPM worker per connected member, which does not scale on a standard pool. Full transport detail, host requirements, and the `wb_gam_sse_allowed` filter are in the developer guide: [Realtime transport](../developer-guide/09-realtime-transport.md).

The transport can also be set with WP-CLI:

```bash
wp option get wb_gam_realtime_transport      # default: heartbeat
wp option update wb_gam_realtime_transport auto
```
