# BuddyNext: QA checklist

16 actions. Integration file: `integrations/buddynext.php`. Code checked against 1.2.7.

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

### Create a post (`bn_post_created`)

Awarded each time a member creates a post. Daily cap prevents farming.

Default: **5 points**. Limit: 30s wait, 20 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 20 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Share a post (`bn_post_shared`)

Awarded when a member shares an existing post. Daily cap limits farming.

Default: **5 points**. Limit: 10 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Write a comment (`bn_comment_created`)

Awarded when a member comments on a post. Cooldown prevents rapid-fire farming.

Default: **3 points**. Limit: 30s wait.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Receive a reaction on your content (`bn_reaction_received`)

Awarded to the content owner when another member reacts to their post. BuddyNext only fires this for cross-user reactions (self-reactions are excluded upstream in ReactionService).

Default: **2 points**. Limit: 20 a day.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 20 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Vote on a poll (`bn_poll_voted`)

Awarded when a member votes on a poll. Daily cap prevents ballot-box farming.

Default: **1 points**. Limit: 5 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Bookmark a post (`bn_post_bookmarked`)

Awarded the first time a member bookmarks a post. Daily cap limits farming.

Default: **1 points**. Limit: 5 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Gain a follower (`bn_followed`)

Awarded to the member who gains a new follower. Daily cap prevents follow-farming.

Default: **5 points**. Limit: 10 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Follow a member for the first time (`bn_first_follow`)

Awarded once when a member follows their first person.

Default: **5 points**. Limit: once ever.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Have a connection request accepted (`bn_connected`)

Awarded to the member who sent a connection request when it is accepted.

Default: **10 points**. No limit by default.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] The member who sent the request gets the points, not the one accepting.

Developer result: Relabelled from Accept a connection, because it has always paid the sender. Who gets paid did not change. Not run.

### Send a connection request (`bn_connection_requested`)

Awarded when a member sends a connection request. Daily cap prevents spam-requesting.

Default: **1 points**. Limit: 5 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Send a direct message (`bn_dm_sent`)

Awarded when a member sends a DM. Daily cap keeps points proportional to real engagement.

Default: **1 points**. Limit: 10 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Join a space (`bn_space_joined`)

Awarded when a member joins a community space. Daily cap prevents bulk-join farming.

Default: **5 points**. Limit: 5 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Create a space (`bn_space_created`)

Awarded when a member creates a new community space.

Default: **10 points**. No limit by default.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Update your profile (`bn_profile_updated`)

Awarded when a member changes their profile completion percentage.

Default: **2 points**. Limit: 300s wait.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete your profile (`bn_profile_completed`)

Awarded once when a member completes every task on their Profile Strength checklist.

Default: **25 points**. Limit: once ever.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete onboarding (`bn_onboarding_completed`)

Awarded once when a member completes the BuddyNext onboarding wizard.

Default: **20 points**. Limit: once ever.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
