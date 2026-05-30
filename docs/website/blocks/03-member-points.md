# Member Points Block

The Member Points block shows a member their current points total, their level name, and a progress bar toward the next level. By default it shows the points of whoever is viewing the page.

## Add it to a page

In the block editor, click `+`, search for "Member Points", and insert the **Member Points** block.

Prefer a shortcode? Use:

```text
[wb_gam_member_points]
[wb_gam_member_points user_id="42" show_level="1" show_progress_bar="1"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| User ID | Whose points to show. Leave at 0 for the logged-in member. | 0 |
| Show level | Show the member's current level name. | on |
| Show progress bar | Show the bar tracking progress to the next level. | on |
| Point type | Which currency to display on multi-currency sites. | (default) |

## Tips

- Place this near the top of a member profile or dashboard page so points are the first thing members see.
- Leave User ID at 0 so every member sees their own total without you creating a page per person.

## See also

- [Points feature](../features/01-points.md)
- [Levels feature](../features/05-levels.md)
- [Blocks overview](01-blocks-overview.md)
