# Badge Showcase Block

The Badge Showcase block displays a grid of the badges a member has earned. You can also show the badges they have not earned yet, greyed out, so members can see what is still up for grabs.

## Add it to a page

In the block editor, click `+`, search for "Badge Showcase", and insert the **Badge Showcase** block.

Prefer a shortcode? Use:

```text
[wb_gam_badge_showcase]
[wb_gam_badge_showcase show_locked="1" category="buddypress" limit="12"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| User ID | Whose badges to show. Leave at 0 for the logged-in member. | 0 |
| Show locked | Also show unearned badges greyed out. | off |
| Category | Limit to one badge category (for example, wordpress or buddypress). | (empty, all) |
| Limit | Cap how many badges to display. 0 shows all. | 0 |

## Tips

- Turn on Show locked to motivate members by revealing badges they can still earn.
- Use a category filter to build focused showcases, such as a page of community badges only.

## See also

- [Badges feature](../features/03-badges.md)
- [Blocks overview](01-blocks-overview.md)
