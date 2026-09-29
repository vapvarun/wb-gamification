# WP Sell Services Integration

The WP Sell Services integration rewards both sides of a marketplace order. The manifest loads automatically when WP Sell Services is active.

## Actions

| Action ID | Label | Paid to | Default Points | Notes |
|---|---|---|---|---|
| `wpss_order_completed` | Complete a service order | The seller | 20 | Once per completed order |
| `wpss_review_created` | Review a service | The buyer | 10 | 60s cooldown |

### Notes

- **No self-dealing.** A seller who orders their own service, or reviews their own order, earns nothing.
- **Reviews held for moderation earn nothing.** Only a review that is published when it is created is rewarded. If your store moderates every review, `wpss_review_created` will not pay; award those by hand or from your own `wpss_review_moderated` listener.
- **Reopened orders do not pay twice.** WP Sell Services does not fire the completion hook again when a dispute restores an order.
- Change the points, cooldown and caps under **Gamification > Settings > Points**.

## Requirements

- WP Sell Services active (the integration detects `WPSS_VERSION`)
