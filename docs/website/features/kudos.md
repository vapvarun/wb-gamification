# Kudos

Kudos is a peer-to-peer recognition system. Members can give a shoutout to another member, with both the giver and receiver earning points in the process.

## What Kudos Is

Kudos lets members publicly recognize each other for helpful contributions, great content, or community support. A kudos can include a short optional message. Both members earn points when kudos is given — the receiver earns more than the giver, reflecting the value of being recognized.

## Default Point Values

| Role | Default Points |
|---|---|
| Receiver (member getting kudos) | 5 points |
| Giver (member sending kudos) | 2 points |

These values are configurable in **Gamification > Settings > Kudos**.

## Daily Send Limit

Each member can give a maximum of **5 kudos per day** by default. This prevents gaming the system by members repeatedly sending kudos to the same friend. The limit resets at midnight (site timezone).

When a member reaches their daily limit, the kudos button shows an informative message telling them when the limit resets.

The daily limit is configurable. Go to **Gamification > Settings > Kudos** and change the **Daily Kudos Limit** field.

## Rules and Restrictions

- Members **cannot give kudos to themselves**
- Kudos can include an **optional message** of up to 255 characters
- Both point awards (giver and receiver) flow through the full gamification pipeline — they count toward badge conditions, level thresholds, streaks, and challenges

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
