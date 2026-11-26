# WP Sell Services Integration

The WP Sell Services integration rewards both sides of a marketplace order. The manifest loads automatically when WP Sell Services is active.

## Actions

| Action ID | Label | Paid to | Default Points | Notes |
|---|---|---|---|---|
| `wpss_order_completed` | Complete a service order | The seller | 20 | Once per completed order |
| `wpss_review_created` | Review a service | The buyer | 10 | Pays when the review is published, on creation or when a moderator approves it. 60s cooldown |

### Notes

- **No self-dealing.** A seller who orders their own service, or reviews their own order, earns nothing.
- **Moderated reviews pay on approval.** With review moderation on, a review is created pending and earns nothing until a moderator approves it from the review queue. Approval pays the buyer through `wpss_review_created` (since 1.6.6; earlier versions never paid a moderated review).
- **Each review pays once.** A review published on creation is not paid a second time if a moderator later re-approves it.
- **Taking a review down takes its points back.** Rejecting, holding or deleting a published review removes the points it earned. Approving it again gives the same points back once, so approve, reject, approve leaves the buyer holding one award.
- **Reopened orders do not pay twice.** WP Sell Services does not fire the completion hook again when a dispute restores an order.
- Change the points, cooldown and caps under **Gamification > Earning > Actions and points**.

## Requirements

- WP Sell Services active (the integration detects `WPSS_VERSION`)
