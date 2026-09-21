# Kudos Settings

Go to **WB Gamification > Kudos** in your admin sidebar.

Kudos is a peer-recognition system. Members send kudos to each other to say "great work." Both the giver and the receiver can earn points, which encourages participation on both sides.

## Fields

### Max kudos per day

**Default: 5**

The maximum number of kudos a single member can send in one calendar day. Once a member hits this limit, they cannot send more until the next day.

This prevents kudos flooding. If members are using kudos to farm points for a friend, lower this value. If you want kudos to feel free and frictionless, raise it.

**Recommended values by community size:**

| Community size | Suggested limit |
|----------------|----------------|
| Small (< 100 members) | 3–5 |
| Medium (100–1,000) | 5–10 |
| Large (1,000+) | 10–20 |

### Points per kudos received

**Default: 5**

The number of points the recipient earns each time they receive a kudos. This rewards members for creating content and behavior that others appreciate.

Set to 0 if you want kudos to be symbolic recognition without point value.

### Points per kudos given

**Default: 2**

The number of points the sender earns each time they give kudos to another member. Keeping this lower than the receiver value ensures that the primary reward goes to the person being recognized, not the person clicking the button.

Set to 0 if you only want the receiver to benefit.

## Saving

Click **Save Changes** after adjusting any value. Changes apply to all future kudos immediately. Existing kudos in the log are not retroactively recalculated.

## Notes

- Kudos daily limits and point values work independently. You can have a high limit with low point values (social and lightweight) or a low limit with high point values (rare and meaningful).
- The Kudos feed block (`[wb_gam_kudos_feed]` shortcode) displays recent kudos activity on the frontend.
- If BuddyPress is active, kudos events also appear in the BuddyPress activity stream.
