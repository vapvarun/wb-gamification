# Streak Block

The Streak block shows a member how many days in a row they have stayed active, and optionally their all-time longest streak and a GitHub-style activity heatmap. Streaks encourage members to come back every day.

## Add it to a page

In the block editor, click `+`, search for "Streak", and insert the **Streak** block.

Prefer a shortcode? Use:

```text
[wb_gam_streak]
[wb_gam_streak show_longest="1" show_heatmap="1" heatmap_days="90"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| User ID | Whose streak to show. Leave at 0 for the logged-in member. | 0 |
| Show longest | Also show the member's all-time longest streak. | on |
| Show heatmap | Show a contribution heatmap of recent activity. | off |
| Heatmap days | How many days of history the heatmap covers (1-365). | 90 |

## Tips

- Turn on the heatmap on a profile page to give members a satisfying visual record of their consistency.
- Keep heatmap days around 90 for a clean grid; larger ranges can get crowded on mobile.

## See also

- [Streaks feature](../features/08-streaks.md)
- [Blocks overview](01-blocks-overview.md)
