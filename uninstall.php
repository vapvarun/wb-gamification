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
// Reason: DROP TABLE is a schema operation required during uninstall.
// Caching is not applicable here — tables are being permanently removed.
// -------------------------------------------------------------------------
// Every table the Installer creates must be listed here. The 2026-05-27
// data-flow audit (admin-rest G3) found 3 tables were missing —
// `wb_gam_user_totals` (materialised totals), `wb_gam_submissions` (UGC
// queue), `wb_gam_leaderboard_cache` (snapshot store) — all introduced
// in 1.0.0. Crosscheck this list against `src/Engine/Installer.php` on
// every schema-add commit. The CI manifest check counts CREATE TABLEs
// against the `tables` section, but does not currently diff against this
// uninstall list.
// The hand-list that used to live here is gone, and the comment above it is the reason why: it told
// the next developer to "crosscheck this list against Installer.php on every schema-add commit",
// which is an instruction, not a mechanism, and it drifted anyway.
//
// Verified by actually running uninstall on a clean-room site rather than reading the code: FOUR
// tables survived it -- wb_gam_api_keys, wb_gam_notifications_queue, wb_gam_side_effect_failures and
// wb_gam_user_intelligence. Crosschecking against Installer would not even have caught three of them,
// because they are created by DbUpgrader migrations rather than by the installer.
//
// So ask the database which tables are ours instead of remembering. Every table this plugin has ever
// created lives under the one prefix; a table added five years from now is covered on the day it is
// written, and there is nothing left to keep in step.
$owned_prefix = $wpdb->esc_like( $wpdb->prefix . 'wb_gam_' ) . '%';

$tables = (array) $wpdb->get_col(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $owned_prefix ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall.
);

foreach ( $tables as $table ) {
	// $table came from SHOW TABLES against our own prefix -- it cannot be anything but ours.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall; table name from SHOW TABLES on our prefix.
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

// -------------------------------------------------------------------------
// 2. Delete options — the whole namespace.
// -------------------------------------------------------------------------
// This was a hand-list of about a hundred literal keys, plus five wildcard patterns for the families
// whose names are only known at runtime. It was refreshed by an audit hours ago and it was STILL
// incomplete: a clean-room uninstall left `wb_gam_tenure_seeded` behind. That is the third
// hand-maintained list in this file to be wrong (tables, usermeta, options), and the third time the
// answer is the same one.
//
// Every option this plugin writes is under a single prefix -- verified by grepping every
// update_option/add_option call site, including the ones that pass a class constant rather than a
// literal. So sweep the prefix. There is no list to forget a key, no wildcard family to enumerate,
// and an option added years from now is covered the day it is written.
//
// The prefix is escaped with esc_like, so `_` is a literal underscore and not a single-character
// wildcard. That matters: `wb_gam_%` unescaped would also match `wb_gamX...`, and an adversarial
// `wb_gamification_lookalike` option belonging to someone else is exactly the row we must not touch.
// Clean-room verified: ours all go, that one stays.
$wb_gam_owned = $wpdb->esc_like( 'wb_gam_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		  WHERE option_name LIKE %s
		     OR option_name LIKE %s
		     OR option_name LIKE %s",
		$wb_gam_owned,
		$wpdb->esc_like( '_transient_wb_gam_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wb_gam_' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uninstall; fixed namespace prefixes, bound.

// -------------------------------------------------------------------------
// 3. Delete transients.
// -------------------------------------------------------------------------
// Note: wb_gam_do_redirect transient retired in 1.0.0 — wizard redirect
// now driven by the persistent wb_gam_pending_setup_redirect option above.

// Wildcard transients: every `set_transient()` call site in the plugin uses
// a 'wb_gam_' key, most of them dynamic (per-user rate-limit buckets, per-user
// nudge cache, per-badge/per-webhook logs are options not transients — see
// above — but per-user/per-slug locks and caches ARE transients: e.g.
// RateLimiter's 'wb_gam_rl_<user_id>', NudgeEngine's 'wb_gam_nudge_<user_id>',
// TransactionalEmailEngine's 'wb_gam_email_burst_<slug>_<user_id>'). Hand-
// listing every dynamic suffix is exactly the fragile pattern that caused
// the options gap this audit found, so one prefix wildcard is used instead —
// SettingsIO::export()/import() independently confirms 'wb_gam_' is this
// plugin's entire, exclusively-owned option/transient namespace, so this
// can't catch anything not ours. Verified 2026-07-13 against every
// `set_transient()` call site in `src/`.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_wb_gam_%'
	    OR option_name LIKE '_transient_timeout_wb_gam_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, wildcard transients cannot use delete_transient().

// -------------------------------------------------------------------------
// 4. Clear cron hooks.
// -------------------------------------------------------------------------
// Crosschecked 2026-07-13 against every `wp_schedule_event()` call site +
// its `CRON_HOOK` constant in `src/`. 5 hooks were missing: PointsExpiry,
// ActionSchedulerCleaner, SideEffectDispatcher, IntelligenceProjector, and
// NotificationBridge all self-schedule on `init`/activation, so a missed
// entry here meant the recurring event kept firing forever post-uninstall.
$cron_hooks = array(
	'wb_gam_weekly_nudge',
	'wb_gam_prune_logs',
	'wb_gam_cohort_assign',
	'wb_gam_cohort_process',
	'wb_gam_weekly_email',
	'wb_gam_status_retention_check',
	'wb_gam_credential_expiry_check',
	'wb_gam_tenure_check',
	'wb_gam_points_decay',                // PointsExpiry::CRON_HOOK.
	'wb_gam_as_cleanup',                  // ActionSchedulerCleaner::CRON_HOOK.
	'wb_gam_reconcile_side_effects',      // SideEffectDispatcher::RECONCILE_CRON.
	'wb_gam_compute_intelligence',        // IntelligenceProjector::COMPUTE_CRON.
	'wb_gam_notifications_queue_prune',   // NotificationBridge::PRUNE_CRON.
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// -------------------------------------------------------------------------
// 5. Cancel Action Scheduler jobs.
// -------------------------------------------------------------------------
// The plugin does NOT use one AS group — it uses 7. `as_unschedule_all_actions()`
// only clears the exact group it's given, so the previous single-group call
// left the other 6 groups' pending/recurring actions running indefinitely
// post-uninstall. Enumerated 2026-07-13 against every `as_schedule_recurring_action()`,
// `as_schedule_single_action()`, and `as_enqueue_async_action()` call site in
// `src/` and resolved each 4th-arg group literal/constant. Note
// 'wb_gamification' (underscore, AsyncEvaluator) and 'wb-gamification'
// (hyphen, everything else) are two DIFFERENT groups, not a typo of one
// group — both must be cleared. Crosscheck this list on every commit that
// adds a new `AS_GROUP` constant or a bare group-string literal.
$as_groups = array(
	'wb-gamification',        // BadgeEngine, CohortEngine, CommunityChallengeEngine, Engine, WebhookDispatcher — default group.
	'wb_gamification',        // AsyncEvaluator — underscore variant, NOT the same group as above.
	'wb_gam_leaderboard',     // LeaderboardEngine::AS_GROUP.
	'wb_gam_email',           // WeeklyEmailEngine::AS_GROUP.
	'wb-gamification-emails', // TransactionalEmailEngine::AS_GROUP.
	'wb_gam_retention',       // StatusRetentionEngine.
	'wb-gamification-nudge',  // LeaderboardNudge.
);

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( $as_groups as $as_group ) {
		as_unschedule_all_actions( '', array(), $as_group );
	}
}

// -------------------------------------------------------------------------
// 6. Remove plugin custom capabilities from every role.
// -------------------------------------------------------------------------
// This list MUST match `WBGam\Engine\Capabilities::CAPS` exactly — caps that
// roll out via the plugin must also roll back on uninstall. Cross-checked
// 2026-05-27 (WBG-011): the previous list had two dead entries
// (wb_gam_manage_redemptions, wb_gam_manage_api_keys) that were never
// declared anywhere, plus 4 missing entries (manage_levels, manage_submissions,
// manage_email_settings, view_analytics) so those caps stayed on every role
// forever. Order matches Capabilities::CAPS for easy visual diff.
$plugin_caps = array(
	'wb_gam_award_manual',
	'wb_gam_manage_badges',
	'wb_gam_manage_rules',
	'wb_gam_manage_challenges',
	'wb_gam_manage_rewards',
	'wb_gam_manage_webhooks',
	'wb_gam_manage_levels',
	'wb_gam_manage_submissions',
	'wb_gam_manage_email_settings',
	'wb_gam_view_analytics',
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
// This was the FOURTH hand-maintained copy of "which meta keys are ours" -- after
// MemberData::USER_META_KEYS, Privacy's export groups, and ProgressReset's list. It
// held seven keys out of sixteen, and hardcoded three notification channels. The
// 2026-05-27 audit already fixed this list once, by adding the keys that were missing
// THEN; it drifted again, because a list is not a mechanism. Every one of those four
// copies had a different idea of what the plugin stores.
//
// Uninstall does not need to be selective, and that is the way out. Everything this
// plugin writes to usermeta lives under one namespace, so sweep the namespace: no list
// to forget a key, no channel to hardcode, nothing to keep in step. A key added five
// years from now is covered the day it is written.
//
// Both spellings, because `_wb_gam_last_award_note` carries WordPress's leading
// underscore convention -- and the fact that ONE key is spelled differently is exactly
// what a hand-list gets wrong.
$deleted_meta = $wpdb->query(
	"DELETE FROM {$wpdb->usermeta}
	  WHERE meta_key LIKE 'wb\\_gam\\_%'
	     OR meta_key LIKE '\\_wb\\_gam\\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uninstall; no user input, fixed namespace prefixes.

unset( $deleted_meta );
