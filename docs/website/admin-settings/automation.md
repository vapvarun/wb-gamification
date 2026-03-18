# Rank Automation

The **Automation** tab at **Gamification → Settings → Automation** lets you define rules that fire automatically when a member reaches a specific level.

## What Automation Does

Instead of manually managing group memberships or roles as members grow, you set a rule once and the plugin handles it.

| Action Type | What Happens |
|---|---|
| `add_bp_group` | Adds the member to a BuddyPress group |
| `send_bp_message` | Sends the member a private message |
| `change_wp_role` | Changes the member's WordPress role |

## Adding a Rule

1. Click **Add Rule**.
2. Select the **Level** that triggers the rule (e.g. Level 5 — Active).
3. Choose an **Action Type**.
4. Fill in the Action Value:
   - `add_bp_group` → enter the group ID
   - `send_bp_message` → enter the message text
   - `change_wp_role` → enter the role slug (e.g. `contributor`, `editor`)
5. Click **Save Changes**.

## Multiple Rules per Level

You can add as many rules as you need to the same level. All rules for a level fire when the member reaches it, in the order listed.

## When Rules Fire

Rules run the moment a member's level changes — immediately after a point award pushes them over the threshold. There is no delay or queue.

## Notes

- Rules do not reverse automatically if a member's points theoretically dropped below a threshold (points do not decay in WB Gamification).
- BuddyPress must be active for `add_bp_group` and `send_bp_message` actions to work.
- `change_wp_role` works on all WordPress installations.
