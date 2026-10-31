# WordPress core: QA checklist

8 actions. Integration file: `integrations/wordpress.php`. Code checked against always on.

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

### Join the site (`wp_user_register`)

Awarded once when a user registers.

Default: **15 points**. Limit: once ever.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Log in for the first time (`wp_first_login`)

Awarded once on the very first login.

Default: **10 points**. Limit: once ever.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete WordPress profile (`wp_profile_complete`)

Awarded once when the user saves their WP profile with a bio.

Default: **10 points**. Limit: once ever.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.
- [ ] Saving a bio for the first time pays straight away, not on the next save.
- [ ] Saving with an empty bio pays nothing, including just after clearing a bio.

Developer result: Fixed: the old hook ran before WordPress saved the bio, so a first bio paid nothing and a cleared bio could pay. Tested through wp_update_user.

### Receive a comment on a post (`wp_post_receives_comment`)

Post author earns points when a comment on their content is approved.

Default: **3 points**. Limit: once per item, 10 a day.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] It stops paying after 10 in one day.
- [ ] A comment held for moderation pays the post author when it is approved.
- [ ] Unapproving and approving it again pays nothing more.
- [ ] Commenting on your own post pays nothing.

Developer result: Fixed: a comment held for moderation never paid. Tested through WordPress comment functions, held then approved, once.

### Publish a blog post (`wp_publish_post`)

Awarded when the author publishes a new post - once per transition into the published state. Editing an already-published post does NOT re-award.

Default: **25 points**. No limit by default.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Publish your first post (`wp_first_post`)

Awarded once on the author's very first transition-to-publish for a post.

Default: **20 points**. Limit: once ever.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Leave a comment (`wp_leave_comment`)

Commenter earns points when their approved comment is posted.

Default: **5 points**. Limit: 60s wait.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Get a comment approved from moderation (`wp_comment_approved`)

Awarded when a previously pending comment gets approved.

Default: **5 points**. No limit by default.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
