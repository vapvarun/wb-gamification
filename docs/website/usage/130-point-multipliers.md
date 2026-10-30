# Point Multipliers (Double Points)

Go to **WB Gamification > Point Multipliers** in your admin menu.

A multiplier changes how many points an action pays, for every member, from the moment you save it. Use it for a "double points weekend", to push one behaviour you want more of, or to slow down one that is getting farmed.

## Add a Multiplier

1. Enter the **Multiplier**: `2` doubles points, `1.5` gives 50% more, `0.5` halves them.
2. In **Applies to**, keep **All actions**, or pick one action (for example "Publish a blog post") to multiply only that one.
3. Click **Add Multiplier**.

The new multiplier is active straight away. Members see the larger amount in their toasts and points history.

## Example: Double Points Weekend

1. On Friday, add a multiplier of `2` with **Applies to** set to **All actions**.
2. On Monday, click **Deactivate** on it in the **Active & Inactive Multipliers** list.

Deactivating keeps the multiplier in the list, so next time you only click **Activate**. **Delete** removes it for good.

## How Multipliers Combine

- Every active multiplier that matches the action applies, one after the other. A `2` on All actions and a `1.5` on "Publish a blog post" make a published post pay 3 times its base points.
- Points are rounded to a whole number after each multiplier, and never go below zero.
- Multipliers change new awards only. Points already earned stay as they are.
- Daily caps and cooldowns on an action still apply; a multiplier changes the amount, not how often a member can earn.

## Scheduled Campaigns

A multiplier can carry a start and end date so it switches itself on and off. The admin screen does not set dates yet; set them through the REST API with `starts_at` and `ends_at` (site time zone) on `wb-gamification/v1/rules`. See [Admin and Webhooks REST](../developer-guide/180-rest-admin-webhooks.md).

## Who Can Manage Multipliers

Administrators, and staff given the **Manage rules** permission under **WB Gamification > Settings > Access > Staff permissions**.
