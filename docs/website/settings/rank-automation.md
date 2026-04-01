# Rank Automation Rules

Go to **WB Gamification > Rules** in your admin sidebar.

Rank Automation rules trigger actions automatically when a member reaches a specific level. Use them to onboard members into groups, grant role upgrades, or send congratulatory messages — all without writing any code.

## How rules work

Each rule has:
- A **trigger** — which level the member must reach
- An **action** — what happens when they reach it

Rules fire once per member per level. If a member already passed a level before you created a rule for it, they will not receive the action retroactively.

You can add multiple rules for the same level. Each rule triggers independently.

## Adding a New Rule

Fill in the **Add New Rule** form at the bottom of the page.

### Step 1: Choose the trigger level

Select the level from the **When member reaches level** dropdown. This list shows all levels you have configured in the Levels tab.

### Step 2: Choose the action type

Select one of the three action types from the **Perform action** dropdown. The form shows only the fields relevant to your selection.

---

### Action type: Add to BuddyPress group

Automatically adds the member as a member of a BuddyPress group.

| Field | Description |
|-------|-------------|
| **BuddyPress Group ID** | The numeric ID of the group. Find this in **BuddyPress > Groups** — hover over a group name and look for `gid=` in the URL. |

**Use case:** Add members who reach "Veteran" level to a private "VIP Members" group, giving them access to exclusive content.

---

### Action type: Add WordPress role

Adds a WordPress role to the member's account. This adds the role — it does not replace their existing roles.

| Field | Description |
|-------|-------------|
| **Role slug** | The lowercase WordPress role slug, e.g. `contributor`, `editor`, or a custom role slug registered by another plugin. |

**Use case:** Grant `contributor` access to members who reach the "Regular" level, allowing them to submit posts for review.

---

### Action type: Send BuddyPress message

Sends a private BuddyPress message to the member from a specified user.

| Field | Description |
|-------|-------------|
| **Message sender user ID** | The user ID of the account that appears as the sender. Defaults to 1 (the site admin). |
| **Message subject** | The subject line of the private message. |
| **Message content** | The body of the message. Plain text. |

**Use case:** Send a congratulations message when a member reaches "Gold" level, including instructions for their new benefits.

---

Click **Add Rule** to save. The rule appears in the rules table immediately.

## Viewing and deleting rules

The rules table shows:

| Column | Description |
|--------|-------------|
| When member reaches | The level name that triggers the rule |
| Action | The action type |
| Parameters | The configuration values (group ID, role, or message details) |

Click **Delete** next to any rule and confirm the prompt to remove it. Deleting a rule does not undo actions already performed for members who previously reached the level.

## Use case examples

| Goal | Trigger level | Action type | Configuration |
|------|--------------|-------------|---------------|
| Welcome new members to a starter group | Newcomer (0 pts) | Add to BP group | Group ID of your "All Members" group |
| Unlock content for engaged members | Regular (500 pts) | Add WP role | `subscriber-plus` (custom role) |
| Personally congratulate top contributors | Legend (10,000 pts) | Send BP message | Sender = admin, personalized message |
| Give editors posting rights at Veteran level | Veteran (2,000 pts) | Add WP role | `contributor` |
