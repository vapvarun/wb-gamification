<?php
/**
 * WB Gamification — Integrating a plugin whose API loads on a hook (FluentCRM)
 *
 * Some plugins define their API INSIDE their own `plugins_loaded` callback
 * rather than at file-parse time. FluentCRM is the canonical example:
 * `fluentCrmApi()` is registered at `plugins_loaded` priority 10.
 *
 * WB Gamification's ManifestLoader includes every `wb-gamification.php`
 * manifest at `plugins_loaded` priority 5 — BEFORE priority 10. So a
 * drop-a-file manifest (Example 01) that guards its return value like this:
 *
 *     // DON'T do this in a wb-gamification.php manifest:
 *     if ( ! function_exists( 'fluentCrmApi' ) ) {
 *         return [];            // always taken at priority 5 → no triggers, ever
 *     }
 *     return [ 'triggers' => [ ... ] ];
 *
 * ...returns an empty array on every request, silently. No error, no warning,
 * no points. The manifest looks correct; it just runs too early to see the
 * function it is testing for.
 *
 * The fix is to register LATE instead of via a manifest file. Put this file in
 * your own plugin (or an mu-plugin) — it is normal PHP that runs in your
 * plugin's load path, NOT a wb-gamification.php manifest.
 *
 * @package YourPlugin
 * @see     examples/01-track-event-via-manifest/  For the drop-a-file manifest (parse-safe plugins).
 * @see     examples/02-programmatic-register/     For the general runtime-registration pattern.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the FluentCRM triggers once every plugin has finished loading.
 *
 * `init` fires after ALL `plugins_loaded` callbacks (priorities 5, 10, 20, …)
 * have run, so both `wb_gam_register_action()` and `fluentCrmApi()` are
 * guaranteed to be defined here. FluentCRM's tag hooks (below) don't fire
 * until a real contact-tag change happens, which is always long after `init`,
 * so registering here never misses an event.
 *
 * If WB Gamification is inactive, `wb_gam_register_action()` is undefined and
 * we bail — no fatal. If FluentCRM is inactive, its hooks never fire, so the
 * registered triggers simply lie dormant — no harm in registering them.
 */
add_action( 'init', 'yourplugin_register_fluentcrm_triggers' );

/**
 * Define the FluentCRM actions that award points.
 */
function yourplugin_register_fluentcrm_triggers(): void {
	// WB Gamification must be active for the helper to exist.
	if ( ! function_exists( 'wb_gam_register_action' ) ) {
		return;
	}

	// FluentCRM must be active AND fully booted. At `init` this is finally a
	// reliable check — unlike at manifest-scan time (plugins_loaded@5).
	if ( ! function_exists( 'fluentCrmApi' ) ) {
		return;
	}

	// Awarded when a tag is added to the member's FluentCRM contact.
	wb_gam_register_action(
		array(
			'id'             => 'fluentcrm_tag_added',
			'label'          => __( 'Tagged in FluentCRM', 'your-plugin' ),
			'description'    => __( 'Awarded when a tag is applied to the member\'s contact.', 'your-plugin' ),
			'hook'           => 'fluentcrm_contact_added_to_tags',
			// FluentCRM passes ( array $tag_ids, FluentCrm\App\Models\Subscriber $subscriber ).
			'user_callback'  => 'yourplugin_fluentcrm_user_id',
			'default_points' => 10,
			'category'       => 'fluentcrm',
			'icon'           => 'dashicons-tag',
			'repeatable'     => true,
			// One award per tag-change burst is plenty; tune to taste.
			'cooldown'       => 60,
		)
	);

	// Optional counterpart: deduct/award when a tag is removed. Shown to make
	// the pairing explicit — delete it if you only reward tag additions.
	wb_gam_register_action(
		array(
			'id'             => 'fluentcrm_tag_removed',
			'label'          => __( 'Untagged in FluentCRM', 'your-plugin' ),
			'description'    => __( 'Awarded when a tag is removed from the member\'s contact.', 'your-plugin' ),
			'hook'           => 'fluentcrm_contact_removed_from_tags',
			'user_callback'  => 'yourplugin_fluentcrm_user_id',
			'default_points' => 5,
			'category'       => 'fluentcrm',
			'icon'           => 'dashicons-tag',
			'repeatable'     => true,
			'cooldown'       => 60,
		)
	);
}

/**
 * Map a FluentCRM tag-hook payload to the WordPress user ID to award.
 *
 * FluentCRM contacts are not always WordPress users, so resolve by the
 * subscriber's email and return 0 when there is no matching account — the
 * engine skips the award cleanly on 0.
 *
 * @param array  $tag_ids    Tag IDs added/removed (unused here).
 * @param object $subscriber FluentCRM Subscriber model (has ->user_id / ->email).
 * @return int WordPress user ID, or 0 to skip.
 */
function yourplugin_fluentcrm_user_id( $tag_ids, $subscriber ): int {
	if ( ! is_object( $subscriber ) ) {
		return 0;
	}

	// FluentCRM sets user_id when the contact is linked to a WP account.
	if ( ! empty( $subscriber->user_id ) ) {
		return (int) $subscriber->user_id;
	}

	// Fall back to email → WP user lookup.
	if ( ! empty( $subscriber->email ) ) {
		$user = get_user_by( 'email', $subscriber->email );
		return $user ? (int) $user->ID : 0;
	}

	return 0;
}
