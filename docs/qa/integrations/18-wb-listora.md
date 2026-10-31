# WB Listora: QA checklist

6 actions. Integration file: `integrations/wb-listora.php`. Code checked against 2.1.0.

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

Default: **10 points**. Limit: 60s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Get a listing approved (`listora_listing_published`)

Awarded to the owner when their listing is approved and goes live.

Default: **10 points**. No limit by default.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Write a review (`listora_review_written`)

Awarded to the reviewer when they leave a listing review. Cooldown limits review farming.

Default: **8 points**. Limit: 300s wait.

- [ ] Doing it pays 8 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Favourite a listing (`listora_favorite_added`)

Awarded to the member who favourites a listing. Daily cap prevents farming.

Default: **2 points**. Limit: 10 a day.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Claim a listing (`listora_claim_approved`)

Awarded to the member when their listing claim is approved.

Default: **15 points**. No limit by default.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Renew a listing (`listora_listing_renewed`)

Awarded to the owner when they renew an expiring or expired listing.

Default: **3 points**. No limit by default.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
