<?php
/**
 * WB Gamification — Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all plugin data: custom tables, options, transients,
 * cron jobs, Action Scheduler tasks, and user meta.
 *
 * @package WB_Gamification
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// -------------------------------------------------------------------------
// 1. Drop custom tables.
//    phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
//    phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
//    Reason: DROP TABLE is a schema operation required during uninstall.
//    Caching is not applicable here — tables are being permanently removed.
// -------------------------------------------------------------------------
// Every table the Installer creates must be listed here. The 2026-05-27
// data-flow audit (admin-rest G3) found 3 tables were missing —
// `wb_gam_user_totals` (materialised totals), `wb_gam_submissions` (UGC
// queue), `wb_gam_leaderboard_cache` (snapshot store) — all introduced
// in 1.0.0. Crosscheck this list against `src/Engine/Installer.php` on
// every schema-add commit. The CI manifest check counts CREATE TABLEs
// against the `tables` section, but does not currently diff against this
// uninstall list.
$tables = array(
	'wb_gam_points',
	'wb_gam_redemption_items',
	'wb_gam_rules',
	'wb_gam_webhooks',
	'wb_gam_badge_defs',
	'wb_gam_user_badges',
	'wb_gam_levels',
	'wb_gam_challenge_log',
	'wb_gam_cohort_members',
	'wb_gam_user_cosmetics',
	'wb_gam_community_challenges',
	'wb_gam_member_prefs',
	'wb_gam_challenges',
	'wb_gam_events',
	'wb_gam_streaks',
	'wb_gam_kudos',
	'wb_gam_partners',
	'wb_gam_community_challenge_contributions',
	'wb_gam_redemptions',
	'wb_gam_cosmetics',
	'wb_gam_point_types',
	'wb_gam_point_type_conversions',
	'wb_gam_user_totals',
	'wb_gam_submissions',
	'wb_gam_leaderboard_cache',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall, no caching needed.
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

// -------------------------------------------------------------------------
// 2. Delete options — known keys.
// -------------------------------------------------------------------------
$known_options = array(
	'wb_gam_db_version',
	'wb_gam_leaderboard_mode',
	'wb_gam_log_retention_months',
	'wb_gam_rank_automation_rules',
	'wb_gam_template',
	'wb_gam_wizard_complete',
	'wb_gam_pending_setup_redirect',
	'wb_gam_leaderboard_invalidated_at',
	'wb_gam_feature_point_types_v1',
	'wb_gam_feature_redemption_point_type_v1',
	'wb_gam_feature_point_type_conversions_v1',
	// Email + privacy options added in 1.0.0 — caught by the 2026-05-27 audit.
	'wb_gam_email_level_up',
	'wb_gam_email_badge_earned',
	'wb_gam_email_challenge_completed',
	'wb_gam_email_redemption',
	'wb_gam_profile_public_enabled',
);

foreach ( $known_options as $option ) {
	delete_option( $option );
}

// Delete wildcard options: per-action point amount, enable flag, currency override.
$wildcard_prefixes = array(
	'wb_gam_points_%',
	'wb_gam_enabled_%',
	'wb_gam_point_type_%',
);

foreach ( $wildcard_prefixes as $like_pattern ) {
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like_pattern
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, one-time cleanup.

	if ( ! empty( $option_names ) ) {
		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}
	}
}

// -------------------------------------------------------------------------
// 3. Delete transients.
// -------------------------------------------------------------------------
// Note: wb_gam_do_redirect transient retired in 1.0.0 — wizard redirect
// now driven by the persistent wb_gam_pending_setup_redirect option above.

// Wildcard transients: wb_gam_site_first_*.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_wb_gam_site_first_%'
	    OR option_name LIKE '_transient_timeout_wb_gam_site_first_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, wildcard transients cannot use delete_transient().

// -------------------------------------------------------------------------
// 4. Clear cron hooks.
// -------------------------------------------------------------------------
$cron_hooks = array(
	'wb_gam_weekly_nudge',
	'wb_gam_prune_logs',
	'wb_gam_cohort_assign',
	'wb_gam_cohort_process',
	'wb_gam_weekly_email',
	'wb_gam_status_retention_check',
	'wb_gam_credential_expiry_check',
	'wb_gam_tenure_check',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// -------------------------------------------------------------------------
// 5. Cancel Action Scheduler jobs.
// -------------------------------------------------------------------------
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'wb-gamification' );
}

// -------------------------------------------------------------------------
// 6. Remove plugin custom capabilities from every role.
// -------------------------------------------------------------------------
// Caught by 2026-05-27 audit (admin-rest G2): pre-refactor this list only
// had `wb_gam_award_manual`, so the other 6 plugin-defined caps stayed in
// every role after uninstall. Crosscheck against `Capabilities::CAPS` on
// every cap-add commit.
$plugin_caps = array(
	'wb_gam_award_manual',
	'wb_gam_manage_badges',
	'wb_gam_manage_challenges',
	'wb_gam_manage_redemptions',
	'wb_gam_manage_webhooks',
	'wb_gam_manage_api_keys',
	'wb_gam_manage_rules',
);

foreach ( wp_roles()->roles as $role_name => $_role_data ) {
	$role = get_role( $role_name );
	if ( ! $role ) {
		continue;
	}
	foreach ( $plugin_caps as $cap ) {
		if ( $role->has_cap( $cap ) ) {
			$role->remove_cap( $cap );
		}
	}
}

// -------------------------------------------------------------------------
// 7. Delete user meta.
// -------------------------------------------------------------------------
// Caught by 2026-05-27 audit (admin-rest G3 + notifications G8): pre-refactor
// the list missed the wizard sentinel and the per-consumer notification
// cursors so the meta lingered after uninstall (also leaked across GDPR
// erase). Crosscheck this against `add_user_meta` / `update_user_meta`
// call sites on every commit that introduces new per-user state.
$user_meta_keys = array(
	'wb_gam_level_id',
	'wb_gam_level_name',
	'wb_gam_league_tier',
	'wb_gam_setup_seen',
	'wb_gam_notif_cursor_footer',
	'wb_gam_notif_cursor_heartbeat',
	'wb_gam_notif_cursor_rest',
);

foreach ( $user_meta_keys as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}
