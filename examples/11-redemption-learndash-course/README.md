# Example 11 — Redemption fulfilment: LearnDash course unlock

Members spend points to unlock a LearnDash course.

## When to use this

You sell or run an LMS site and want top earners to redeem their points for course access without manual intervention. The plugin grants enrolment immediately on redemption.

## How it works

1. **Admin creates a reward** in `Gamification → Redemption Store`:
   - **Reward Type:** `Custom Reward (fulfilled via hook)`
   - **Description:** something like *"Unlock the WordPress 101 course. course:42"* — the literal substring `course:42` is what this listener parses.
   - **Point Cost:** whatever you want.

2. **Member redeems** the reward via the redemption store block (or REST).

3. **`RedemptionEngine::redeem()`** validates points, debits them, marks the row `pending_fulfillment`, then fires `wb_gamification_points_redeemed`.

4. **This listener** picks up the action, finds `course:<id>` in the description, calls `ld_update_course_access( $user_id, $course_id, false )`, and updates the redemption row to `fulfilled`.

## Files

- `your-plugin.php` — drop into `wp-content/plugins/redemption-learndash-course/` and activate.

## Why "description prefix" instead of structured config?

Out of the box WB Gamification's reward form only stores structured config for the built-in types (WooCommerce coupon types and Wbcom Credits). For `custom` rewards we use a description-prefix convention to avoid forking the admin UI. If you ship this as a real plugin, replace the regex with a proper `reward_config` JSON field rendered by your own admin metabox.

## Pairs well with

- `examples/12-redemption-bp-group` — same pattern for BuddyPress group joins.
- `examples/13-redemption-manual-queue` — for swag/gift-cards where there's no API to call.
