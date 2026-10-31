# WooCommerce: QA checklist

5 actions. Integration file: `integrations/woocommerce.php`. Code checked against 11.2.1.

**Setup:** Wishlist needs YITH WooCommerce Wishlist. Test one online order and one offline order (cash on delivery or bank transfer) marked Completed by hand.

Tick each box when it passes. If one fails, comment on this plugin's Basecamp card with the steps, what you expected, what happened and a screenshot.

## Whole plugin

- [ ] Activate the plugin with WB Gamification. Its actions appear under **WB Gamification > Points**, grouped under the right plugin name.
- [ ] Every action below has a clear label and description, an on switch and a points amount. Repeatable actions also have cooldown, daily and weekly limits.
- [ ] Change one amount and one limit, save and reload. Both stayed.
- [ ] Points screen at 390px: nothing overflows the page.
- [ ] Do the actions as a **Subscriber or member**, not as admin.
- [ ] After testing, **Tools > Site Health** and `debug.log` show no PHP errors and no `Gamification trigger failed` lines.
- [ ] Deactivate the plugin: its actions leave the Points screen, past points stay, no PHP errors.

## Each action

For every action: do it for real, then confirm the points appear in the member's history with the default amount, on the right member. Then switch it off, repeat it and confirm no points, and switch it back on.

### Pay for an order (`wc_order_completed`)

Awarded each time a customer pays for an order (fires once per order on payment success).

Default: **25 points**. No limit by default.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Make your first purchase (`wc_first_purchase`)

Awarded once on a customer's very first paid order.

Default: **50 points**. Limit: once ever.

- [ ] Doing it pays 50 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.
- [ ] A second order pays nothing for this.
- [ ] A guest order whose billing email matches a member pays that member, only if it is their first paid order.

Developer result: Fixed: a matched guest order was counted wrongly, so a first order paid nothing and a second could pay. Tested through payment_complete on a member order, a second order and a guest order with a member's email. No real checkout in the browser.

### Leave a product review (`wc_product_reviewed`)

Awarded when a product review is approved.

Default: **15 points**. Limit: once per item, 86400s wait.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Doing it again inside 86400 seconds pays nothing. After the wait it pays.
- [ ] A review held for moderation pays when approved.
- [ ] Unapproving and approving it again pays nothing more.

Developer result: Fixed: a review held for moderation never paid, and WordPress holds a first-time commenter by default. Tested through WordPress comment functions, held then approved, once.

### Add a product to wishlist (YITH) (`wc_wishlist_add`)

Awarded when a member adds any product to their YITH wishlist.

Default: **5 points**. Limit: 300s wait.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Add a product to cart (`wc_add_to_cart`)

Awarded when a logged-in member adds a product to their cart. Cooldown limits farming.

Default: **1 points**. Limit: 300s wait.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
