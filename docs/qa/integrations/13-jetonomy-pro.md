# Jetonomy Pro: QA checklist

6 actions. Integration file: `integrations/jetonomy-pro.php`. Code checked against 2.0.7.

**Setup:** Needs Jetonomy.

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

### Create a poll (`jetonomy_pro_poll_created`)

Awarded when a member creates a Jetonomy poll on a post.

Default: **10 points**. Limit: 60s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Vote on a poll (`jetonomy_pro_poll_voted`)

Awarded when a member casts a vote on a Jetonomy poll.

Default: **2 points**. Limit: 30s wait.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Send a Jetonomy private message (`jetonomy_pro_message_sent`)

Awarded when a member sends a Jetonomy private message. Daily cap blocks DM-farming.

Default: **2 points**. Limit: 60s wait, 20 a day.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 20 in one day.
- [ ] KNOWN ISSUE: leaving a conversation also pays this, because Jetonomy Pro fires the hook for its system message. Confirm it and comment on the card.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Start a Jetonomy conversation (`jetonomy_pro_conversation_created`)

Awarded once per conversation when a member opens a new Jetonomy DM thread.

Default: **5 points**. Limit: 120s wait.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 120 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Receive a Jetonomy private message (`jetonomy_pro_dm_received`)

Awarded to the recipient when a Jetonomy private message is delivered. Daily cap protects against spam-DM gaming.

Default: **1 points**. Limit: 120s wait, 10 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 120 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 10 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Send a reaction (`jetonomy_pro_reaction_added`)

Awarded when a member adds a reaction to a post or reply. Removing a reaction does not award.

Default: **1 points**. Limit: 30s wait, 50 a day.

- [ ] Doing it pays 1 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 50 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.
