# Points & Actions

The **Points** tab at **Gamification → Settings → Points** lists every action the plugin recognises, with the point value and enabled state for each.

## Action Table

Each row represents one trackable event:

| Column | Description |
|---|---|
| Action | The trigger event identifier (e.g. `publish_post`, `wp_login`) |
| Label | Human-readable name shown in the points log and notifications |
| Points | Value awarded when the action fires (negative values deduct points) |
| Enabled | Toggle to include or exclude this action from scoring |

## Editing Point Values

Click the value field for any action and type a new number. Click **Save Changes** to apply.

Changes take effect immediately for new actions. Existing point records are not retroactively adjusted — the history reflects the value at the time each action fired.

## Rate Limiting

Rate limiting is defined per-action in the manifest. The two common patterns are:

- **Cooldown** — a minimum number of seconds between awards for the same user and action (e.g. one login bonus per 24 hours)
- **Once-only** — non-repeatable actions fire at most once per user lifetime (e.g. profile completion)

The admin UI shows these as read-only indicators. To change rate limits, use the `wb_gamification_action_manifest` filter.

## Awarding Points Manually

Use **Gamification → Manual Award** to add or deduct points outside the normal action system. Manual awards appear in the member's history with the label "Manual Award" and an optional admin note.

## Points Decay

WB Gamification does not implement points decay. Points are permanent. This is intentional — decay consistently drives away active contributors who feel their history is being erased.
