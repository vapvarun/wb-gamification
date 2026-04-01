# Redemption Store

The Redemption Store lets members spend their accumulated points on rewards you define. It closes the loop on your points economy — points become worth something tangible, which increases the motivation to earn them.

## Creating a Reward Item

1. Go to **WB Gamification → Redemption Store → Add Reward**.
2. Enter a **title** and **description** visible to members.
3. Set the **points cost** required to redeem.
4. Choose a **reward type**:

| Reward Type | What It Does |
|---|---|
| `discount_pct` | Generates a percentage-off coupon code |
| `discount_fixed` | Generates a fixed-amount coupon code |
| `custom` | You define the fulfillment manually |

5. Optionally set a **stock limit**. When stock hits zero, the item becomes unavailable.
6. Click **Publish**.

## How Members Redeem

Members browse available rewards on the store page (use the `[wb_gam_store]` shortcode or the REST endpoint `GET /wp-json/wb-gamification/v1/redemption/items`). When they redeem an item, the `RedemptionController` deducts the points cost and creates a transaction record.

For discount reward types, a WooCommerce coupon code is generated automatically and shown to the member. For `custom` rewards, you handle fulfillment manually and mark the redemption as fulfilled in the admin.

## Transaction Log

Every redemption is recorded with the member ID, reward ID, points spent, timestamp, and fulfillment status. View the log at **WB Gamification → Redemption Store → Transactions**.

## Stock Management

Stock counts decrement on each successful redemption. If you set no stock limit, the reward is unlimited. You can update stock at any time from the reward edit screen.

## Requirements

- Pro add-on active (no separate feature flag required)
