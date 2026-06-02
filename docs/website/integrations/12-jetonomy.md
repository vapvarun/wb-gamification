# Jetonomy Integration

WB Gamification connects to Jetonomy automatically when Jetonomy is active. Two things happen: Jetonomy activity awards WB Gamification points, and - to avoid showing two identical leaderboards - WB Gamification defers its own leaderboard display to Jetonomy's reputation ranking.

## Jetonomy Events Award WB Gam Points

`WBGam\Integrations\Jetonomy\JetonomyIntegration` mirrors every Jetonomy reputation delta 1:1 into the WB Gamification points ledger via the `jetonomy_reputation_changed` action. Positive deltas award points; negative deltas debit (only when the member has enough balance). Sandboxed users are skipped in both directions, and Jetonomy's own reputation row is never touched - only the gamification ledger mirrors it.

Forum and content reputation events (post / reply / vote / idea / flag) are recorded under prefixed action IDs (`jetonomy_*`) so forum-sourced points stay distinct from native WB Gam awards in reports.

In addition, the auto-discovered manifests register discrete Jetonomy actions:

| Manifest | Example actions |
|---|---|
| `integrations/jetonomy.php` | `jetonomy_space_joined`, `jetonomy_join_request_approved`, `jetonomy_trust_level_up`, `jetonomy_membership_activated` |
| `integrations/jetonomy-pro.php` | `jetonomy_pro_poll_created`, `jetonomy_pro_poll_voted`, `jetonomy_pro_message_sent`, `jetonomy_pro_badge_earned`, `jetonomy_pro_reaction_added` |

`jetonomy_pro_badge_earned` awards WB Gam points when a member earns a Jetonomy custom badge, hooking `jetonomy_pro_badge_earned`.

## Leaderboard Deferral

Because WB Gamification mirrors Jetonomy reputation 1:1 into points, the two leaderboards would rank the same members in the same order - a genuine duplicate. Rather than show both, `WBGam\Integrations\Jetonomy\DisplayDefer` suppresses WB Gam's leaderboard display when Jetonomy is active and lets Jetonomy's reputation leaderboard be the single source of truth.

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

See the [Filters reference](../developer-guide/14-filters-reference.md) for the full signature.

## Requirements

- Jetonomy active (reputation mirroring + leaderboard deferral).
- Jetonomy Pro active for the `jetonomy_pro_*` actions, including `jetonomy_pro_badge_earned`.
