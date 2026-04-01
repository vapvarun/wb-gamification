# Badges

Badges are visual achievements members earn by hitting specific milestones. The plugin ships with 30 default badges and supports unlimited custom badges.

## What Badges Are

A badge is a named achievement with an image, description, and one or more conditions that must be met before it awards. Badges award automatically the moment a member meets all conditions. Members keep their badges permanently unless the badge has an expiry period.

## Default Badges

The 30 default badges are organized into categories:

### Points Milestones
Badges for reaching cumulative point totals. Examples: "First Steps" (100 points), "Rising Star" (500 points), "Community Pillar" (1,500 points), "Champion" (5,000 points).

### WordPress Actions
Badges for WordPress-native contributions. Examples: "Published Author" (publish first post), "Prolific Writer" (publish 10 posts), "Commenter" (leave first comment), "Conversation Starter" (post receives a comment).

### BuddyPress Actions
Badges for BuddyPress community participation. Examples: "Welcome Aboard" (complete extended profile), "Social Butterfly" (accept 10 friendships), "Group Builder" (create a group), "Reaction Magnet" (receive 50 reactions).

### Special
Badges assigned manually by admins or awarded for extraordinary contributions. These do not auto-evaluate — they are given by an admin through the Manual Award interface.

## How Badges Are Awarded

**Auto-award** happens automatically. After every point transaction, the badge engine evaluates all active badge conditions for the member who just earned points. If any condition is now satisfied for the first time, the badge awards immediately.

Conditions that trigger auto-award:
- **Point milestone** — member's cumulative points reach a threshold
- **Action count** — member has completed a specific action a set number of times

**Manual award** is done by an admin. Go to **Gamification > Manual Award**, select a member, choose a badge from the list, and click **Award**. The member receives a notification right away.

## Creating Custom Badges

1. Go to **Gamification > Badges**.
2. Click **Add New Badge**.
3. Enter a name, description, and choose or upload a badge image.
4. Under **Award Condition**, choose the condition type:
   - **Point milestone** — enter the cumulative points required
   - **Action count** — choose the action and the number of times it must be completed
   - **Admin only** — the badge is never awarded automatically
5. Optionally configure advanced options (see below).
6. Click **Save Badge**.

The new badge is active immediately. Existing members who already meet the condition will **not** receive it retroactively — it awards only on future transactions.

## Badge Images

Each badge displays an image in blocks and on BuddyPress profiles. When you create a custom badge, you can upload any image from your WordPress Media Library. Recommended size is 200x200 pixels. PNG files with transparent backgrounds work best.

Default badges use SVG icons that scale cleanly at any size.

## Advanced Badge Options

When creating or editing a badge, you can set the following optional limits:

**Expiry (validity_days)** — If set, the badge expires this many days after it is earned. An expired badge is removed from the member's showcase. Useful for certifications that need periodic renewal. Leave blank for permanent badges.

**Close date (closes_at)** — The badge stops awarding after this date. Members who earn it before the date keep it permanently. Useful for event-based or seasonal badges.

**Maximum earners (max_earners)** — The badge stops awarding once this many members have earned it. Useful for "first 100 members" exclusivity badges.

## Credential Badges (OpenBadges 3.0)

Badges marked as **Credential** badges generate a verifiable digital credential following the OpenBadges 3.0 standard. Members can download the credential JSON file, share it on LinkedIn, or include it in a digital portfolio to prove they earned the badge on your site.

This feature is part of **WB Gamification Pro**.

## Viewing Badges

Members can see their earned badges in:
- The **Badge Showcase block** on any page (`[wb_gam_badge_showcase]`)
- Their **BuddyPress profile** Gamification tab (if BuddyPress is active)

Use the `show_locked="1"` attribute on the Badge Showcase shortcode to display unearned badges grayed out, so members know what they are working toward.
