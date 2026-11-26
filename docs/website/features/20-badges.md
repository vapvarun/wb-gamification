# Badges

Badges are achievements members earn for milestones and actions. A fresh install comes with 37 badges, and you can add as many of your own as you like.

## What Badges Are

A badge has a name, a description, an icon and a rule. The rule is one or more conditions. When a member meets the rule, the badge is theirs. A badge whose only condition is **Admin awarded only (manual)** is never given out on its own: you grant it by hand.

Members keep a badge for good unless you set **Valid for (days)** on it. Then it expires that many days after they earn it.

## Default Badges

A fresh install includes these 37 badges. You can edit, delete or add to them.

| Group | Badges | How they are earned |
|---|---|---|
| Points (5) | Century Club, Five Hundred Strong, Thousand Points Club, Five Thousand Strong, Ten Thousand Club | Reaching 100, 500, 1,000, 5,000 and 10,000 total points |
| WordPress (6) | Welcome Aboard, First Post, Prolific Writer, Content Creator, First Comment, Engaged Reader | Joining, publishing 1, 10 and 25 posts, leaving 1 and 10 comments |
| BuddyPress (12) | First Update, Active Member, Community Voice, First Connection, Social Connector, Group Creator, Team Player, Profile Pro, Reaction Magnet, Comment Champion, Poll Pioneer, Blog Publisher | Activity updates, friendships, groups, profile, reactions, activity comments, polls and member blog posts |
| Special (7) | Early Adopter, Founding Member, Top Contributor, Mentor, Kudos Champion, Event Host, Community Veteran | Granted by an admin |
| Tenure (4) | 1-Year Member, 2-Year Member, 5-Year Member, 10-Year Member | Being a member for 1, 2, 5 and 10 years |
| Site-first (3) | First Champion, First to 10,000, Century Streak Pioneer | Going to the one member who gets there first |

The BuddyPress badges can only be earned while BuddyPress is active, because the actions they count come from BuddyPress. On a site without BuddyPress, members do not see those badges at all, unless they already earned one. In **Gamification > Rewards > Badges** the badge shows a **Not earnable here** label instead of **Auto-award**. The same applies to any badge that needs a plugin the site does not run. For tenure and site-first badges, see [Tenure and First-Time Badges](../usage/160-tenure-and-first-time-badges.md).

## How Badges Are Awarded

**Automatically.** A badge with any condition other than **Admin awarded only (manual)** is checked whenever something could change the answer: the member earns points, moves up a level, or reaches a streak milestone. Tenure badges are checked once a day, because no member action can trigger them. The member gets the badge as soon as the rule is met.

**By hand.** Open the badge in **Gamification > Rewards > Badges**. Under **Award this badge to a user**, search for the member and click **Award Badge**. This works for any badge, including ones that also award automatically.

## Creating Custom Badges

1. Go to **Gamification > Rewards > Badges**.
2. Click **+ Create New Badge**.
3. Enter a **Badge ID**, a **Name** and a **Description**, and click **Choose Icon** to pick an image.
4. Under **Auto-Award Conditions**, pick a condition and fill in its value. Click **+ Add condition** to add more, and choose whether the member must match **ALL of these** or **ANY of these**.
5. Click **Create Badge**.

The condition types are:

| Condition | Awards when |
|---|---|
| **Admin awarded only (manual)** | Never on its own. You grant it by hand. |
| **Reaches a point total** | The member's total points reach the number you enter. |
| **Performs an action N times** | The member has done the chosen action that many times. |
| **Reaches a level** | The member reaches the chosen level. |
| **Has earned another badge** | The member already holds the chosen badge. |
| **Keeps a daily streak of N days** | The member's daily streak reaches that many days in a row. |
| **Has been a member for N days** | That many days have passed since the member joined. |
| **Earns N points in a period** | The member earns that many points in one day, week or month. |

For every field on the form, see [Badge Management](../usage/20-badge-management.md).

### Members Who Already Qualify

A new or changed badge is earned from now on. Members who met the rule before you saved it do not get it straight away.

To give it to them too, tick **Also award this badge to members who already qualify** before you save. The plugin then awards it in the background to every member who meets the rule. On a large community that can be many people and many notifications, so it is off by default.

## Badge Images

Each badge shows its icon in blocks, on member profiles and on the share page. Pick any image from your Media Library. The recommended size is 128x128 pixels, a PNG with a transparent background.

## Limits on a Badge

These optional fields on the badge form control how long and how widely a badge is given out:

- **Valid for (days)** - the badge expires this many days after a member earns it and stops counting. Leave it blank so it never expires. Useful for certifications that need renewing.
- **Closes at** - no one can earn the badge after this date. Members who earned it before keep it. Useful for event and seasonal badges.
- **Max earners** - the badge stops awarding once this many members have it. Useful for "first 100 members" badges.

## Credential Badges (OpenBadges 3.0)

Tick **Is Credential** on a badge to make it a verifiable digital credential following the OpenBadges 3.0 standard. Members can share a verified badge link, for example on LinkedIn.

## Viewing Badges

Members see their earned badges in:

- The **Badge Showcase** block, or the `[wb_gam_badge_showcase]` shortcode, on any page.
- Their BuddyPress profile, when BuddyPress is active.

Add `show_locked="1"` to the shortcode to show unearned badges greyed out, so members can see what they are working toward.

## FAQ

**I created a badge and nobody has it yet. Why?**
A new badge is earned from now on. Tick **Also award this badge to members who already qualify** and save again to award it to everyone who already meets the rule.

**Can I give an automatic badge to someone by hand?**
Yes. Open the badge and use **Award this badge to a user**.

## Troubleshooting

**A badge never awards.** If its rule counts an action, check that the action is switched on and pays more than 0 points: go to **WB Gamification**, click **Points** in the left menu, and check its **On** switch and **Amount**. An action that pays nothing is not counted.

**A badge stopped awarding.** Check its **Closes at** date and its **Max earners** limit.
