<?php
/**
 * WB Gamification Installer
 * Creates all custom DB tables on plugin activation.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

final class WB_Gam_Installer {

	public static function install(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Points ledger.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_points (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL,
			action_id  VARCHAR(100)    NOT NULL,
			points     INT             NOT NULL,
			object_id  BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY action_id (action_id),
			KEY created_at (created_at)
		) $charset;" );

		// Earned badges.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_user_badges (
			id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id   BIGINT UNSIGNED NOT NULL,
			badge_id  VARCHAR(100)    NOT NULL,
			earned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_badge (user_id, badge_id)
		) $charset;" );

		// Badge definitions.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_badge_defs (
			id            VARCHAR(100) NOT NULL,
			name          VARCHAR(255) NOT NULL,
			description   TEXT,
			image_url     VARCHAR(500),
			trigger_type  VARCHAR(50)  NOT NULL,
			trigger_value VARCHAR(255),
			created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;" );

		// Level definitions.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_levels (
			id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			name       VARCHAR(255)    NOT NULL,
			min_points BIGINT UNSIGNED NOT NULL,
			icon_url   VARCHAR(500),
			sort_order INT             DEFAULT 0,
			PRIMARY KEY (id),
			KEY min_points (min_points)
		) $charset;" );

		// Streaks.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_streaks (
			user_id        BIGINT UNSIGNED NOT NULL,
			current_streak INT UNSIGNED    DEFAULT 0,
			longest_streak INT UNSIGNED    DEFAULT 0,
			last_active    DATE,
			updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id)
		) $charset;" );

		// Challenges.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_challenges (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title        VARCHAR(255)    NOT NULL,
			action_id    VARCHAR(100)    NOT NULL,
			target       INT UNSIGNED    NOT NULL,
			bonus_points INT             NOT NULL DEFAULT 0,
			period       VARCHAR(20)     DEFAULT 'none',
			starts_at    DATETIME,
			ends_at      DATETIME,
			status       VARCHAR(20)     DEFAULT 'active',
			PRIMARY KEY (id),
			KEY status (status)
		) $charset;" );

		// Challenge progress.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_challenge_log (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			challenge_id BIGINT UNSIGNED NOT NULL,
			progress     INT UNSIGNED    DEFAULT 0,
			completed_at DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_challenge (user_id, challenge_id),
			KEY challenge_id (challenge_id)
		) $charset;" );

		// Seed default levels.
		self::seed_default_levels();

		update_option( 'wb_gam_db_version', WB_GAM_VERSION );
	}

	private static function seed_default_levels(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_levels';

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) > 0 ) {
			return;
		}

		$levels = [
			[ 'name' => 'Newcomer',    'min_points' => 0,     'sort_order' => 1 ],
			[ 'name' => 'Member',      'min_points' => 100,   'sort_order' => 2 ],
			[ 'name' => 'Contributor', 'min_points' => 500,   'sort_order' => 3 ],
			[ 'name' => 'Regular',     'min_points' => 1500,  'sort_order' => 4 ],
			[ 'name' => 'Champion',    'min_points' => 5000,  'sort_order' => 5 ],
		];

		foreach ( $levels as $level ) {
			$wpdb->insert( $table, $level );
		}
	}
}
