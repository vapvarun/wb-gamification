# Kudos

Kudos is a peer-to-peer recognition system. Members can give a shoutout to another member, with both the giver and receiver earning points in the process.

## What Kudos Is

Kudos lets members publicly recognize each other for helpful contributions, great content, or community support. A kudos can include a short optional message. Both members earn points when kudos is given - the receiver earns more than the giver, reflecting the value of being recognized.

## Default Point Values

| Role | Default Points |
|---|---|
| Receiver (member getting kudos) | 5 points |
| Giver (member sending kudos) | 2 points |

These values are configurable in **Gamification > Settings > Kudos**.

## Daily Points Limit

Members can always send kudos. What is limited is the **points**: by default the first **5 kudos a member sends each day** award points to both people. After that, kudos still go through - the recipient still gets the message and the notification - they just earn no points. The count resets at midnight (site timezone).

A second kudos to the same member **within an hour** also earns no points. This is what stops two friends trading kudos to farm points, without ever telling a member they cannot thank someone.

Members are never shown a "limit reached" message. The only hard stop is a spam ceiling of **50 kudos a day**, far above normal use; once a member reaches it, the kudos form is simply not shown until the next day. Developers can change it with the `wb_gam_kudos_daily_ceiling` filter.

Change the points limit in **Gamification > Settings > Kudos > Kudos per day that earn points**.

## Rules and Restrictions

- Members **cannot give kudos to themselves**
- Kudos can include an **optional message** of up to 255 characters
- Both point awards (giver and receiver) flow through the full gamification pipeline - they count toward badge conditions, level thresholds, streaks, and challenges

## The Kudos Feed

The Kudos Feed block and shortcode display a public stream of recent kudos activity. Each entry shows the giver's avatar, the receiver's avatar, and the optional message.

This creates a visible culture of recognition. When members see others being recognized, they are more likely to send kudos themselves.

**Shortcode:**

```
[wb_gam_kudos_feed limit="10"]
[wb_gam_kudos_feed limit="5" show_messages="0"]
```

| Attribute | Default | Description |
|---|---|---|
| `limit` | 10 | How many recent kudos to show (max 50) |
| `show_messages` | 1 | Whether to display the kudos message |

## Sending Kudos (Give Kudos Shortcode)

*New in 1.4.0.*

Members can send kudos directly from the frontend via the `[wb_gam_give_kudos]` shortcode. Drop it into any page, template part, or BuddyPress profile section.

**Shortcode:**

```
[wb_gam_give_kudos]
[wb_gam_give_kudos to="username"]
[wb_gam_give_kudos label="Cheer this member"]
```

| Attribute | Default | Description |
|---|---|---|
| `to` | - | Lock the form to a specific recipient by `user_login` or `user_id`. When set, the recipient field is hidden. |
| `label` | "Send Kudos" | Custom submit button label. |

The shortcode wraps an underlying server-side block (`wb-gamification/give-kudos`) so theme builders and template-builder plugins that consume blocks via `render_block()` can use the same render path. The block is not yet wired into the WordPress block inserter - use the shortcode for now.

**Behavior:**

- Logged-out visitors see a sign-in prompt instead of the form.
- Logged-in members see a recipient input (or the locked recipient if `to=` is set), a message field (max 255 characters), and a Send button.
- Typing two or more letters of a name shows matching members (avatar, name, @handle); pick one with the mouse or the arrow keys and Enter. Typing an exact username also works.
- Submitting POSTs to `POST /wb-gamification/v1/kudos` with `recipient_login` (the server resolves the username or profile slug to a user ID).
- Status feedback appears below the submit button: sent, recipient not found, or a network error. There is no "limit reached" message: past the daily points limit kudos still send, and at the spam ceiling the form is not shown.
- Responsive: stacks vertically with a full-width submit button on screens ≤640 px.

A common placement is the BuddyPress member profile page (with `to="{{member_login}}"`) so visitors can send kudos directly to the profile they are viewing.

## BuddyPress Integration

When BuddyPress is active:
- Giving kudos creates a BuddyPress activity post so the community can see it
- The receiver gets a BuddyPress notification
- The Kudos Feed block pulls from the same data source, so activity appears in both places

## Viewing All Kudos

Admins can see all kudos activity in **Gamification > Analytics**. The kudos table shows the giver, receiver, message, date, and points awarded for each transaction.

## Tips

- Add the Kudos Feed block to your community homepage or sidebar to make recognition visible
- Consider adding kudos sending directly to member profile pages where it is easy to reach
- The giver earning points (even just 2) encourages members to actively give recognition rather than passively receive it
- Pair kudos with a "Most Recognized" challenge (based on kudos received) to make recognition a community event
