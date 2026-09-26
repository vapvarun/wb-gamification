# bbPress Integration

The bbPress integration rewards forum participation. The manifest loads automatically when bbPress is active.

## Actions

| Action ID | Label | Default Points | Repeatable |
|---|---|---|---|
| `bbp_new_topic` | Create a forum topic | 10 | Yes (5min cooldown) |
| `bbp_new_reply` | Post a forum reply | 5 | Yes (60s cooldown) |
| `bbp_topic_closed` | Topic resolved / closed | 20 | Yes |

### Notes

- `bbp_topic_closed` fires when a topic is marked as **closed or resolved**. It awards points to the **topic author**, not the moderator who closed it. The action checks `$r['is_closed']` — reopening a topic does not trigger the award.
- Cooldowns on `bbp_new_topic` (5 minutes) and `bbp_new_reply` (60 seconds) prevent abuse from rapid posting.

## Relationship to BuddyPress Integration

bbPress and BuddyPress actions are separate and do not conflict. A forum reply is a different action from a BuddyPress activity update — they fire on different hooks and represent different types of community participation. You can have both integrations active at the same time.

## Requirements

- bbPress active
