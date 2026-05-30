# Leaderboard Block

The Leaderboard block shows members a ranked list of who has earned the most points over a chosen time period. It also highlights the logged-in member's own rank, even when they are not in the visible top entries.

## Add it to a page

In the block editor, click `+`, search for "Leaderboard", and insert the **Gamification Leaderboard** block.

Prefer a shortcode? Use:

```text
[wb_gam_leaderboard period="all" limit="10" show_avatars="1"]
[wb_gam_leaderboard period="week" limit="5" scope_type="group" scope_id="12"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| Period | Time window to rank by: all-time, month, week, or day. | all |
| Limit | How many members to list (1-100). | 10 |
| Scope type | Set to a group to limit the board to one BuddyPress group. | (empty, site-wide) |
| Scope ID | The BuddyPress group ID when scope type is a group. | 0 |
| Show avatars | Show or hide member profile photos. | on |
| Point type | Which currency to rank by on multi-currency sites. | (default) |

## Tips

- Use a weekly period to keep the competition fresh and give newer members a chance to appear.
- Scope to a group to run separate leaderboards for different teams or communities.

## See also

- [Leaderboard feature](../features/10-leaderboard.md)
- [Blocks overview](01-blocks-overview.md)
