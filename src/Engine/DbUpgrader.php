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
	 *
	 * Two passes:
	 *   1. Versioned migrations (0.1.0 → WB_GAM_VERSION) — the legacy upgrade pipeline.
	 *   2. Feature migrations — option-flag-gated, idempotent, run independently of
	 *      WB_GAM_VERSION so v1.0 critical-gap schemas land on dev boxes whose
	 *      `wb_gam_db_version` option is already higher than WB_GAM_VERSION.
	 */
	public static function init(): void {
		$current = get_option( self::OPT_KEY, '0.0.0' );

		if ( version_compare( $current, WB_GAM_VERSION, '<' ) ) {
			// Prevent concurrent upgrade runs (e.g. two simultaneous requests on activation).
			if ( ! get_transient( self::LOCK_KEY ) ) {
				set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

				self::run( $current );

				update_option( self::OPT_KEY, WB_GAM_VERSION );
				delete_transient( self::LOCK_KEY );
			}
		}

		// Feature migrations always run (gated by per-feature option flags).
		self::ensure_feature_migrations();
	}

	/**
	 * Run idempotent feature-flag-gated schema migrations.
	 *
	 * Each migration uses its own option flag so it runs exactly once per site,
	 * decoupled from `wb_gam_db_version` / `WB_GAM_VERSION`. This lets v1.0 cycle
	 * features add schema without bumping the public version, and keeps internal
	 * dev boxes (db_version=1.2.0 from pre-launch iterations) in sync.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_feature_migrations(): void {
		self::ensure_point_types_schema();
		self::ensure_redemption_point_type_column();
		self::ensure_point_type_conversions_table();
		self::ensure_leaderboard_cache_point_type_column();
		self::ensure_user_totals_table();
		self::ensure_leaderboard_cache_unique_key();
	}

	/**
	 * Add a UNIQUE KEY (user_id, period, point_type) to wb_gam_leaderboard_cache
	 * so write_snapshot can use INSERT ... ON DUPLICATE KEY UPDATE instead
	 * of TRUNCATE + INSERT. Removes the brief read-through window on every
	 * 5-minute cron tick where reads fell back to the live SUM.
	 *
	 * Idempotent: feature-flag gated. Drops the legacy
	 * idx_user_type_period non-unique key first since it would conflict
	 * with the new UNIQUE on the same columns.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_leaderboard_cache_unique_key(): void {
		$flag_key = 'wb_gam_feature_leaderboard_cache_unique_key_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_leaderboard_cache';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (array) $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 );

		if ( in_array( 'idx_user_type_period', $existing, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX idx_user_type_period" );
		}
		if ( ! in_array( 'uniq_user_period_type', $existing, true ) ) {
			// Truncate first to avoid duplicate-key collisions on existing rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE `{$table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY uniq_user_period_type (user_id, period, point_type)" );
		}

		update_option( $flag_key, '1' );
	}

	/**
	 * Create + backfill `wb_gam_user_totals` so PointsEngine::get_total() can
	 * read a single PK row instead of running SUM against the full ledger.
	 *
	 * Idempotent: feature-flag gated. Backfill is one-shot — uses
	 * `INSERT ... ON DUPLICATE KEY UPDATE` with a SUM subquery so subsequent
	 * runs are no-ops on already-populated rows.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_user_totals_table(): void {
		$flag_key = 'wb_gam_feature_user_totals_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_user_totals (
			user_id    BIGINT UNSIGNED NOT NULL,
			point_type VARCHAR(60)     NOT NULL DEFAULT 'points',
			total      BIGINT          NOT NULL DEFAULT 0,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id, point_type),
			KEY idx_type_total (point_type, total)
		) $charset;"
		);

		// One-shot backfill from existing ledger.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"INSERT INTO {$wpdb->prefix}wb_gam_user_totals (user_id, point_type, total)
			 SELECT user_id, point_type, COALESCE(SUM(points), 0)
			   FROM {$wpdb->prefix}wb_gam_points
			  GROUP BY user_id, point_type
			 ON DUPLICATE KEY UPDATE total = VALUES(total)"
		);

		update_option( $flag_key, '1' );
	}

	/**
	 * Add `point_type` column to `wb_gam_leaderboard_cache` so the snapshot
	 * cron can pre-aggregate per-currency rankings (otherwise a multi-currency
	 * site falls through to the live SUM query against `wb_gam_points` for
	 * every non-primary leaderboard read — fatal at 100k users).
	 *
	 * Idempotent: column existence guard + INSERT IGNORE-style flag.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_leaderboard_cache_point_type_column(): void {
		$flag_key = 'wb_gam_feature_leaderboard_cache_point_type_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_leaderboard_cache';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrapped table name.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'point_type' ) );

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrap-time DDL.
			$wpdb->query( "ALTER TABLE $table ADD COLUMN point_type VARCHAR(60) NOT NULL DEFAULT 'points' AFTER period" );
			// Drop legacy single-type indexes; replace with type-aware composites.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE $table DROP INDEX idx_period_rank, DROP INDEX idx_user_period, ADD KEY idx_type_period_rank (point_type, period, `rank`), ADD KEY idx_user_type_period (user_id, point_type, period)" );
			// Existing rows were filled with DEFAULT 'points' but on a multi-
			// currency site they were aggregated across all currencies — the
			// data is misleading. TRUNCATE so the next snapshot cron rebuilds
			// every (period × point_type) combination cleanly. Reads will
			// fall through to the live SUM until the next 5-minute cron tick.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE $table" );
		}

		update_option( $flag_key, '1' );
	}

	/**
	 * Create the `wb_gam_point_type_conversions` table on existing sites.
	 * Idempotent — `dbDelta` is safe to re-run; flag stops the work after first
	 * successful pass.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_point_type_conversions_table(): void {
		$flag_key = 'wb_gam_feature_point_type_conversions_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_point_type_conversions (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			from_type           VARCHAR(60)     NOT NULL,
			to_type             VARCHAR(60)     NOT NULL,
			from_amount         INT UNSIGNED    NOT NULL,
			to_amount           INT UNSIGNED    NOT NULL,
			min_convert         INT UNSIGNED    NOT NULL DEFAULT 1,
			cooldown_seconds    INT UNSIGNED    NOT NULL DEFAULT 0,
			max_per_day         INT UNSIGNED    NOT NULL DEFAULT 0,
			is_active           TINYINT(1)      NOT NULL DEFAULT 1,
			created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY pair (from_type, to_type),
			KEY idx_from_active (from_type, is_active),
			KEY idx_to_active (to_type, is_active)
		) $charset;"
		);

		update_option( $flag_key, '1' );
	}

	/**
	 * Add `point_type` column to `wb_gam_redemption_items` so each reward can
	 * be priced in a specific currency. Idempotent — guards on column existence.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_redemption_point_type_column(): void {
		$flag_key = 'wb_gam_feature_redemption_point_type_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_redemption_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrapped table name.
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'point_type' ) );

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrap-time DDL.
			$wpdb->query( "ALTER TABLE $table ADD COLUMN point_type VARCHAR(60) NOT NULL DEFAULT 'points' AFTER points_cost" );
		}

		update_option( $flag_key, '1' );
	}

	/**
	 * Ensure `wb_gam_point_types` table exists and `point_type` columns are present
	 * on `wb_gam_points` and `wb_gam_events`.
	 *
	 * Idempotent — guards on column existence (information_schema lookup) and uses
	 * `INSERT IGNORE` for the default-type seed.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_point_types_schema(): void {
		$flag_key = 'wb_gam_feature_point_types_v1';
		if ( get_option( $flag_key ) ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Create the point_types table if missing (dbDelta is idempotent).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_point_types (
			slug        VARCHAR(60)     NOT NULL,
			label       VARCHAR(100)    NOT NULL,
			description TEXT,
			icon        VARCHAR(100)    DEFAULT NULL,
			is_default  TINYINT(1)      NOT NULL DEFAULT 0,
			position    INT UNSIGNED    NOT NULL DEFAULT 0,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (slug),
			KEY idx_default (is_default)
		) $charset;"
		);

		// 2. Add point_type column to wb_gam_points if missing.
		self::add_point_type_column( $wpdb->prefix . 'wb_gam_points', 'AFTER points' );

		// 3. Add point_type column to wb_gam_events if missing.
		self::add_point_type_column( $wpdb->prefix . 'wb_gam_events', 'AFTER metadata' );

		// 4. Seed the default 'points' type. INSERT IGNORE = no duplicate on re-run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrapped table name; INSERT IGNORE on PK.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}wb_gam_point_types (slug, label, description, icon, is_default, position) VALUES (%s, %s, %s, %s, %d, %d)",
				'points',
				'Points',
				'Primary points currency. Renamable; the slug stays as `points` for back-compat.',
				'star',
				1,
				0
			)
		);

		update_option( $flag_key, '1' );
	}

	/**
	 * Add a `point_type VARCHAR(60) NOT NULL DEFAULT 'points'` column to a table
	 * if it does not already exist.
	 *
	 * @param string $table       Fully-qualified table name (with prefix).
	 * @param string $position_sql `AFTER <col>` or empty string for end-of-row.
	 */
	private static function add_point_type_column( string $table, string $position_sql ): void {
		global $wpdb;

		// Check existing columns. SHOW COLUMNS is the idiomatic guard for ALTER idempotency.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated upstream.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM $table LIKE %s",
				'point_type'
			)
		);

		if ( $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrap-time DDL; column / table names validated upstream; default is a static string literal.
		$wpdb->query( "ALTER TABLE $table ADD COLUMN point_type VARCHAR(60) NOT NULL DEFAULT 'points' $position_sql" );

		// Add the user/type/created index for fast per-type balance queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrap-time index creation.
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM $table WHERE Key_name = %s",
				'idx_user_type_created'
			)
		);
		if ( ! $index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrap-time DDL.
			$wpdb->query( "ALTER TABLE $table ADD KEY idx_user_type_created (user_id, point_type, created_at)" );
		}
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
			'1.1.0' => 'upgrade_to_1_1_0',
			'1.2.0' => 'upgrade_to_1_2_0',
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
		// Note: `rank` is a MySQL 8.0 reserved word — must be backtick-escaped.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$cache_table}` (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id      BIGINT UNSIGNED NOT NULL,
				period       VARCHAR(20)     NOT NULL DEFAULT 'all',
				total_points BIGINT          NOT NULL DEFAULT 0,
				`rank`       INT UNSIGNED    NOT NULL DEFAULT 0,
				updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_period_rank (period, `rank`),
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

	/**
	 * 1.1.0 → drop the cosmetics tables.
	 *
	 * Per plans/ARCHITECTURE-DRIVEN-PLAN.md, the CosmeticEngine had no
	 * user-facing surface (no admin, no REST, no block) — a Tier-violation
	 * from the engine surface contract. The class is removed in v1.1.0;
	 * this migration drops the two tables it used.
	 *
	 * Safe to re-run: DROP TABLE IF EXISTS is idempotent. Clean v1.1.0
	 * installs that never had the tables are a no-op.
	 *
	 * NOTE: PersonalRecordEngine is preserved as an Internal-only tier
	 * engine — it computes derived state (wb_gam_pr_best_week/day/month
	 * user_meta) consumed by WeeklyEmailEngine's "Personal best!" badge.
	 * Its lack of standalone surface is by-design, not a violation.
	 */
	private static function upgrade_to_1_1_0(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wb_gam_user_cosmetics" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wb_gam_cosmetics" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * 1.2.0 — Backfill `image_url` on the seeded badge defs.
	 *
	 * The plugin has shipped 37 ready-made badge SVGs in `assets/badges/`
	 * since 0.4.0, but `Installer::seed_default_badges()` was inserting
	 * rows with `image_url = NULL`, so `badge-showcase` blocks rendered
	 * the 🏅 placeholder for every default badge. This migration walks
	 * the badge_defs table on existing installs and links each row to
	 * its bundled SVG via `Installer::default_badge_image_url()`,
	 * touching only rows whose `image_url` is currently NULL or empty
	 * so admin-customised artwork is preserved.
	 */
	private static function upgrade_to_1_2_0(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_badge_defs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$ids = $wpdb->get_col( "SELECT id FROM `{$table}` WHERE image_url IS NULL OR image_url = ''" );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			$url = Installer::default_badge_image_url( (string) $id );
			if ( '' === $url ) {
				continue;
			}
			$wpdb->update(
				$table,
				array( 'image_url' => $url ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%s' )
			);
		}
	}
}
