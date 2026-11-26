---
journey: forum-points-owner-controlled
plugin: wb-gamification
priority: high
roles: [administrator, subscriber]
covers: [jetonomy-topic-award, jetonomy-reply-award, owner-amount, owner-off, owner-daily-cap, reply-cooldown, edit-move-no-award, delete-reverses-once, permanent-delete-no-second, penalty-floor-zero, restore-gives-back, re-approve-no-award, excluded-member]
prerequisites:
  - "BuddyNext + Jetonomy active, Reign 8.1.2"
  - "Settings > Points: Jetonomy group, Started a forum topic and Replied in the forum on (Post removed by a moderator on for steps 8-9)"
  - "Members in a forum space: one with a long history, one with under 20 points"
estimated_runtime_minutes: 20
---

# Forum topics and replies earn points the owner controls (1.6.9)

Card 10534617034. Forum points used to be a fixed copy of Jetonomy's reputation number: always on,
no cooldown or cap, not on the Actions screen, and a deleted topic kept its points. Each forum event
is now an owner-controlled action with its exit declared. Starting limits: topic 5 a day; reply 20 a
day and 30 seconds apart.

Ledger check after each step:
`SELECT action_id, points, object_id FROM wp_wb_gam_points WHERE user_id = <id> ORDER BY id DESC LIMIT 5`

## Steps

### 1. Topic pays the default once
- **Action**: as the member, start a topic.
- **Expect**: one `jetonomy_post_created` row, +2 (what Jetonomy pays), object id set; the REST response carries `Server-Timing: wbgam-toast` and the points toast shows.

### 2. Edit and move pay nothing
- **Action**: edit the topic; a moderator moves it to another space and back.
- **Expect**: still one award row.

### 3. Reply and its cooldown
- **Action**: reply to another member's topic twice within 30 seconds.
- **Expect**: first +1, second nothing (silent: skip toasts are off by default, `wb_gam_award_skip_toast_reasons`).

### 4. Owner changes the amount
- **Action**: Settings > Points, set Started a forum topic to 4, save; start a topic.
- **Expect**: +4.

### 5. Owner switches it off
- **Action**: switch Started a forum topic off, save; start a topic.
- **Expect**: no row, no toast. Switch it back on and reset the amount.

### 6. Delete takes back exactly what it paid, once
- **Action**: the author deletes the +4 topic; deletes it again; an admin then deletes it permanently.
- **Expect**: one `jetonomy_post_created_removed` row of -4 (what it paid, not today's amount); the second delete is refused (409); the permanent delete adds nothing.

### 7. Owner daily cap
- **Action**: set the topic daily cap to 1 (the field saves on leaving it); a member with no topics today starts two.
- **Expect**: first +2, second nothing although Jetonomy allows it. Restore the cap.
- **Note**: Jetonomy has its own per-trust-level daily topic allowance (429); a member at that allowance never reaches this cap.

### 8. A removal never takes a balance below zero
- **Action**: a member with 4 points starts a topic (6); a moderator trashes it.
- **Expect**: -2 (`jetonomy_post_created_removed`), then the penalty takes only what is left: -4 (`jetonomy_post_removed`). Balance 0.

### 9. Restore gives back exactly what left; re-approve pays nothing
- **Action**: the moderator approves the topic; approves it again.
- **Expect**: +4 (`jetonomy_post_removed_removed`) and +2 (`jetonomy_post_created_restored`), balance 6; the second approve is refused (409) and adds nothing.

### 10. Excluded member earns nothing
- **Action**: exclude the member (Access settings); they reply.
- **Expect**: reply posts, no award row.

### 11. Screens
- **Action**: Settings > Points (Jetonomy group) and the member's Activity (points history) at 1280 and 390, light and dark.
- **Expect**: forum rows editable with clear labels; history shows "Started a forum topic (content removed)".

## Pass criteria

1. Every number is the owner's: amount, on/off, cooldown and caps take effect on the next award.
2. One award per topic or reply; edit, move and re-approve pay nothing.
3. Removal takes back exactly what was paid, once, never below zero; restore gives exactly that back.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Topic paid twice | second path (reputation mirror) not standing aside | `src/Integrations/Jetonomy/JetonomyIntegration.php` |
| Delete takes today's amount, not what was paid | reversal reads the setting | `PointsEngine::reverse_object_awards()` |
| Penalty drives the balance negative | penalty not capped | `PointsEngine` penalty path |
| Cap or cooldown ignored | defaults missing | `integrations/jetonomy.php` (daily_cap, cooldown) |
