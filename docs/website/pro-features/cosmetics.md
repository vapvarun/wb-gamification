# Cosmetics and Profile Frames

Cosmetics let members personalize their community presence using visual items — profile frames, overlays, or custom CSS effects. The `CosmeticEngine` manages the item catalog, member inventory, and equipped state.

## Creating a Cosmetic Item

1. Go to **WB Gamification → Cosmetics → Add Item**.
2. Enter a **name** for the item (visible to members).
3. Choose the **type** (e.g., `profile_frame`, `overlay`).
4. Upload the **asset** (image URL or CSS asset) in the **Asset URL** field.
5. Optionally enter a **CSS class** to apply when the item is equipped.
6. Set the **award type**:

| Award Type | How Members Get It |
|---|---|
| `admin_grant` | You assign it manually to specific members |
| `purchase` | Members spend points from their balance |

7. If `purchase`, enter the **points cost**.
8. Click **Save**.

## How Members Equip Cosmetics

Members browse their cosmetic inventory on their profile page and click to equip an item. Only one item of each type can be equipped at a time. Equipping a new frame automatically unequips the previous one.

## BuddyPress Profile Display

When BuddyPress is active, equipped cosmetics appear on the member's BP profile avatar and profile header. The `CosmeticEngine` outputs the asset and CSS class via the BuddyPress profile display hooks.

## Awarding Cosmetics to Members

To grant a cosmetic item to a specific member:

1. Go to **WB Gamification → Cosmetics → Items**.
2. Click the item name.
3. Under **Grant to Members**, search for and select the member.
4. Click **Grant**.

You can also award cosmetics automatically as badge rewards or level-up bonuses using the Rules engine.

## Requirements

- Pro add-on active
- `cosmetics` feature flag enabled
