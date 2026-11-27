# Points & Actions

Go to **Gamification > Earning > Actions and points** in your admin sidebar.

This screen lists every action that earns (or takes) points on your site, grouped by the plugin it comes from. Actions are detected automatically from the plugins you run, so a plugin's group appears as soon as that plugin is active.

Every change saves on its own. There is no Save button for actions: change an amount, a limit or a switch, and the screen says **Saved.** with an **Undo** button for 10 seconds. Each change keeps its own 10 seconds: the button shows how many are waiting, for example **Undo (2)**, and each click undoes the newest. If a save fails, the previous value comes back and the screen says so, so what you see is always what is stored.

## Find an action

A site with several plugins can have more than 80 actions. Three tools narrow the list:

| Tool | What it does |
|---|---|
| **Find an action** | Type part of an action's name or description. |
| **All / On / Off / Takes points / Has a limit** | Show every action, only the ones paying, only the ones switched off, only penalties, or only actions with a limit set. |
| **Plugin** | Show one plugin's actions. |

The count above the list ("86 actions") follows the filters. When nothing matches, the screen tells you to clear the search or choose **All**.

## One plugin's group

Each plugin is a group you can open and close, headed by the plugin name and its number of actions. Two buttons act on the whole group:

- **Restore [plugin] defaults** puts that plugin's actions back to how the plugin ships them: on or off, amount and limits.
- **Switch all off** stops every action in the group from paying. Amounts and limits are kept, so switching an action back on restores what you had.

## One action

| Column | What it controls |
|---|---|
| **On** | Whether the action pays. An action switched off earns no points and does not count towards badges or challenges. |
| **Action** | The action's name and what it rewards. **Once per member** means a member earns it one time only (for example, joining the site). **Takes points** marks a penalty, such as a moderator removing a post. |
| **Points** | How many points the action pays, from 0 to 9,999. For a penalty, the number of points it takes. When you run more than one point type, a menu beside the amount chooses which type the action pays in. |
| **Limit** | How often the action can pay, in words ("No limit", "10 a day", "1 min apart"). Open it to change it (see below). |
| **Members can submit** | Lets members claim the action through the Submit Achievement form, for you to approve. Every action starts unticked. Not available for penalties. See [Submissions](../features/120-submissions.md#choose-what-members-can-submit). |

Changes apply to the next award. Points already given are not recalculated.

## Limits

Open an action's **Limit** to set up to three limits:

| Field | What it does |
|---|---|
| **Time between awards (seconds)** | The shortest gap between two awards of this action to the same member. 30 stops a member earning for 50 comments posted in a minute. |
| **Most per day** | How many times the action can pay a member in one day, in your site's time zone (Settings > General). |
| **Most per week** | How many times the action can pay a member in one week. |

Leave a field empty for no limit. The limit's summary updates as you type, for example "20 a day, 30s apart".

Limits stop farming without blocking real activity. Good starting points: a few seconds between comments, a daily limit on uploads and reactions, and a weekly limit on anything members could repeat at will.

## Point expiry and log retention

Two cards sit below the actions, and these do have a **Save** button:

- **Point expiry** takes points away from members who have been inactive for a set time. It is off by default. See [Point Expiry](../features/230-point-expiry.md).
- **Log retention** sets how long the points history keeps rows: **Keep points history for** 1 to 24 months (6 by default). Older rows are removed once a day. The events table, which totals are rebuilt from, is never trimmed.

## For developers

Each change on this screen is one call to the REST route `POST /wb-gamification/v1/actions/{id}/overrides`, which takes `enabled`, `points`, `point_type`, `cooldown`, `daily_cap`, `weekly_cap` and `submittable`. `DELETE` on the same route restores the action's shipped values. Overrides are stored in the `wb_gam_action_overrides` option, keyed by action id, and the engine merges them over the manifest values, so the screen, the rate limits and REST always agree. See [REST: admin and webhooks](../developer-guide/180-rest-admin-webhooks.md).
