# Redemption Store Block

The Redemption Store block is a member-facing rewards catalog. It lists your active redemption items with their point cost, stock level, and a Redeem button, and can show the member's current balance.

## Add it to a page

In the block editor, search for "Redemption Store" and place it on a dedicated rewards page. It is found under the WB Gamification category.

Prefer a shortcode?

```
[wb_gam_redemption_store]
[wb_gam_redemption_store columns="3" limit="0" show_balance="true"]
```

Pair it with the member rewards history shortcode so members can see what they have already redeemed:

```
[wb_gam_my_rewards limit="10" show_status="true"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| Columns | Number of columns in the rewards grid (1 to 4). | 3 |
| Limit | Maximum number of items to show. 0 shows all active items. | 0 (all) |
| Show balance | Show the member's current point balance above the grid. | On |
| Show stock | Show how many of each reward remain. | On |
| Button label | Custom text for the redeem button. Leave empty for the default. | empty |
| Empty message | Message shown when no rewards are available. Leave empty for the default. | empty |
| Point type | Which currency the prices and balance use on multi-currency sites. | empty |

## Tips

- Give the page a clear title like "Rewards Store" so members know what they are spending points on.
- Use the empty message field to point members to how they can earn more points.
- On multi-currency sites, set the point type so the prices match the currency members are spending.

## See also

- [Redemption store](../features/12-redemption-store.md)
- [Multi-currency points](../features/02-multi-currency-points.md)
- [Blocks and shortcodes overview](01-blocks-overview.md)
