# Multi-Currency Points

WB Gamification supports multiple distinct point currencies running on the same site. Use this to model real-world economies that don't reduce to a single number — coins for in-game purchases, XP for level progress, reputation for moderation privileges, etc.

## When to Use Multiple Currencies

Single currency (the default) covers most communities. Use multiple currencies when:

- You want a **separate XP track** for level progression that's not affected by every action ("only forum participation counts toward leveling")
- You're running a **game economy** with spendable coins (redeem at the store) and unspendable rep (drives ranking)
- You want **distinct leaderboards** per currency ("Top contributors" vs "Top spenders")
- Each integration has its **own scale** ("LearnDash coursework points" vs "WooCommerce loyalty points")

## How Currencies Work

Each currency has:

- **Slug** — a short identifier (`coins`, `xp`, `reputation`)
- **Display name** — shown in member-facing UIs
- **Symbol** — optional prefix (`⭐`, `🪙`, `💎`)
- **Default flag** — exactly one currency is the site default; if action manifests don't specify, this is what they award

The default site has one currency: `points`. To add more, go to **Settings → Point Types** and create them.

Once created, you can:

- **Assign a currency to actions** — set `point_type` on the action manifest entry
- **Assign a currency to redemption rewards** — store catalogs can be currency-scoped
- **Configure conversions** — let members convert one currency to another (e.g. "10 XP = 1 coin")

## Member Surface

Members see their balances:

- **Member Hub block** — a tile per currency, with the configured symbol and label
- **Member Points block** — accepts a `type` parameter to show a specific currency
- **Leaderboard block** — same `type` parameter for currency-scoped rankings
- **Points History block** — shows the currency next to each event, color-coded

If your site uses one currency, none of this UI changes — currency labels are hidden when there's only one.

## Currency Conversions

Conversions let a member trade one currency for another. Common patterns:

- **XP → coins** — "spend your XP on store rewards"
- **Reputation → privileges** — "unlock moderation tools at 1000 rep"
- **Currency rebalancing** — admin migrates a community from `points` to `xp`

Each conversion is configured in **Settings → Conversions** with:

- Source currency + target currency
- Conversion rate (`from` per `to`, e.g., `10 → 1`)
- Whether members can self-convert or only admins
- Optional minimum balance to convert (anti-spam)

Conversions are **atomic**: the source debit and target credit happen in a single MySQL transaction with a row-level lock on the user's balance row. If the operation fails midway, both rows roll back. The same `event_id` links the two ledger rows so audit trails show the conversion as a coupled pair.

## Action Configuration

Set the currency for an action via the action manifest entry:

```php
'wp_publish_post' => array(
    'label'       => 'Publish a post',
    'category'    => 'wordpress',
    'default_points' => 10,
    'point_type'  => 'xp',  // <-- award XP, not the default currency
),
```

If `point_type` is omitted, the action awards the site default currency.

The site owner can override per-action via the Settings → Points page — change the dropdown next to each action's point value.

## Materialised Totals

Each member has a row in `wb_gam_user_totals` per currency. The leaderboard, hub, and member-points blocks read this materialised view rather than aggregating the ledger on every request — sub-100ms response even with millions of rows in `wb_gam_points`.

## Privacy

Currencies inherit the same export/erasure rules as the default points engine. All balances per currency export with the user's data and are erased on deletion.

## Configuration

Settings → Point Types and Settings → Conversions.

| Setting | Default |
|---|---|
| Currencies | `points` (default, no symbol) |
| Default currency | `points` |
| Auto-convert on redemption | Off |
| Show currency labels in single-currency mode | Off |

## Developer Integration

If your plugin awards points, declare the currency in your manifest:

```php
return array(
    'actions' => array(
        'my_action' => array(
            'label' => 'Custom action',
            'default_points' => 5,
            'point_type' => 'coins',  // optional, defaults to site default
        ),
    ),
);
```

Or, when calling the helper directly:

```php
wb_gam_award_points( $user_id, 'my_action', array(
    'point_type' => 'coins',
) );
```

See [Manifest Files](../developer-guide/manifest-files.md) for the full manifest spec.

## See Also

- **[Points](points.md)** — single-currency basics
- **[Leaderboard](leaderboard.md)** — currency-scoped rankings
- **[Redemption Store](redemption-store.md)** — currency-scoped reward catalogs
- **[Helper Functions](../developer-guide/helper-functions.md)** — `wb_gam_award_points` API
