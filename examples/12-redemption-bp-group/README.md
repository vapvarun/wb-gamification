# Example 12 — Redemption fulfilment: BuddyPress group access

Members spend points to join a BuddyPress group (e.g. an Insiders space, mentor-only group).

## When to use this

Your community runs on BuddyPress and you want to gate access to specific groups behind point thresholds. Members redeem and the listener calls `groups_join_group()` immediately.

## How it works

1. **Admin creates a reward** in `Gamification → Redemption Store`:
   - **Reward Type:** `Custom Reward (fulfilled via hook)`
   - **Description:** *"Join the Insiders group. group:7"* — `group:7` is the BuddyPress group ID this listener parses.
   - **Point Cost:** whatever you want.

2. **Member redeems** the reward.

3. **This listener** picks up `wb_gamification_points_redeemed`, finds `group:<id>`, calls `groups_join_group( $group_id, $user_id )`, and updates redemption status.

## Files

- `your-plugin.php` — drop into `wp-content/plugins/redemption-bp-group/` and activate.

## Public groups vs private/hidden

`groups_join_group()` joins regardless of group privacy — it's a direct membership write. If you want the user to land in the group's pending-request queue instead of being auto-joined, replace the call with `groups_send_membership_request()`. That's the right pattern for "spend points to skip the line" but still keep approval gates.
