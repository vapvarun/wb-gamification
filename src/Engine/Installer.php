<?php
/**
 * WB Gamification Installer
 * Creates all custom DB tables on plugin activation.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function install(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Immutable event log — source of truth for all gamification state.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_events (
			id         VARCHAR(36)     NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			action_id  VARCHAR(100)    NOT NULL,
			object_id  BIGINT UNSIGNED DEFAULT NULL,
			metadata   LONGTEXT,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_user_action (user_id, action_id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_created (created_at)
		) $charset;" );

		// Points ledger — derived from events; event_id nullable until Phase 0 Engine is built.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_points (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id   VARCHAR(36)     DEFAULT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			action_id  VARCHAR(100)    NOT NULL,
			points     INT             NOT NULL,
			object_id  BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_event (event_id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_action (action_id),
			KEY idx_created (created_at)
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
		// Award conditions live in wb_gam_rules (type='badge_condition', target_id=badge_id).
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_badge_defs (
			id            VARCHAR(100) NOT NULL,
			name          VARCHAR(255) NOT NULL,
			description   TEXT,
			image_url     VARCHAR(500),
			is_credential TINYINT(1)   DEFAULT 0,
			category      VARCHAR(50)  DEFAULT 'general',
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
			timezone       VARCHAR(50)     DEFAULT 'UTC',
			grace_used     TINYINT(1)      DEFAULT 0,
			updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id)
		) $charset;" );

		// Challenges.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_challenges (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title          VARCHAR(255)    NOT NULL,
			type           VARCHAR(20)     DEFAULT 'individual',
			team_group_id  BIGINT UNSIGNED DEFAULT NULL,
			action_id      VARCHAR(100)    NOT NULL,
			target         INT UNSIGNED    NOT NULL,
			bonus_points   INT             NOT NULL DEFAULT 0,
			period         VARCHAR(20)     DEFAULT 'none',
			starts_at      DATETIME,
			ends_at        DATETIME,
			status         VARCHAR(20)     DEFAULT 'active',
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
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_challenge (user_id, challenge_id),
			KEY challenge_id (challenge_id),
			KEY created_at (created_at)
		) $charset;" );

		// Peer kudos.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_kudos (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			giver_id    BIGINT UNSIGNED NOT NULL,
			receiver_id BIGINT UNSIGNED NOT NULL,
			message     VARCHAR(255)    DEFAULT NULL,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY giver_date (giver_id, created_at),
			KEY receiver_id (receiver_id)
		) $charset;" );

		// Accountability partners.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_partners (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id_1  BIGINT UNSIGNED NOT NULL,
			user_id_2  BIGINT UNSIGNED NOT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY partner_pair (user_id_1, user_id_2)
		) $charset;" );

		// Member preferences.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_member_prefs (
			user_id               BIGINT UNSIGNED NOT NULL,
			leaderboard_opt_out   TINYINT(1)      DEFAULT 0,
			show_rank             TINYINT(1)      DEFAULT 1,
			notification_mode     VARCHAR(20)     DEFAULT 'smart',
			PRIMARY KEY (user_id)
		) $charset;" );

		// Stored rule configuration (badge conditions, point multipliers, etc.).
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_rules (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_type   VARCHAR(50)     NOT NULL,
			target_id   VARCHAR(100)    DEFAULT NULL,
			rule_config LONGTEXT        NOT NULL,
			is_active   TINYINT(1)      DEFAULT 1,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY rule_type (rule_type),
			KEY target_id (target_id)
		) $charset;" );

		// Outbound webhook registrations.
		dbDelta( "CREATE TABLE {$wpdb->prefix}wb_gam_webhooks (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url        VARCHAR(500)    NOT NULL,
			secret     VARCHAR(255)    NOT NULL,
			events     TEXT            NOT NULL,
			is_active  TINYINT(1)      DEFAULT 1,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;" );

		// Seed default levels.
		self::seed_default_levels();

		// Seed default badge library (30 badges).
		self::seed_default_badges();

		update_option( 'wb_gam_db_version', WB_GAM_VERSION );
	}

	/**
	 * Seed the default badge library — 30 badges across 5 categories.
	 * Runs only on fresh installs (skipped when badge_defs table already has rows).
	 */
	private static function seed_default_badges(): void {
		global $wpdb;
		$defs_table  = $wpdb->prefix . 'wb_gam_badge_defs';
		$rules_table = $wpdb->prefix . 'wb_gam_rules';

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $defs_table" ) > 0 ) {
			return;
		}

		// Badge definitions: id, name, description, category, is_credential.
		// is_credential = 1 → admin-awarded badge suitable for external sharing.
		$badges = [
			// ── Points milestones ──────────────────────────────────────────────
			[ 'century_club',        'Century Club',         'Earned your first 100 points.',                               'points',     0 ],
			[ 'five_hundred_strong', 'Five Hundred Strong',  'Reached 500 total points.',                                   'points',     0 ],
			[ 'thousand_points',     'Thousand Points Club', 'Earned 1,000 total points.',                                  'points',     0 ],
			[ 'five_thousand_points','Five Thousand Strong', 'Reached 5,000 total points.',                                 'points',     0 ],
			[ 'ten_thousand_points', 'Ten Thousand Club',    'Earned 10,000 total points.',                                 'points',     0 ],

			// ── WordPress ──────────────────────────────────────────────────────
			[ 'welcome',             'Welcome Aboard',       'Joined the community.',                                       'wordpress',  0 ],
			[ 'first_post',          'First Post',           'Published your very first post.',                             'wordpress',  0 ],
			[ 'prolific_writer',     'Prolific Writer',      'Published 10 posts.',                                         'wordpress',  0 ],
			[ 'content_creator',     'Content Creator',      'Published 25 posts — a committed contributor.',               'wordpress',  0 ],
			[ 'first_comment',       'First Comment',        'Left your first comment.',                                    'wordpress',  0 ],
			[ 'engaged_reader',      'Engaged Reader',       'Left 10 comments — always adding to the conversation.',       'wordpress',  0 ],

			// ── BuddyPress ────────────────────────────────────────────────────
			[ 'first_update',        'First Update',         'Posted your first activity update.',                          'buddypress', 0 ],
			[ 'active_member',       'Active Member',        'Posted 10 activity updates.',                                 'buddypress', 0 ],
			[ 'community_voice',     'Community Voice',      'Posted 50 activity updates — your voice shapes this community.', 'buddypress', 0 ],
			[ 'first_friend',        'First Connection',     'Made your first friend.',                                     'buddypress', 0 ],
			[ 'social_connector',    'Social Connector',     'Made 10 connections — growing the community network.',        'buddypress', 0 ],
			[ 'group_creator',       'Group Creator',        'Created your first group.',                                   'buddypress', 0 ],
			[ 'team_player',         'Team Player',          'Joined 3 or more groups.',                                    'buddypress', 0 ],
			[ 'profile_pro',         'Profile Pro',          'Completed your extended profile.',                            'buddypress', 0 ],
			[ 'reaction_magnet',     'Reaction Magnet',      'Received 10 reactions on your activity.',                     'buddypress', 0 ],
			[ 'comment_champion',    'Comment Champion',     'Commented on 20 activity updates.',                           'buddypress', 0 ],
			[ 'poll_pioneer',        'Poll Pioneer',         'Created your first poll.',                                    'buddypress', 0 ],
			[ 'blog_publisher',      'Blog Publisher',       'Published your first member blog post.',                      'buddypress', 0 ],

			// ── Special / admin-awarded ────────────────────────────────────────
			[ 'early_adopter',       'Early Adopter',        'One of the first members of this community.',                 'special',    0 ],
			[ 'founding_member',     'Founding Member',      'A founding member — here from the very beginning.',           'special',    1 ],
			[ 'top_contributor',     'Top Contributor',      'Recognized as a top community contributor.',                  'special',    1 ],
			[ 'mentor',              'Mentor',               'Helped guide and support other community members.',           'special',    1 ],
			[ 'kudos_champion',      'Kudos Champion',       'Recognized for spreading positivity across the community.',   'special',    0 ],
			[ 'event_host',          'Event Host',           'Hosted a community event.',                                   'special',    0 ],
			[ 'community_veteran',   'Community Veteran',    'A long-standing, valued member of this community.',           'special',    1 ],
		];

		// Conditions for auto-awarded badges (id => condition_type config).
		// Admin-awarded badges have no condition entry — awarded manually via API.
		$conditions = [
			// Points milestones.
			'century_club'         => [ 'condition_type' => 'point_milestone', 'points' => 100 ],
			'five_hundred_strong'  => [ 'condition_type' => 'point_milestone', 'points' => 500 ],
			'thousand_points'      => [ 'condition_type' => 'point_milestone', 'points' => 1000 ],
			'five_thousand_points' => [ 'condition_type' => 'point_milestone', 'points' => 5000 ],
			'ten_thousand_points'  => [ 'condition_type' => 'point_milestone', 'points' => 10000 ],

			// WordPress actions.
			'welcome'              => [ 'condition_type' => 'action_count', 'action_id' => 'wp_user_register', 'count' => 1 ],
			'first_post'           => [ 'condition_type' => 'action_count', 'action_id' => 'wp_first_post',    'count' => 1 ],
			'prolific_writer'      => [ 'condition_type' => 'action_count', 'action_id' => 'wp_publish_post',  'count' => 10 ],
			'content_creator'      => [ 'condition_type' => 'action_count', 'action_id' => 'wp_publish_post',  'count' => 25 ],
			'first_comment'        => [ 'condition_type' => 'action_count', 'action_id' => 'wp_leave_comment', 'count' => 1 ],
			'engaged_reader'       => [ 'condition_type' => 'action_count', 'action_id' => 'wp_leave_comment', 'count' => 10 ],

			// BuddyPress actions.
			'first_update'         => [ 'condition_type' => 'action_count', 'action_id' => 'bp_activity_update',    'count' => 1 ],
			'active_member'        => [ 'condition_type' => 'action_count', 'action_id' => 'bp_activity_update',    'count' => 10 ],
			'community_voice'      => [ 'condition_type' => 'action_count', 'action_id' => 'bp_activity_update',    'count' => 50 ],
			'first_friend'         => [ 'condition_type' => 'action_count', 'action_id' => 'bp_friends_accepted',   'count' => 1 ],
			'social_connector'     => [ 'condition_type' => 'action_count', 'action_id' => 'bp_friends_accepted',   'count' => 10 ],
			'group_creator'        => [ 'condition_type' => 'action_count', 'action_id' => 'bp_groups_create',      'count' => 1 ],
			'team_player'          => [ 'condition_type' => 'action_count', 'action_id' => 'bp_groups_join',        'count' => 3 ],
			'profile_pro'          => [ 'condition_type' => 'action_count', 'action_id' => 'bp_profile_complete',   'count' => 1 ],
			'reaction_magnet'      => [ 'condition_type' => 'action_count', 'action_id' => 'bp_reactions_received', 'count' => 10 ],
			'comment_champion'     => [ 'condition_type' => 'action_count', 'action_id' => 'bp_activity_comment',   'count' => 20 ],
			'poll_pioneer'         => [ 'condition_type' => 'action_count', 'action_id' => 'bp_polls_created',      'count' => 1 ],
			'blog_publisher'       => [ 'condition_type' => 'action_count', 'action_id' => 'bp_publish_post',       'count' => 1 ],

			// Special / admin-awarded — no auto condition.
			'early_adopter'        => [ 'condition_type' => 'admin_awarded' ],
			'founding_member'      => [ 'condition_type' => 'admin_awarded' ],
			'top_contributor'      => [ 'condition_type' => 'admin_awarded' ],
			'mentor'               => [ 'condition_type' => 'admin_awarded' ],
			'kudos_champion'       => [ 'condition_type' => 'admin_awarded' ],
			'event_host'           => [ 'condition_type' => 'admin_awarded' ],
			'community_veteran'    => [ 'condition_type' => 'admin_awarded' ],
		];

		foreach ( $badges as [ $id, $name, $description, $category, $is_credential ] ) {
			$wpdb->insert(
				$defs_table,
				[
					'id'            => $id,
					'name'          => $name,
					'description'   => $description,
					'category'      => $category,
					'is_credential' => $is_credential,
				],
				[ '%s', '%s', '%s', '%s', '%d' ]
			);

			if ( isset( $conditions[ $id ] ) ) {
				$wpdb->insert(
					$rules_table,
					[
						'rule_type'   => 'badge_condition',
						'target_id'   => $id,
						'rule_config' => wp_json_encode( $conditions[ $id ] ),
						'is_active'   => 1,
					],
					[ '%s', '%s', '%s', '%d' ]
				);
			}
		}
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
