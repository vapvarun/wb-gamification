# Tenure and First-Time Badges

Go to **WB Gamification > Badges** in your admin menu.

Two kinds of badge reward a moment, not a total. **First-time badges** celebrate a member's first post, first comment and similar. **Tenure badges** celebrate time as a member, such as one year. Both are ordinary badges with an editable rule. For the basics, see [Badge Management](20-badge-management.md) and [Badges](../features/20-badges.md).

## First-Time Badges

A fresh install includes these first-time badges. Each one awards when the member does the action once.

| Badge | Awarded for | Action it counts |
|---|---|---|
| Welcome Aboard | Joining the community | **Join the site** |
| First Post | Publishing a first post | **Publish a blog post** |
| First Comment | Leaving a first comment | **Leave a comment** |
| First Update | Posting a first activity update | **Post an activity update** (BuddyPress) |
| First Connection | Making a first friend | **Accept a friendship** (BuddyPress) |

The BuddyPress badges can only be earned while BuddyPress is active, because the action they count comes from BuddyPress.

### What Makes Them Fire

A first-time badge counts the member's points history for one action. It awards as soon as that action pays points and the count reaches the number in the rule, which is `1`.

That means the action must be able to pay points:

1. Go to **WB Gamification** and click **Points** in the left menu.
2. Find the action, for example **Publish a blog post**.
3. Make sure its **On** switch is on and its **Amount** is above 0.

If you switch the action off or set it to 0, nothing is paid, so nothing is counted and the badge cannot award.

A first-time badge does not look backwards on its own. A member who posted before you created the badge gets it the next time they do the action. To award it to everyone who already qualifies, tick **Also award this badge to members who already qualify** when you save. See [Change the Rule](#change-the-rule).

## Tenure Badges

A tenure badge awards after a member has had an account for a number of days, counted from the date they registered. The rule type is **Has been a member for N days**.

The plugin checks tenure once a day, because no member action can trigger it. A member who passes the mark is awarded the badge at the next daily check.

A new tenure badge also reaches long-time members this way. Everyone who is already past the mark receives it at the next daily check, even if you leave **Also award this badge to members who already qualify** unticked.

### The Built-In Ladder

Sites that carry the built-in anniversary badges have these four. The badge ID is shown in the edit form.

| Badge ID | Days | Roughly |
|---|---|---|
| `tenure_1yr` | 365 | 1 year |
| `tenure_2yr` | 730 | 2 years |
| `tenure_5yr` | 1825 | 5 years |
| `tenure_10yr` | 3650 | 10 years |

A fresh install does not create these four badges. If you do not see them in the **Badges** library, add the ones you want. See [Add a Tenure Badge](#add-a-tenure-badge).

## Change the Rule

1. Go to **WB Gamification > Badges**.
2. Click the badge card.
3. Under **Auto-Award Conditions**, change the number. For example, set a tenure badge to `548` days for 18 months.
4. Tick **Also award this badge to members who already qualify** only if you want existing members to receive it now. It is off by default.
5. Click **Save Changes**.

The rule is a normal condition, so you can add more with **+ Add condition** and choose whether the member must match **ALL of these** or **ANY of these**. For example, require 365 days as a member and 500 points.

Saving a rule changes who can earn the badge from now on. It never removes a badge a member already holds.

## Turn a Badge Off

Each badge is switched off on its own.

1. Open the badge in the library.
2. Set its condition to **Admin awarded only (manual)**.
3. Click **Save Changes**.

The badge now awards only when you grant it by hand, and the library card changes from **Auto-award** to **Manual**. Members who already hold it keep it.

To remove a badge completely, open it and click **Delete Badge**. This also removes it from every member who earned it.

The **Modules** screen lists switches called **Tenure badges** and **Site-first badges**. Do not rely on them to stop these badges. Switch off each badge as above.

## Add a Tenure Badge

1. Go to **WB Gamification > Badges** and click **+ Create New Badge**.
2. Enter a **Badge ID** such as `tenure_3yr`, a **Name** such as "3-Year Member", and an **Icon**.
3. Under **Auto-Award Conditions**, choose **Has been a member for N days** and enter `1095`.
4. Click **Create Badge**.

## Add a First-Time Badge

1. Click **+ Create New Badge**.
2. Fill in the **Badge ID**, **Name**, **Description** and **Icon**.
3. Choose **Performs an action N times**, pick the action from the list, and enter `1`.
4. Click **Create Badge**.

Make sure the action is switched on and pays points. See [What Makes Them Fire](#what-makes-them-fire).

## Site-First Badges

A site-first badge goes to only the first member who does something: reach a level, earn a point total, or hold a streak. It is a normal badge with **Max earners** set to `1`. Once one member has it, nobody else can earn it.

Sites that carry the built-in set have three: `first_champion` (reach the Champion level, or your highest level if you have no Champion), `first_10k_points` (10,000 points) and `first_100_day_streak` (a 100-day streak). You can create your own the same way, with **Max earners** set to `1`.

## Who Can Manage Badges

Administrators, and staff given the **Manage badges** permission. See [Staff Permissions](140-staff-permissions.md).

## See Also

- [Badge Management](20-badge-management.md) lists every badge field.
- [Badges](../features/20-badges.md) explains how badges work for members.
- [Badge Credentials](180-badge-credentials.md) turns a badge into a shareable credential.
