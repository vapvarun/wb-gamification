# Jetonomy Integration

WB Gamification connects to Jetonomy automatically when Jetonomy is active. Two things happen: Jetonomy activity awards WB Gamification points, and - to avoid showing two identical leaderboards - WB Gamification defers its own leaderboard display to Jetonomy's reputation ranking.

## Forum Events Are Actions You Control

Every forum event Jetonomy scores is an action on **Earning > Actions and points**, under Jetonomy, like any other action:

| Action | Starts at | What it is |
|---|---|---|
| Started a forum topic | Jetonomy's value | A topic is published |
| Replied in the forum | Jetonomy's value | A reply is published |
| Your forum post / reply was upvoted | Jetonomy's value | Each upvote from another member |
| Your answer was accepted | Jetonomy's value | The asker accepts a reply |
| Your idea was planned | Jetonomy's value | A moderator moves an idea onto the roadmap |
| Your report was upheld | Jetonomy's value | A moderator removes a post you reported |
| Your forum post / reply was downvoted | Jetonomy's value | Each downvote. **Takes points** |
| Forum post reported | Jetonomy's value | Each report against the author. **Takes points** |
| Post removed by a moderator | Jetonomy's value | A moderator trashes or spams a topic or reply. **Takes points** |

For each one you can set the amount, switch it off, and add a cooldown, a daily cap or a weekly cap. **Nothing is fixed in code.** "Starts at" is only a starting value: until you save a number here, the action pays what Jetonomy already pays on your site (its default, or whatever you set under Jetonomy > Settings > Reputation), so installing this version changes no number.

How the two plugins split the work: Jetonomy decides whether a forum event counts at all and keeps the forum reputation. WB Gamification decides what that event is worth as community points. A value of 0 in Jetonomy means the event is never announced, so it also pays nothing here; to stop only the points, switch the action off here.

**Penalties** take points away. The amount is the number you set (shown as a positive number), a penalty never takes a balance below zero, and a member with nothing to lose loses nothing. What was really taken is what the ledger records, and what is given back if the penalty is lifted. Penalties are not shown in the public "how to earn" guide.

**Taken back and given back.** Content leaves with its points: a topic or reply that is deleted, or removed by a moderator, takes its participation points back, and restoring it returns them. A topic takes its replies' points with it (a reply's own points and any accepted-answer points), settled in the background in batches of 200 so a long thread never slows the request; approving the topic gives them back. When a moderator removes a topic, its participation points go back first and the penalty is then taken from what the member still holds, so a small balance ends at zero, never below. A withdrawn upvote takes back that one vote's points (not every upvote on the post), a withdrawn downvote or lifted report gives the penalty back, and un-accepting an answer takes the acceptance points back. A vote that changes direction does both: the old one is undone and the new one applies. Each upvote, downvote and report is tracked separately, so one member withdrawing never touches another's. Points that were already spent can leave a balance below zero when an earned award is taken back; that rule is the same for every integration.

Everything else Jetonomy scores (a custom badge's reputation bonus, a manual adjustment from the command line) is not an action: it is copied into the points ledger 1:1 as `jetonomy_badge_earned` and so on. Sandboxed members are skipped in both directions. Jetonomy's own reputation row is never touched.

In addition, the auto-discovered manifests register discrete Jetonomy actions:

| Manifest | Example actions |
|---|---|
| `integrations/jetonomy.php` | `jetonomy_space_joined`, `jetonomy_join_request_approved`, `jetonomy_trust_level_up`, `jetonomy_membership_activated` |
| `integrations/jetonomy-pro.php` | `jetonomy_pro_poll_created`, `jetonomy_pro_poll_voted`, `jetonomy_pro_reaction_added` |

A Jetonomy custom badge is paid once: its reputation bonus reaches the points ledger through the remaining reputation copy as `jetonomy_badge_earned`. There is no separate `jetonomy_pro_badge_earned` action (before 1.6.5 there was, and each badge paid twice).

## Membership Activation Pays Once Per Plan

`jetonomy_membership_activated` ("Activate a membership", 25 points by default) rewards a member for joining a paid plan. Since 1.6.6:

- **Once per member per plan.** Membership adapters re-announce a membership on every renewal, admin date change and monthly/yearly switch. Those re-announcements no longer pay again. Joining a different plan still earns, once. The check reads the points ledger, so an activation that a cap blocked does not count as earned.
- **Free plans can opt out.** Adapters also report free levels, such as a default plan every new member is placed on. The source that knows its levels answers the `wb_gam_membership_level_earns` filter with `false` for a free one, and the award is skipped. BuddyNext Pro does this for its free plans. See the [filters reference](../developer-guide/130-filters-reference.md).
- The award runs in the request rather than the background queue, so a second announcement seconds later already sees the first award.

## Leaderboard Deferral

Out of the box WB Gamification pays forum events the same amounts Jetonomy scores them, so the two leaderboards would rank the same members in the same order - a genuine duplicate. Rather than show both, `WBGam\Integrations\Jetonomy\DisplayDefer` suppresses WB Gam's leaderboard display when Jetonomy is active and lets Jetonomy's reputation leaderboard be the single source of truth.

When deferral is on, WB Gamification suppresses:

- The `leaderboard` block and the `[wb_gam_leaderboard]` shortcode
- The `top-members` block and the `[wb_gam_top_members]` shortcode
- The Hub block's leaderboard card

Suppression is done by blanking the rendered output (`render_block` and `do_shortcode_tag` filters), so any place those blocks or shortcodes appear - including the member achievement surfaces - renders nothing for the leaderboard.

### Badges Are Kept

Badges are **deliberately not** deferred. WB Gamification's badge engine is the broader system (OpenBadges 3.0, expiry, share pages, any-event triggers), and the two badge sets are complementary rather than duplicates: Jetonomy uses forum-native criteria, WB Gam uses site-wide actions. Both badge sets keep rendering.

### Overriding the Deferral

Deferral defaults to **on when Jetonomy is active** (`JETONOMY_VERSION` defined). Override it with the `wb_gam_defer_leaderboard_to_jetonomy` filter:

```php
// Force-show WB Gam's own leaderboard even when Jetonomy is active.
add_filter( 'wb_gam_defer_leaderboard_to_jetonomy', '__return_false' );

// Or defer even when Jetonomy is not active (unusual).
add_filter( 'wb_gam_defer_leaderboard_to_jetonomy', '__return_true' );
```

See the [Filters reference](../developer-guide/130-filters-reference.md) for the full signature.

## Requirements

- Jetonomy 2.0.8 or later for exact per-vote and per-report tracking (older versions still pay, but a withdrawn vote cannot be matched to its award); Jetonomy 1.4.3 or later otherwise (forum actions + leaderboard deferral).
- Jetonomy Pro active for the `jetonomy_pro_*` actions.
