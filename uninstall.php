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
	// -------------------------------------------------------------------
	// 2026-07-13 data-lifecycle audit. Root cause of the gap: most of these
	// keys are never written as a quoted 'wb_gam_...' string — they're
	// written via a class constant (e.g. `self::OPTION_KEY`, `self::OPT_LAST`).
	// A plain grep for quoted literals misses that, so they silently
	// survived every earlier uninstall pass. Verified one by one against
	// every `update_option()` / `add_option()` call site in `src/`,
	// resolving each constant back to its literal string. Crosscheck this
	// block against those call sites on every commit that adds an option.
	//
	// wb_gam_hub_page_id points at a WP page (Installer::ensure_hub_page()).
	// Delete ONLY the option here — the page itself is real site content a
	// site owner may have built on; uninstall must never delete content.
	'wb_gam_hub_page_id',
	'wb_gam_features',                   // FeatureFlags::OPTION_KEY.
	'wb_gam_license_key',                // LicenseActivator::KEY_OPTION.
	'wb_gam_license_key_allow_tracking', // LicenseActivator::KEY_OPTION . '_allow_tracking'.
	'wb_gam_preset_activated',           // LicenseActivator::ACTIVATED_OPTION.
	'wb_gam_caps_version',               // Capabilities::CAPS_VERSION_OPTION.
	'wb_gam_cohort_settings',            // CohortSettingsController::OPTION_KEY.
	'wb_gam_deactivation_reasons',       // DeactivationFeedback::OPTION.
	'wb_gam_realtime_transport',         // SSEController::TRANSPORT_OPTION.
	'wb_gam_modules',                    // SettingsPage module enable/disable map.
	'wb_gam_accent_color',               // Appearance::OPTION.
	'wb_gam_toast_position',             // NotificationBridge::TOAST_POSITION_OPTION.
	'wb_gam_credential_expiry_last_run', // CredentialExpiryEngine::OPT_LAST.
	'wb_gam_action_overrides',           // ActionsController per-action override map.
	'wb_gam_wc_account_endpoint_v1',     // WooCommerce AccountIntegration one-shot flag.
	'wb_gam_events_retention_months',    // SettingsPage — distinct from wb_gam_log_retention_months above.
	// Plain settings keys SettingsPage::save() writes directly — none of
	// these were listed at all before this audit.
	'wb_gam_points_decay_enabled',
	'wb_gam_points_decay_days',
	'wb_gam_points_decay_percent',
	'wb_gam_leaderboard_authority',
	'wb_gam_login_bonus_enabled',
	'wb_gam_login_bonus_tiers',
	'wb_gam_streak_grace_days',
	'wb_gam_streak_milestone_bonus',
	'wb_gam_weekly_email_enabled',
	'wb_gam_weekly_email_subject',
	// Never auto-created by the plugin (only ever read, via
	// Email::from_header()'s get_option() default) — but it's a
	// plugin-namespaced settings key a site owner or integration may have
	// set directly, so uninstall still owns cleaning it up if present.
	'wb_gam_weekly_email_from_name',
	'wb_gam_nudge_email',
	'wb_gam_bp_stream_badge_earned',
	'wb_gam_bp_stream_challenge_completed',
	'wb_gam_bp_stream_kudos_given',
	'wb_gam_bp_stream_level_changed',
	'wb_gam_profile_slug_base',
	'wb_gam_kudos_daily_limit',
	'wb_gam_kudos_receiver_points',
	'wb_gam_kudos_giver_points',
	'wb_gam_excluded_roles',
	'wb_gam_excluded_users',
	// DbUpgrader.php one-shot feature-migration / data-migration flags. Each
	// is a "ran already" idempotency marker for a single ALTER TABLE /
	// backfill, gated behind its own local `$flag_key` variable rather than
	// a shared constant — that's why 18 of these were missing here despite
	// 3 sibling flags (feature_point_types_v1 and friends, above) already
	// being listed.
	'wb_gam_feature_engine_badges_are_rules_v1',
	'wb_gam_feature_badge_rule_groups_v1',
	'wb_gam_migrated_stock_null_unlimited',
	'wb_gam_feature_kudos_moderation_v1',
	'wb_gam_feature_streak_sort_idx_v1',
	'wb_gam_feature_scale_indexes_v1',
	'wb_gam_feature_events_source_key_v1',
	'wb_gam_feature_superseded_badge_action_ids_v1',
	'wb_gam_feature_user_intelligence_v1',
	'wb_gam_feature_notifications_queue_v1',
	'wb_gam_feature_notifications_skip_purge_v1',
	'wb_gam_feature_side_effect_failures_v1',
	'wb_gam_feature_api_keys_table_v1',
	'wb_gam_feature_submissions_v1',
	'wb_gam_feature_leaderboard_cache_unique_key_v1',
	'wb_gam_feature_user_totals_v1',
	'wb_gam_feature_leaderboard_cache_point_type_v1',
	'wb_gam_feature_leaderboard_cache_prev_rank_v1',
);

foreach ( $known_options as $option ) {
	delete_option( $option );
}

// Delete wildcard options: per-action point amount, enable flag, currency override.
// Also covers 2 dynamic-key option families found by the 2026-07-13 audit:
// BadgeEngine::start_backfill() writes 'wb_gam_backfill_<badge_id>' (one per
// badge, progress state for the async backfill job) and
// WebhookDispatcher writes 'wb_gam_webhook_log_<webhook_id>' (one per
// webhook, rolling delivery log) — both keyed on an ID that only exists at
// runtime, so they can't be listed as literals above.
$wildcard_prefixes = array(
	'wb_gam_points_%',
	'wb_gam_enabled_%',
	'wb_gam_point_type_%',
	'wb_gam_backfill_%',
	'wb_gam_webhook_log_%',
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
