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
);

foreach ( $known_options as $option ) {
	delete_option( $option );
}

// Delete wildcard options: wb_gam_points_* and wb_gam_enabled_*.
$wildcard_prefixes = array( 'wb_gam_points_%', 'wb_gam_enabled_%' );

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
delete_transient( 'wb_gam_do_redirect' );

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
$plugin_caps = array(
	'wb_gam_award_manual',
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
$user_meta_keys = array(
	'wb_gam_level_id',
	'wb_gam_level_name',
	'wb_gam_league_tier',
);

foreach ( $user_meta_keys as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}
