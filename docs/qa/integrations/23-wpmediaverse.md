# WPMediaVerse: QA checklist

15 actions. Integration file: `integrations/wpmediaverse.php`.

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

### Upload a photo (`mvs_upload_photo`)

Awarded when a member uploads a photo or video.

Default: **10 points**. Limit: 5s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 5 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Add items to an album (`mvs_create_album`)

Awarded when a member adds media to an album (rewards the actor, not just the album owner).

Default: **15 points**. Limit: 10s wait.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 10 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Receive a like on a photo (`mvs_receive_like`)

Awarded to the media owner when someone likes their photo.

Default: **2 points**. No limit by default.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Receive a comment on a photo (`mvs_receive_comment`)

Awarded to the media owner when someone comments on their photo.

Default: **5 points**. No limit by default.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Gain a new follower (`mvs_receive_follow`)

Awarded when another member follows you.

Default: **3 points**. No limit by default.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Have a photo bookmarked (`mvs_receive_favorite`)

Awarded to the media owner when someone bookmarks their photo.

Default: **2 points**. No limit by default.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Write a meaningful comment (`mvs_give_comment`)

Awarded when a member leaves a comment of 20+ characters.

Default: **3 points**. Limit: 30s wait, 20 a day.

- [ ] Doing it pays 3 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 20 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Follow another member (`mvs_give_follow`)

Awarded when a member follows another user.

Default: **1 points**. Limit: 50 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 50 in one day.

Developer result: Its cap of 50 was not reached because the REST rate limit stopped the run at 30. Run on a test site through the plugin's REST route.

### Bookmark a photo (`mvs_bookmark_photo`)

Awarded when a member saves a photo to favorites.

Default: **1 points**. Limit: 30 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 30 in one day.

Developer result: Its cap of 30 was not run. Run on a test site through the plugin's REST route.

### Win a photo battle (`mvs_battle_win`)

Awarded to the winner of a 1v1 photo battle.

Default: **100 points**. No limit by default.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: One first run paid nothing and clean reruns paid; cause not found. Run on a test site through the plugin's own code.

### Enter a photo challenge (`mvs_challenge_participate`)

Awarded when a member submits an entry to a photo challenge.

Default: **10 points**. No limit by default.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Place in a photo challenge (`mvs_challenge_winner`)

Awarded to the top-3 finishers of a photo challenge (200/100/50 points for 1st/2nd/3rd).

Default: **200 points**. No limit by default.

- [ ] Doing it pays 200 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's own code. Paid the right member.

### Win a tournament round (`mvs_tournament_round_win`)

Awarded for winning a round in a photo tournament.

Default: **150 points**. No limit by default.

- [ ] Doing it pays 150 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Tournament winners came from the tie rule, because test votes returned 400. Run on a test site through the plugin's own code.

### Win a tournament (`mvs_tournament_win`)

Awarded to the grand champion of a photo tournament.

Default: **500 points**. No limit by default.

- [ ] Doing it pays 500 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Tournament winners came from the tie rule, because test votes returned 400. Run on a test site through the plugin's own code.

### Hit an upload streak milestone (`mvs_streak_milestone`)

Awarded when a member hits 7, 30, 100, or 365 consecutive upload days.

Default: **50 points**. No limit by default.

- [ ] Doing it pays 50 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: One day-30 first run paid nothing and clean reruns paid; cause not found. Pays 50, 250 or 1000 by milestone, not a flat amount. Run on a test site through the plugin's REST route.
