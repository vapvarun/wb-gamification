# Points History Block

The Points History block shows a member a paginated table of their recent point transactions, with the action that earned each point and the date it happened.

## Add it to a page

In the block editor, click the inserter, search for "Points History", and place the block where you want the table to appear. It is found under the WB Gamification category.

Prefer a shortcode? Drop this into any page, post, or widget:

```
[wb_gam_points_history]
[wb_gam_points_history limit="20" show_action_label="1"]
```

By default the block shows the logged-in member's own history.

## Settings

| Setting | What it does | Default |
|---|---|---|
| User ID | Whose history to show. Leave at 0 for the logged-in member. | 0 |
| Point type | Limit the table to a single currency on multi-currency sites. Leave empty for the default currency. | empty |
| Number of rows | How many transactions to list (1 to 100). | 20 |
| Show action label | Show the human-readable action name next to each row. | On |

## Tips

- Put this on a member's "My Activity" page so they can see exactly how they earned their points.
- Lower the row count for a compact sidebar; raise it for a full history page.
- On multi-currency sites, set a point type to keep each currency on its own page.

## See also

- [Points](../features/01-points.md)
- [Multi-currency points](../features/02-multi-currency-points.md)
- [Blocks and shortcodes overview](01-blocks-overview.md)
