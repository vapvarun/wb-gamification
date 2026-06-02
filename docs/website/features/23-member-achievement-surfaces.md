# Member Achievement Surfaces

WB Gamification gives each member a place to see their own progress inside the member areas they already use - the BuddyPress profile, the WooCommerce My Account page, and (optionally) the LearnDash profile. All of these reuse the plugin's existing blocks and the mapped Hub page, so there is no duplicated display logic to keep in sync.

## The Shared Renderer

Every surface goes through `WBGam\Engine\MemberSurface`. Each integration adapter decides **which** blocks to show and **where** to mount them; `MemberSurface` owns the common plumbing:

- Enqueuing the styles the reused blocks need on a non-block host page (design tokens, base frontend CSS, the Lucide icon font, and the Hub stylesheet when the Hub block is used).
- Rendering one or more block shortcodes scoped to a specific member.
- Appending a "View full dashboard" link to the **mapped** Hub page (option `wb_gam_hub_page_id`) - never a hardcoded slug. The link only appears on the member's own surface and only when a Hub page is mapped.
- Wrapping the output and passing it through the `wb_gam_member_surface_html` filter so a host can wrap or augment it without forking the renderer.

## Surface 1 - BuddyPress Achievements Tab

When BuddyPress is active, members get an **Achievements** profile tab with Overview / Badges / Points / Streak sub-tabs, each scoped to the displayed member. Full detail in [BuddyPress Achievements Tab](../buddypress/04-achievements-tab.md).

## Surface 2 - WooCommerce My Account Endpoint

Stores running WooCommerce **without** BuddyPress still get a member-facing surface. `WBGam\Integrations\WooCommerce\AccountIntegration` adds an **Achievements** item to the My Account menu and an endpoint at:

```
/my-account/achievements/
```

My Account is always the logged-in customer's own account, so the endpoint renders the member's full **Hub** block (their self dashboard) plus the mapped "View full dashboard" link.

This surface only boots when WooCommerce is active (`class_exists('WooCommerce')`). The rewrite endpoint is registered on `init`; it is flushed once on upgrade (guarded by the `wb_gam_wc_account_endpoint_v1` option) and again on plugin activation. If the endpoint does not resolve on an existing install, re-save Permalinks under **Settings > Permalinks** to flush rewrite rules.

## Surface 3 - LearnDash Profile Link (opt-in)

LearnDash only exposes a "before template" hook on its profile, so rather than stack blocks there, WB Gamification adds a single clean **My Achievements** link at the top of the LearnDash profile, pointing to the mapped Hub page.

This surface is **off by default**. LearnDash's profile extension point is less native than the BuddyPress tab or the WooCommerce endpoint, so sites opt in:

```php
add_filter( 'wb_gam_learndash_profile_link', '__return_true' );
```

It only boots when LearnDash is active (`LEARNDASH_VERSION` defined), and renders nothing for logged-out visitors or when no Hub page is mapped.

## The Mapped Hub Page

All three surfaces link to (and the WooCommerce endpoint renders) the **Hub** page you map during setup. The mapping is stored in the `wb_gam_hub_page_id` option. Because the surfaces resolve the Hub from that option, you can move or rename the Hub page without breaking any link.

## Customizing Any Surface

Every surface's wrapped markup passes through one filter before output:

```php
add_filter(
	'wb_gam_member_surface_html',
	static function ( string $html, int $user_id ): string {
		// Wrap or augment the surface for every host (BP / WC / LD).
		return $html;
	},
	10,
	2
);
```

## See Also

- **[BuddyPress Achievements Tab](../buddypress/04-achievements-tab.md)** - the profile tab and its sub-tabs.
- **[WooCommerce integration](../integrations/03-woocommerce.md)** - WooCommerce action triggers.
- **[LearnDash integration](../integrations/04-learndash.md)** - LearnDash action triggers.
- **[Filters reference](../developer-guide/14-filters-reference.md)** - `wb_gam_member_surface_html`, `wb_gam_learndash_profile_link`.
