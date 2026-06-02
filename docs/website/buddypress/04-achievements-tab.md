# BuddyPress Achievements Tab

## Overview

When BuddyPress is active, WB Gamification adds an **Achievements** tab to every member profile. No shortcode or configuration is required - as soon as both plugins are active, the tab appears in the profile navigation and renders the member's gamification data through the same blocks used everywhere else in the plugin.

The tab is added by `WBGam\BuddyPress\ProfileIntegration`, which boots on the `bp_loaded` action and guards on `function_exists('buddypress')` before registering anything.

## What Members See

The tab is labelled **Achievements** (slug `achievements`) and sits at navigation position 35. It has four sub-tabs:

| Sub-tab | Slug | Shows | Source block(s) |
|---------|------|-------|-----------------|
| Overview | `overview` | Points + level progress and the current streak (a concise personal summary) | `member-points`, `streak` |
| Badges | `badges` | Earned and locked badges | `badge-showcase` |
| Points | `points` | The member's points history | `points-history` |
| Streak | `streak` | Streak heatmap and milestones | `streak` |

`overview` is the default sub-tab. All content is scoped to the **displayed** member (`bp_displayed_user_id()`), so visiting another member's profile shows that member's achievements.

## How It Renders

Each sub-tab renders existing gamification blocks through their shortcodes, scoped to the displayed member with `user_id`. There is no profile-only template to keep in sync - the profile reuses the single source of block markup.

The shared plumbing (asset enqueue, the mapped "View full dashboard" link, and the surface wrapper) lives in `WBGam\Engine\MemberSurface`. The same renderer also powers the WooCommerce My Account endpoint and the opt-in LearnDash profile link, so the three surfaces stay consistent. See [Member Achievement Surfaces](../features/23-member-achievement-surfaces.md).

## The "View full dashboard" Link

When a member views **their own** profile and a Hub page is mapped (option `wb_gam_hub_page_id`), the surface appends a "View full dashboard" link to the mapped Hub page. The link never appears on another member's profile, and never when no Hub page is mapped.

## Customizing the Surface

The wrapped surface markup passes through the `wb_gam_member_surface_html` filter before output, so a theme or add-on can wrap or augment the tab without duplicating the renderer:

```php
add_filter(
	'wb_gam_member_surface_html',
	static function ( string $html, int $user_id ): string {
		return '<h2>' . esc_html__( 'Achievements', 'my-textdomain' ) . '</h2>' . $html;
	},
	10,
	2
);
```

See the [Filters reference](../developer-guide/14-filters-reference.md) for the full signature.

## Profile Header Rank

The Achievements tab is separate from the rank badge that WB Gamification adds to the profile **header** (level name, point count, progress bar). That header display is documented in [BuddyPress Profile Display](03-profile-display.md) and is controlled by the member's `show_rank` preference.

## No Configuration Needed

`ProfileIntegration::init()` runs on `bp_loaded`. If BuddyPress is deactivated, the tab silently does nothing.
