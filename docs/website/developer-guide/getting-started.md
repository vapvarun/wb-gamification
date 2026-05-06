# Getting Started for Developers

WB Gamification is built as a platform -- not just a plugin. Choose your path based on what you're building.

## Three Paths

### Path 1: WordPress Plugin Developer

**"I want to add gamification to my plugin"**

The fastest way is a **manifest file** -- a single PHP file that tells WB Gamification what actions your plugin provides.

1. Create `wb-gamification.php` in your plugin's root directory
2. Return an array of action definitions
3. WB Gamification auto-discovers it on activation

[See the full manifest tutorial](build-first-integration.md)
[Download the manifest template](manifest-template.php)

**For advanced use cases**, use the PHP API directly:

```php
// Register a custom action programmatically.
wb_gam_register_action( array(
    'id'             => 'my_plugin_action',
    'label'          => 'Custom Action',
    'hook'           => 'my_plugin_did_something',
    'user_callback'  => fn() => get_current_user_id(),
    'default_points' => 25,
    'category'       => 'my-plugin',
) );

// Check if a user has earned a badge.
if ( wb_gam_has_badge( $user_id, 'first-post' ) ) {
    // Show special content.
}

// Award points manually.
wb_gam_award_points( $user_id, 50, 'custom_reward' );
```

[See all PHP API functions](helper-functions.md)

### Path 2: Theme Developer

**"I want to display gamification data in my theme"**

Use **Gutenberg blocks** or **shortcodes** -- no PHP knowledge needed.

**Blocks (recommended):**
Add any of the 12 blocks in the editor: Hub, Leaderboard, Badge Showcase, Level Progress, Challenges, Streak, Top Members, Kudos Feed, Year Recap, Points History, Earning Guide.

**Shortcodes (classic themes):**

```
[wb_gam_hub]
[wb_gam_leaderboard period="week" limit="10"]
[wb_gam_member_points]
[wb_gam_badge_showcase show_locked="1"]
[wb_gam_level_progress]
[wb_gam_challenges]
[wb_gam_streak]
[wb_gam_earning_guide]
```

**PHP template tags:**

```php
// In your theme templates:
$points = wb_gam_get_user_points( get_current_user_id() );
$level  = wb_gam_get_user_level( get_current_user_id() );
$badges = wb_gam_get_user_badges( get_current_user_id() );
$streak = wb_gam_get_user_streak( get_current_user_id() );
```

[See all blocks and shortcodes](../features/blocks-shortcodes.md)

### Path 3: App / Headless Developer

**"I want to build a mobile app or headless frontend"**

Use the **REST API** with API key authentication.

**Setup:**

1. Generate an API key in WP Admin > Gamification > API Keys
2. Use the key in the `X-WB-Gam-Key` header

**JavaScript SDK (recommended):**

```typescript
import { WBGamification } from '@wbcom/wb-gamification';

const client = new WBGamification({
  baseUrl: 'https://your-site.com',
  apiKey: 'your-api-key',
});

const leaders = await client.getLeaderboard('week', 10);
const member  = await client.getMember(42);
await client.submitEvent(42, 'completed_lesson', { lesson_id: 5 });
```

**Direct REST API:**

```bash
# Get leaderboard
curl -H "X-WB-Gam-Key: YOUR_KEY" \
  https://your-site.com/wp-json/wb-gamification/v1/leaderboard?period=week

# Award points
curl -X POST -H "X-WB-Gam-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"user_id":42,"action_id":"manual","points":50}' \
  https://your-site.com/wp-json/wb-gamification/v1/events
```

**OpenAPI spec:** `GET /wp-json/wb-gamification/v1/openapi.json` -- import into Postman, Swagger UI, or any OpenAPI tool.

**Webhooks** for real-time event notifications to your backend:
[See webhook documentation](webhooks.md)

[See full REST API reference](rest-api.md)

---

## Architecture Overview

```
Trigger Sources
  |- WordPress hooks (publish_post, wp_login, etc.)
  |- BuddyPress hooks (bp_activity_posted, etc.)
  |- REST API (POST /events)
  |- WP-CLI (wp wb-gamification points award)
  |- Manifest files (auto-discovered)
       |
Event Normalization -> WB_Gam_Event
       |
Rule Evaluation Engine
  |- PointsEngine  -> append-only points ledger
  |- BadgeEngine   -> rule-based badge awards
  |- LevelEngine   -> threshold-based progression
  |- ChallengeEngine -> time-bound goals
  |- StreakEngine   -> daily/weekly tracking
  |- KudosEngine   -> peer recognition
       |
Output Consumers
  |- Gutenberg blocks (12 blocks)
  |- BuddyPress (profile, directory, activity)
  |- REST API (18 controllers)
  |- Webhooks (Zapier, Make, n8n)
  |- Toast notifications
  |- Your custom code (hooks + PHP API)
```

---

## Extension Points

| Layer | How to extend | Documentation |
|-------|--------------|---------------|
| **Add actions** | Manifest file or `wb_gam_register_action()` | [Manifest tutorial](build-first-integration.md) |
| **Modify points** | `wb_gam_points_for_action` filter | [Hooks reference](hooks-filters.md) |
| **Custom badge rules** | `wb_gam_should_award_badge` filter | [Hooks reference](hooks-filters.md) |
| **React to events** | `wb_gam_after_points_award` action | [Hooks reference](hooks-filters.md) |
| **React to badges** | `wb_gam_after_badge_award` action | [Hooks reference](hooks-filters.md) |
| **React to levels** | `wb_gam_level_changed` action | [Hooks reference](hooks-filters.md) |
| **Custom display** | Shortcodes, blocks, or PHP API | [Blocks & shortcodes](../features/blocks-shortcodes.md) |
| **External systems** | Webhooks or REST API | [Webhooks](webhooks.md), [REST API](rest-api.md) |
