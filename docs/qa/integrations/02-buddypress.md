# BuddyPress: QA checklist

13 actions. Integration file: `integrations/buddypress.php`. Code checked against 14.5.4.

**Setup:** Reactions need the BuddyPress Reactions add-on, which only stays active on the BuddyX, BuddyX Pro or Reign theme. Polls need the BuddyPress Polls add-on. Member blog needs BP Member Blog.

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

### Post an activity update (`bp_activity_update`)

Awarded when a member posts an activity update.

Default: **10 points**. Limit: 30s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Comment on an activity (`bp_activity_comment`)

Awarded when a member comments on an activity update.

Default: **5 points**. Limit: 30s wait.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Accept a friendship (`bp_friends_accepted`)

Awarded to the member who accepts a friendship request.

Default: **8 points**. No limit by default.

- [ ] Doing it pays 8 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Join a group (`bp_groups_join`)

Awarded when a member joins a BuddyPress group.

Default: **8 points**. No limit by default.

- [ ] Doing it pays 8 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Create a group (`bp_groups_create`)

Awarded when a member creates a new BuddyPress group.

Default: **20 points**. No limit by default.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete extended profile (`bp_profile_complete`)

Awarded once when a member saves their extended BuddyPress profile.

Default: **15 points**. Limit: once ever.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Receive a reaction (`bp_reactions_received`)

Awarded when another member reacts to their content.

Default: **3 points**. Limit: once per item.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] The content owner gets the points, not the member reacting.
- [ ] Reacting to your own content pays nothing.
- [ ] Changing the emoji pays nothing.
- [ ] Removing a reaction and reacting again pays nothing.
- [ ] A second member reacting to the same item pays again.

Developer result: Fixed: the old hook never fired. All these cases ran through the add-on's real AJAX handlers on the BuddyX theme. Clicking the reaction button in the browser not run.

### Create a poll (`bp_polls_created`)

Awarded when a member creates a poll with BuddyPress Polls.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Poll posted in the activity feed pays, also inside a group.
- [ ] Poll page: a draft pays nothing, publishing pays, editing pays nothing.
- [ ] A normal activity update does not pay this.

Developer result: Fixed: the old hook never fired. Poll page tested through WordPress, and the feed poll through the add-on's real type switch. Posting a poll from the feed form in the browser not run.

### Publish a member blog post (`bp_publish_post`)

Awarded when a member publishes a post via BP Member Blog.

Default: **25 points**. Limit: 600s wait, 5 a day.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 600 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 5 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Upload profile photo (`bp_avatar_upload`)

Awarded the first time a member uploads a profile avatar.

Default: **10 points**. Limit: once ever.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Upload cover photo (`bp_cover_upload`)

Awarded the first time a member uploads a profile cover image.

Default: **10 points**. Limit: once ever.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Upload group cover photo (`bp_group_cover_upload`)

Awarded once per group when an admin uploads a cover image for a group.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Uploading again to the same group pays nothing. Another group pays.
- [ ] The member uploading gets the points.

Developer result: Fixed: the description says once per group but every upload paid. Not run after the change.

### Send a private message (`bp_message_sent`)

Awarded for sending a 1:1 message. Cooldown applies to limit spam.

Default: **3 points**. Limit: 60s wait.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
