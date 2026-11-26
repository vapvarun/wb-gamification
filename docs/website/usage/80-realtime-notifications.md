# Realtime and Notification Settings

Go to **Gamification > Experience > Notifications** in your admin sidebar.

This section controls how live reward feedback reaches members - the transport that delivers updates, and where on screen reward toasts appear.

## Toast Position

**Default: Bottom center (recommended)**

Reward toasts (points, badges, kudos) appear where you pick here. A bottom position slides up and a top position slides down.

How toasts behave (1.7.0):

- **One toast per moment.** Awards that land within a second and a half of each other become one summary, for example "+17 Points and a new badge", with what earned it underneath and the badge's picture. The number counts up.
- **The big moment first.** A level-up (or a league promotion or community goal) shows on its own; the summary follows when it closes.
- **Never crowded.** At most two toasts show at once on a desktop and one on a phone. The rest wait their turn and are never dropped.
- **Closes by itself.** After 4 seconds, or 8 when the toast carries a button such as **See progress**. Pointing at a toast or tabbing into it pauses the timer, and **Escape** closes the newest one. Toasts wait while a popup is open or the member is typing.
- **Fits your site.** Toasts use your site's font and your theme's accent colour, are one width everywhere, and inside BuddyNext they go through BuddyNext's own toast stack.

See [Notifications](../features/160-notifications.md) for every toast and its wording.

| Option | Stored value |
|---|---|
| Bottom center (recommended) | `bottom-center` |
| Bottom right | `bottom-right` |
| Bottom left | `bottom-left` |
| Top right | `top-right` |
| Top center | `top-center` |

Bottom center is recommended because it never overlaps your theme header or navigation and matches BuddyNext. Choose a top position only if a chat or support widget already sits at the bottom.

## Celebrations

Level-ups, streak milestones, league promotions and community goals each open one full-screen card with a small confetti burst in your community's accent colour. Badges and completed challenges add a smaller burst to their toast. Confetti is off automatically for members who prefer reduced motion. To turn it off for everyone, return `none` from the `wb_gam_celebration_style` filter - see the [Filters reference](../developer-guide/130-filters-reference.md).

The selection is stored in the `wb_gam_toast_position` option. Developers can override it per request with the `wb_gam_toast_position` filter - see the [Filters reference](../developer-guide/130-filters-reference.md).

## Realtime Transport

**Default: Heartbeat**

The transport decides how the browser receives live updates. WP Heartbeat is the shipped default and is right for most sites - it polls on a shared, throttled tick (15s steady, a 5s burst for ~30s after a member action, near-suspend on hidden tabs).

| Option | When to use |
|---|---|
| Heartbeat | Default. Works on every host. |
| SSE | Server-Sent Events stream for sub-second toasts. Opt-in: it also requires the `wb_gam_sse_allowed` filter to return `true` and a host built for long-lived connections. |
| Auto | Client tries SSE first (when permitted) and falls back to Heartbeat. |

SSE is gated because its long-poll pins one PHP-FPM worker per connected member, which does not scale on a standard pool. Full transport detail, host requirements, and the `wb_gam_sse_allowed` filter are in the developer guide: [Realtime transport](../developer-guide/80-realtime-transport.md).

The transport can also be set with WP-CLI:

```bash
wp option get wb_gam_realtime_transport      # default: heartbeat
wp option update wb_gam_realtime_transport auto
```
