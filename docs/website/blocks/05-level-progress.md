# Level Progress Block

The Level Progress block gives a focused view of a member's current level: the level icon, a progress bar, and how many points are needed to reach the next level. It is a great companion to the Member Points block.

## Add it to a page

In the block editor, click `+`, search for "Level Progress", and insert the **Level Progress** block.

Prefer a shortcode? Use:

```text
[wb_gam_level_progress]
[wb_gam_level_progress show_next_level="1" show_icon="1"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| User ID | Whose level to show. Leave at 0 for the logged-in member. | 0 |
| Show progress bar | Show the bar tracking progress to the next level. | on |
| Show next level | Show the name and point threshold of the next level. | on |
| Show icon | Show the current level icon. | on |
| Point type | Which currency to base the level on for multi-currency sites. | (default) |

## Tips

- Pair this with Member Points for a complete "where am I and what's next" snapshot.
- Keep Show next level on so members always have a clear goal in front of them.

## See also

- [Levels feature](../features/05-levels.md)
- [Blocks overview](01-blocks-overview.md)
