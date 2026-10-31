# BuddyNext: QA checklist

13 actions. Integration file: `integrations/buddynext.php`. Code checked against 1.2.7.

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

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Share a post (`bn_post_shared`)

Awarded when a member shares an existing post. Daily cap limits farming.

Default: **5 points**. Limit: 10 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Write a comment (`bn_comment_created`)

Awarded when a member comments on a post. Cooldown prevents rapid-fire farming.

Default: **3 points**. Limit: 30s wait.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Receive a reaction on your content (`bn_reaction_received`)

Awarded to the content owner when another member reacts to their post. BuddyNext only fires this for cross-user reactions (self-reactions are excluded upstream in ReactionService).

Default: **2 points**. Limit: 20 a day.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 20 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Vote on a poll (`bn_poll_voted`)

Awarded when a member votes on a poll. Daily cap prevents ballot-box farming.

Default: **1 points**. Limit: 5 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Bookmark a post (`bn_post_bookmarked`)

Awarded the first time a member bookmarks a post. Daily cap limits farming.

Default: **1 points**. Limit: once per item, 5 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] It stops paying after 5 in one day.
- [ ] Removing a bookmark and adding it again pays nothing more.

Developer result: Fixed: remove and re-add paid again. Now once per post. Run on a test site through the plugin's REST route.

### Gain a follower (`bn_followed`)

Awarded to the member who gains a new follower. Daily cap prevents follow-farming.

Default: **5 points**. Limit: 10 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Follow a member for the first time (`bn_first_follow`)

Awarded once when a member follows their first person.

Default: **5 points**. Limit: once ever.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Fails when the first follow goes through a private account approval: BuddyNext never fires the hook there. Card on the BuddyNext board. Run on a test site through the plugin's REST route.

### Have a connection request accepted (`bn_connected`)

Awarded to the member who sent a connection request when it is accepted.

Default: **10 points**. No limit by default.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] The member who sent the request gets the points, not the one accepting.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Join a space (`bn_space_joined`)

Awarded when a member joins a community space. Daily cap prevents bulk-join farming.

Default: **5 points**. Limit: 5 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Create a space (`bn_space_created`)

Awarded when a member creates a new community space.

Default: **10 points**. No limit by default.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Complete your profile (`bn_profile_completed`)

Awarded once when a member completes every task on their Profile Strength checklist.

Default: **25 points**. Limit: once ever.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Complete onboarding (`bn_onboarding_completed`)

Awarded once when a member completes the BuddyNext onboarding wizard.

Default: **20 points**. Limit: once ever.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.
