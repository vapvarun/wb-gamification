<?php
/**
 * WB Gamification DB Upgrader
 *
 * Runs `ALTER TABLE` migrations when the stored db_version is behind
 * WB_GAM_VERSION. Safe to call on every request (version-gated + transient
 * lock prevents repeated work).
 *
 * Add a new migration:
 *   1. Bump WB_GAM_VERSION in the main plugin file.
 *   2. Add a method `upgrade_to_X_Y_Z(): void` below.
 *   3. Register it in `get_upgrades()`.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Runs ALTER TABLE migrations when the stored db_version is behind WB_GAM_VERSION.
 *
 * @package WB_Gamification
 */
final class DbUpgrader {

	private const OPT_KEY  = 'wb_gam_db_version';
	private const LOCK_KEY = 'wb_gam_db_upgrade_lock';
	private const LOCK_TTL = 60;

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Run any pending migrations on plugins_loaded.
	 */
	public static function init(): void {
		$current = get_option( self::OPT_KEY, '0.0.0' );

		if ( version_compare( $current, WB_GAM_VERSION, '>=' ) ) {
			return;
		}

		// Prevent concurrent upgrade runs (e.g. two simultaneous requests on activation).
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		self::run( $current );

		update_option( self::OPT_KEY, WB_GAM_VERSION );
		delete_transient( self::LOCK_KEY );
	}

	// ── Dispatcher ───────────────────────────────────────────────────────────────

	/**
	 * Execute all migrations that are newer than the given version.
	 *
	 * @param string $from Current stored db_version (e.g. "0.1.0").
	 */
	private static function run( string $from ): void {
		foreach ( self::get_upgrades() as $version => $method ) {
			if ( version_compare( $from, $version, '<' ) ) {
				self::$method();
			}
		}
	}

	/**
	 * Map of "upgrade to this version" => method name.
	 * Must be in ascending version order.
	 *
	 * @return array<string, string>
	 */
	private static function get_upgrades(): array {
		return array(
			'0.1.0' => 'upgrade_to_0_1_0',
			'0.2.0' => 'upgrade_to_0_2_0',
			'0.3.0' => 'upgrade_to_0_3_0',
			'0.5.0' => 'upgrade_to_0_5_0',
			'1.0.0' => 'upgrade_to_1_0_0',
		);
	}

	// ── Migrations ───────────────────────────────────────────────────────────────

	/**
	 * 0.3.0 — add credential expiry columns.
	 *   wb_gam_badge_defs.validity_days  — days a credential remains valid (NULL = forever)
	 *   wb_gam_user_badges.expires_at    — computed on award from validity_days
	 */
	private static function upgrade_to_0_3_0(): void {
		global $wpdb;

		$defs  = $wpdb->prefix . 'wb_gam_badge_defs';
		$ubadg = $wpdb->prefix . 'wb_gam_user_badges';

		$def_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$defs}`", 0 );
		if ( ! in_array( 'validity_days', $def_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$defs}` ADD COLUMN `validity_days` INT UNSIGNED DEFAULT NULL AFTER `is_credential`" );
		}

		$badge_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$ubadg}`", 0 );
		if ( ! in_array( 'expires_at', $badge_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$ubadg}` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `earned_at`, ADD KEY `idx_expires_at` (`expires_at`)" );
		}
	}

	/**
	 * 0.2.0 — rename community_challenges columns to match CommunityChallengeEngine;
	 *          add composite and auxiliary indexes.
	 */
	private static function upgrade_to_0_2_0(): void {
		global $wpdb;

		$cc = $wpdb->prefix . 'wb_gam_community_challenges';

		// Rename columns only if the old names still exist.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$cc}`", 0 );

		if ( in_array( 'action_id', $cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$cc}` CHANGE `action_id` `target_action` VARCHAR(100) NOT NULL" );
		}
		if ( in_array( 'target', $cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$cc}` CHANGE `target` `target_count` BIGINT UNSIGNED NOT NULL" );
		}
		if ( in_array( 'current_count', $cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$cc}` CHANGE `current_count` `global_progress` BIGINT UNSIGNED DEFAULT 0" );
		}

		// Rename KEY action_id → target_action (MySQL 8+ syntax; safe to ignore failure on older versions).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$cc}` RENAME INDEX `action_id` TO `target_action`" );

		// Add composite index for cap-check queries on points ledger.
		$pts = $wpdb->prefix . 'wb_gam_points';
		$idx = $wpdb->get_var( "SHOW INDEX FROM `{$pts}` WHERE Key_name = 'idx_user_action_created'" );
		if ( ! $idx ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$pts}` ADD KEY `idx_user_action_created` (user_id, action_id, created_at)" );
		}

		// Index for leaderboard opt-out queries.
		$prefs = $wpdb->prefix . 'wb_gam_member_prefs';
		$idx   = $wpdb->get_var( "SHOW INDEX FROM `{$prefs}` WHERE Key_name = 'idx_opt_out'" );
		if ( ! $idx ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$prefs}` ADD KEY `idx_opt_out` (leaderboard_opt_out)" );
		}

		// Composite index for challenge status+action queries.
		$chal = $wpdb->prefix . 'wb_gam_challenges';
		$idx  = $wpdb->get_var( "SHOW INDEX FROM `{$chal}` WHERE Key_name = 'idx_status_action'" );
		if ( ! $idx ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$chal}` ADD KEY `idx_status_action` (status, action_id)" );
		}
	}

	/**
	 * 0.5.0 — add badge eligibility limits.
	 *   wb_gam_badge_defs.closes_at   — stop awarding after this datetime (NULL = no cutoff)
	 *   wb_gam_badge_defs.max_earners — stop awarding after N members earn it (NULL = unlimited)
	 */
	private static function upgrade_to_0_5_0(): void {
		global $wpdb;

		$defs     = $wpdb->prefix . 'wb_gam_badge_defs';
		$def_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$defs}`", 0 );

		if ( ! in_array( 'closes_at', $def_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$defs}` ADD COLUMN `closes_at` DATETIME DEFAULT NULL AFTER `validity_days`" );
		}

		if ( ! in_array( 'max_earners', $def_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$defs}` ADD COLUMN `max_earners` INT UNSIGNED DEFAULT NULL AFTER `closes_at`" );
		}
	}

	/**
	 * 0.1.0 → add `created_at` to wb_gam_challenge_log (missed in initial schema).
	 */
	private static function upgrade_to_0_1_0(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'wb_gam_challenge_log';
		$column = 'created_at';

		// Check if column already exists before altering.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				  WHERE TABLE_SCHEMA = %s
				    AND TABLE_NAME   = %s
				    AND COLUMN_NAME  = %s',
				DB_NAME,
				$table,
				$column
			)
		);

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				"ALTER TABLE `{$table}`
				 ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				 ADD KEY `created_at` (`created_at`)"
			);
		}
	}

	/**
	 * 1.0.0 → create wb_gam_leaderboard_cache table, add site_id to events, drop unused wb_gam_partners.
	 */
	private static function upgrade_to_1_0_0(): void {
		global $wpdb;

		$charset     = $wpdb->get_charset_collate();
		$cache_table = $wpdb->prefix . 'wb_gam_leaderboard_cache';

		// Create the leaderboard snapshot cache table if it does not exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$cache_table}` (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id      BIGINT UNSIGNED NOT NULL,
				period       VARCHAR(20)     NOT NULL DEFAULT 'all',
				total_points BIGINT          NOT NULL DEFAULT 0,
				rank         INT UNSIGNED    NOT NULL DEFAULT 0,
				updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_period_rank (period, rank),
				KEY idx_user_period (user_id, period)
			) {$charset};"
		);

		// Add site_id column to events table for cross-site attribution.
		$events = $wpdb->prefix . 'wb_gam_events';
		$cols   = $wpdb->get_col( "SHOW COLUMNS FROM `{$events}`", 0 );
		if ( ! in_array( 'site_id', $cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$events}` ADD COLUMN `site_id` VARCHAR(100) NOT NULL DEFAULT '' AFTER `metadata`, ADD KEY `idx_site_id` (`site_id`)" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wb_gam_partners" );
	}

}
