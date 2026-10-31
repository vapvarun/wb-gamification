# WB Listora: QA checklist

5 actions. Integration file: `integrations/wb-listora.php`. Code checked against 2.1.0.

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

### Submit a listing (`listora_listing_submitted`)

Awarded to the owner when a listing is submitted. Cooldown prevents bulk-submit farming.

Default: **10 points**. Limit: once per item, 60s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.
- [ ] Saving a draft pays nothing. Submitting it pays once.

Developer result: Fixed: saving a draft paid, then the submit paid again. Now once per listing, drafts pay nothing. Run on a test site through the plugin's REST route.

### Get a listing approved (`listora_listing_published`)

Awarded to the owner when their listing is approved and goes live.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Fixed: deactivating and reactivating paid again. Now once per listing. Run on a test site through the plugin's REST route.

### Write a review (`listora_review_written`)

Awarded to the reviewer when they leave a listing review. Cooldown limits review farming.

Default: **8 points**. Limit: 300s wait.

- [ ] Doing it pays 8 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Claim a listing (`listora_claim_approved`)

Awarded to the member when their listing claim is approved.

Default: **15 points**. No limit by default.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Renew a listing (`listora_listing_renewed`)

Awarded to the owner when they renew an expiring or expired listing.

Default: **3 points**. No limit by default.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.
