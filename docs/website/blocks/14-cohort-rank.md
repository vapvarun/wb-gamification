# Cohort Rank Block

The Cohort Rank block shows the current member's standing in their cohort league: their tier, their rank within the cohort, and the points they have earned this week.

## Add it to a page

In the block editor, search for "Cohort Rank" and place it on a member dashboard or profile page. It is found under the WB Gamification category.

Prefer a shortcode?

```
[wb_gam_cohort_rank]
[wb_gam_cohort_rank limit="5"]
```

By default the block shows the logged-in member's cohort standing.

## Settings

| Setting | What it does | Default |
|---|---|---|
| User ID | Whose cohort standing to show. Leave at 0 for the logged-in member. | 0 |
| Limit | How many nearby members to list around the member's rank. | 5 |
| Point type | Which currency the standing is based on for multi-currency sites. | empty |

## Tips

- Cohort leagues group members who joined around the same time, so this works best once leagues are set up in your gamification settings.
- The tier accent (Bronze, Silver, Gold, and higher) is colored automatically to match the member's current tier.
- Place it near a leaderboard so members see both their global rank and their fairer cohort rank.

## See also

- [Cohort leagues](../features/11-cohort-leagues.md)
- [Leaderboard](../features/10-leaderboard.md)
- [Blocks and shortcodes overview](01-blocks-overview.md)
