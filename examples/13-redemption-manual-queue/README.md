# Example 13 — Redemption fulfilment: Manual queue

For rewards that don't have an API to call — branded swag, gift cards mailed by hand, mentor calls, custom merch.

## When to use this

Most redemptions can be fulfilled programmatically (WooCommerce coupon, course unlock, group join, credit topup). But there are always a few that need a human:

- "WB Hoodie — Size M"
- "$10 Amazon gift card"
- "30-min mentoring call"
- "Custom illustration"

This listener takes over for those: it emails the site admin on every redemption AND adds a queue page in `Tools → Redemption Queue` where the admin can see what's been paid for and click "Mark fulfilled" once they've shipped it.

## How it works

1. **Admin creates a reward** with **Reward Type: `Custom Reward (fulfilled via hook)`**. No description prefix needed for this listener — it picks up *all* custom redemptions.

2. **Member redeems.** Engine debits points and parks the row at `status = 'pending_fulfillment'`.

3. **This listener** emails the site admin with the member's name, email, reward title, and a deep link to the queue page.

4. **Admin visits `Tools → Redemption Queue`**, sees a table of pending items, clicks "Mark fulfilled" once they've shipped/sent the reward.

## Files

- `your-plugin.php` — drop into `wp-content/plugins/redemption-manual-queue/` and activate.

## Why this isn't shipped as a built-in feature

Some sites would want this; others would want their tickets created in HelpScout / Zendesk / their own CRM instead. Shipping it as an example lets each install pick its own workflow without core taking on a dependency. If you want HelpScout instead of email + a queue page, fork this listener and replace the `wp_mail` + admin page with a HelpScout ticket-create call.

## Pairs well with

- A `custom` reward whose description ends in `swag` and a separate listener that opens a Shopify draft order — the engine fires the same hook for everyone, so you can stack listeners and route by description prefix.
