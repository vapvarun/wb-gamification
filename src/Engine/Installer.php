<?php
/**
 * WB Gamification Installer
 * Creates all custom DB tables on plugin activation.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Creates all custom DB tables on plugin activation.
 *
 * @package WB_Gamification
 */
final class Installer {

	/**
	 * Create all required tables and seed default data.
	 */
	public static function install(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Immutable event log — source of truth for all gamification state.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_events (
			id         VARCHAR(36)     NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			action_id  VARCHAR(100)    NOT NULL,
			object_id  BIGINT UNSIGNED DEFAULT NULL,
			metadata   LONGTEXT,
			site_id    VARCHAR(100)    NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_user_action (user_id, action_id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_created (created_at),
			KEY idx_site_id (site_id)
		) $charset;"
		);

		// Points ledger — derived from events; event_id nullable until Phase 0 Engine is built.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_points (
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
			KEY idx_user_action_created (user_id, action_id, created_at),
			KEY idx_action (action_id),
			KEY idx_created (created_at)
		) $charset;"
		);

		// Earned badges.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_user_badges (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL,
			badge_id   VARCHAR(100)    NOT NULL,
			earned_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_badge (user_id, badge_id),
			KEY idx_expires_at (expires_at)
		) $charset;"
		);

		// Badge definitions.
		// Award conditions live in wb_gam_rules (type='badge_condition', target_id=badge_id).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_badge_defs (
			id            VARCHAR(100)  NOT NULL,
			name          VARCHAR(255)  NOT NULL,
			description   TEXT,
			image_url     VARCHAR(500),
			is_credential TINYINT(1)    DEFAULT 0,
			validity_days INT UNSIGNED  DEFAULT NULL,
			closes_at     DATETIME      DEFAULT NULL,
			max_earners   INT UNSIGNED  DEFAULT NULL,
			category      VARCHAR(50)   DEFAULT 'general',
			created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;"
		);

		// Level definitions.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_levels (
			id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			name       VARCHAR(255)    NOT NULL,
			min_points BIGINT UNSIGNED NOT NULL,
			icon_url   VARCHAR(500),
			sort_order INT             DEFAULT 0,
			PRIMARY KEY (id),
			KEY min_points (min_points)
		) $charset;"
		);

		// Streaks.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_streaks (
			user_id        BIGINT UNSIGNED NOT NULL,
			current_streak INT UNSIGNED    DEFAULT 0,
			longest_streak INT UNSIGNED    DEFAULT 0,
			last_active    DATE,
			timezone       VARCHAR(50)     DEFAULT 'UTC',
			grace_used     TINYINT(1)      DEFAULT 0,
			updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id)
		) $charset;"
		);

		// Challenges.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_challenges (
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
			KEY status (status),
			KEY idx_status_action (status, action_id)
		) $charset;"
		);

		// Challenge progress.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_challenge_log (
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
		) $charset;"
		);

		// Peer kudos.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_kudos (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			giver_id    BIGINT UNSIGNED NOT NULL,
			receiver_id BIGINT UNSIGNED NOT NULL,
			message     VARCHAR(255)    DEFAULT NULL,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY giver_date (giver_id, created_at),
			KEY receiver_id (receiver_id)
		) $charset;"
		);

		// Member preferences.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_member_prefs (
			user_id               BIGINT UNSIGNED NOT NULL,
			leaderboard_opt_out   TINYINT(1)      DEFAULT 0,
			show_rank             TINYINT(1)      DEFAULT 1,
			notification_mode     VARCHAR(20)     DEFAULT 'smart',
			PRIMARY KEY (user_id),
			KEY idx_opt_out (leaderboard_opt_out)
		) $charset;"
		);

		// Stored rule configuration (badge conditions, point multipliers, etc.).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_rules (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_type   VARCHAR(50)     NOT NULL,
			target_id   VARCHAR(100)    DEFAULT NULL,
			rule_config LONGTEXT        NOT NULL,
			is_active   TINYINT(1)      DEFAULT 1,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY rule_type (rule_type),
			KEY target_id (target_id)
		) $charset;"
		);

		// Outbound webhook registrations.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_webhooks (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url        VARCHAR(500)    NOT NULL,
			secret     VARCHAR(255)    NOT NULL,
			events     TEXT            NOT NULL,
			is_active  TINYINT(1)      DEFAULT 1,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset;"
		);

		// Community challenges (global counter, Pokémon GO model).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_community_challenges (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title           VARCHAR(255)    NOT NULL,
			description     TEXT,
			target_action   VARCHAR(100)    NOT NULL,
			target_count    BIGINT UNSIGNED NOT NULL,
			global_progress BIGINT UNSIGNED DEFAULT 0,
			bonus_points    INT             NOT NULL DEFAULT 0,
			status          VARCHAR(20)     DEFAULT 'active',
			starts_at       DATETIME        DEFAULT NULL,
			ends_at         DATETIME        DEFAULT NULL,
			completed_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY target_action (target_action)
		) $charset;"
		);

		// Per-user contribution to community challenges.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_community_challenge_contributions (
			challenge_id       BIGINT UNSIGNED NOT NULL,
			user_id            BIGINT UNSIGNED NOT NULL,
			contribution_count BIGINT UNSIGNED DEFAULT 0,
			PRIMARY KEY (challenge_id, user_id),
			KEY user_id (user_id)
		) $charset;"
		);

		// Cohort league members (Duolingo model).
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_cohort_members (
			user_id    BIGINT UNSIGNED  NOT NULL,
			cohort_id  VARCHAR(50)      NOT NULL,
			tier       TINYINT UNSIGNED DEFAULT 0,
			tier_end   TINYINT UNSIGNED DEFAULT NULL,
			outcome    VARCHAR(20)      DEFAULT NULL,
			week       VARCHAR(10)      NOT NULL,
			pts_start  INT UNSIGNED     DEFAULT 0,
			PRIMARY KEY (user_id, week),
			KEY cohort_id (cohort_id),
			KEY week (week)
		) $charset;"
		);

		// Redemption reward items catalog.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_redemption_items (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title         VARCHAR(255)    NOT NULL,
			description   TEXT,
			points_cost   INT UNSIGNED    NOT NULL,
			reward_type   VARCHAR(50)     NOT NULL,
			reward_config LONGTEXT,
			stock         INT UNSIGNED    DEFAULT NULL,
			is_active     TINYINT(1)      DEFAULT 1,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY is_active (is_active)
		) $charset;"
		);

		// Redemption transaction log.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_redemptions (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id     BIGINT UNSIGNED NOT NULL,
			item_id     BIGINT UNSIGNED NOT NULL,
			points_cost INT UNSIGNED    NOT NULL,
			status      VARCHAR(30)     DEFAULT 'pending',
			coupon_code VARCHAR(100)    DEFAULT NULL,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY item_id (item_id),
			KEY created_at (created_at)
		) $charset;"
		);

		// Leaderboard snapshot cache — written by cron, read by LeaderboardEngine.
		// Note: `rank` is a MySQL 8.0 reserved word — must be backtick-escaped.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_leaderboard_cache (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			period       VARCHAR(20)     NOT NULL DEFAULT 'all',
			total_points BIGINT          NOT NULL DEFAULT 0,
			`rank`       INT UNSIGNED    NOT NULL DEFAULT 0,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_period_rank (period, `rank`),
			KEY idx_user_period (user_id, period)
		) $charset;"
		);

		// Seed default levels.
		self::seed_default_levels();

		// Seed default badge library (30 badges).
		self::seed_default_badges();

		update_option( 'wb_gam_db_version', WB_GAM_VERSION );

		// Auto-create Gamification hub page if it doesn't exist.
		self::maybe_create_hub_page();
	}

	/**
	 * Create the Gamification hub page if one doesn't already exist.
	 *
	 * Uses post meta `_wb_gam_hub_page` to detect existing pages,
	 * preventing duplicates on reactivation.
	 *
	 * @since 1.0.0
	 */
	private static function maybe_create_hub_page(): void {
		$existing = get_posts(
			array(
				'post_type' => 'page',
				'meta_key'  => '_wb_gam_hub_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'    => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'numberposts'   => 1,
			'post_status'   => array( 'publish', 'draft', 'private', 'trash' ),
			'fields'        => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			update_option( 'wb_gam_hub_page_id', $existing[0], false );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Gamification', 'wb-gamification' ),
				'post_content' => '<!-- wp:wb-gamification/hub /-->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id() ?: 1,
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_post_meta( $page_id, '_wb_gam_hub_page', '1' );
			update_option( 'wb_gam_hub_page_id', $page_id, false );
		}
	}

	/**
	 * Seed the default badge library — 30 badges across 5 categories.
	 * Runs only on fresh installs (skipped when badge_defs table already has rows).
	 */
	private static function seed_default_badges(): void {
		global $wpdb;
		$defs_table  = $wpdb->prefix . 'wb_gam_badge_defs';
		$rules_table = $wpdb->prefix . 'wb_gam_rules';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix, no user data.
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $defs_table" ) > 0 ) {
			return;
		}

		// Badge definitions: id, name, description, category, is_credential.
		// is_credential = 1 → admin-awarded badge suitable for external sharing.
		$badges = array(
			// ── Points milestones ──────────────────────────────────────────────
			array( 'century_club', 'Century Club', 'Earned your first 100 points.', 'points', 0 ),
			array( 'five_hundred_strong', 'Five Hundred Strong', 'Reached 500 total points.', 'points', 0 ),
			array( 'thousand_points', 'Thousand Points Club', 'Earned 1,000 total points.', 'points', 0 ),
			array( 'five_thousand_points', 'Five Thousand Strong', 'Reached 5,000 total points.', 'points', 0 ),
			array( 'ten_thousand_points', 'Ten Thousand Club', 'Earned 10,000 total points.', 'points', 0 ),

			// ── WordPress ──────────────────────────────────────────────────────
			array( 'welcome', 'Welcome Aboard', 'Joined the community.', 'wordpress', 0 ),
			array( 'first_post', 'First Post', 'Published your very first post.', 'wordpress', 0 ),
			array( 'prolific_writer', 'Prolific Writer', 'Published 10 posts.', 'wordpress', 0 ),
			array( 'content_creator', 'Content Creator', 'Published 25 posts — a committed contributor.', 'wordpress', 0 ),
			array( 'first_comment', 'First Comment', 'Left your first comment.', 'wordpress', 0 ),
			array( 'engaged_reader', 'Engaged Reader', 'Left 10 comments — always adding to the conversation.', 'wordpress', 0 ),

			// ── BuddyPress ────────────────────────────────────────────────────
			array( 'first_update', 'First Update', 'Posted your first activity update.', 'buddypress', 0 ),
			array( 'active_member', 'Active Member', 'Posted 10 activity updates.', 'buddypress', 0 ),
			array( 'community_voice', 'Community Voice', 'Posted 50 activity updates — your voice shapes this community.', 'buddypress', 0 ),
			array( 'first_friend', 'First Connection', 'Made your first friend.', 'buddypress', 0 ),
			array( 'social_connector', 'Social Connector', 'Made 10 connections — growing the community network.', 'buddypress', 0 ),
			array( 'group_creator', 'Group Creator', 'Created your first group.', 'buddypress', 0 ),
			array( 'team_player', 'Team Player', 'Joined 3 or more groups.', 'buddypress', 0 ),
			array( 'profile_pro', 'Profile Pro', 'Completed your extended profile.', 'buddypress', 0 ),
			array( 'reaction_magnet', 'Reaction Magnet', 'Received 10 reactions on your activity.', 'buddypress', 0 ),
			array( 'comment_champion', 'Comment Champion', 'Commented on 20 activity updates.', 'buddypress', 0 ),
			array( 'poll_pioneer', 'Poll Pioneer', 'Created your first poll.', 'buddypress', 0 ),
			array( 'blog_publisher', 'Blog Publisher', 'Published your first member blog post.', 'buddypress', 0 ),

			// ── Special / admin-awarded ────────────────────────────────────────
			array( 'early_adopter', 'Early Adopter', 'One of the first members of this community.', 'special', 0 ),
			array( 'founding_member', 'Founding Member', 'A founding member — here from the very beginning.', 'special', 1 ),
			array( 'top_contributor', 'Top Contributor', 'Recognized as a top community contributor.', 'special', 1 ),
			array( 'mentor', 'Mentor', 'Helped guide and support other community members.', 'special', 1 ),
			array( 'kudos_champion', 'Kudos Champion', 'Recognized for spreading positivity across the community.', 'special', 0 ),
			array( 'event_host', 'Event Host', 'Hosted a community event.', 'special', 0 ),
			array( 'community_veteran', 'Community Veteran', 'A long-standing, valued member of this community.', 'special', 1 ),
		);

		// Conditions for auto-awarded badges (id => condition_type config).
		// Admin-awarded badges have no condition entry — awarded manually via API.
		$conditions = array(
			// Points milestones.
			'century_club'         => array(
				'condition_type' => 'point_milestone',
				'points'         => 100,
			),
			'five_hundred_strong'  => array(
				'condition_type' => 'point_milestone',
				'points'         => 500,
			),
			'thousand_points'      => array(
				'condition_type' => 'point_milestone',
				'points'         => 1000,
			),
			'five_thousand_points' => array(
				'condition_type' => 'point_milestone',
				'points'         => 5000,
			),
			'ten_thousand_points'  => array(
				'condition_type' => 'point_milestone',
				'points'         => 10000,
			),

			// WordPress actions — wp_user_register is always-on (not standalone_only).
			'welcome'              => array(
				'condition_type' => 'action_count',
				'action_id'      => 'wp_user_register',
				'count'          => 1,
			),
			// Post/comment badges use point milestones instead of action counts.
			// This ensures they work on both standalone WP and BuddyPress installs,
			// regardless of which action IDs are registered.
			'first_post'           => array(
				'condition_type' => 'point_milestone',
				'points'         => 25,
			),
			'prolific_writer'      => array(
				'condition_type' => 'point_milestone',
				'points'         => 250,
			),
			'content_creator'      => array(
				'condition_type' => 'point_milestone',
				'points'         => 750,
			),
			'first_comment'        => array(
				'condition_type' => 'point_milestone',
				'points'         => 50,
			),
			'engaged_reader'       => array(
				'condition_type' => 'point_milestone',
				'points'         => 150,
			),

			// BuddyPress actions.
			'first_update'         => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_activity_update',
				'count'          => 1,
			),
			'active_member'        => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_activity_update',
				'count'          => 10,
			),
			'community_voice'      => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_activity_update',
				'count'          => 50,
			),
			'first_friend'         => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_friends_accepted',
				'count'          => 1,
			),
			'social_connector'     => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_friends_accepted',
				'count'          => 10,
			),
			'group_creator'        => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_groups_create',
				'count'          => 1,
			),
			'team_player'          => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_groups_join',
				'count'          => 3,
			),
			'profile_pro'          => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_profile_complete',
				'count'          => 1,
			),
			'reaction_magnet'      => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_reactions_received',
				'count'          => 10,
			),
			'comment_champion'     => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_activity_comment',
				'count'          => 20,
			),
			'poll_pioneer'         => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_polls_created',
				'count'          => 1,
			),
			'blog_publisher'       => array(
				'condition_type' => 'action_count',
				'action_id'      => 'bp_publish_post',
				'count'          => 1,
			),

			// Special / admin-awarded — no auto condition.
			'early_adopter'        => array( 'condition_type' => 'admin_awarded' ),
			'founding_member'      => array( 'condition_type' => 'admin_awarded' ),
			'top_contributor'      => array( 'condition_type' => 'admin_awarded' ),
			'mentor'               => array( 'condition_type' => 'admin_awarded' ),
			'kudos_champion'       => array( 'condition_type' => 'admin_awarded' ),
			'event_host'           => array( 'condition_type' => 'admin_awarded' ),
			'community_veteran'    => array( 'condition_type' => 'admin_awarded' ),
		);

		foreach ( $badges as [ $id, $name, $description, $category, $is_credential ] ) {
			$wpdb->insert(
				$defs_table,
				array(
					'id'            => $id,
					'name'          => $name,
					'description'   => $description,
					'category'      => $category,
					'is_credential' => $is_credential,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);

			if ( isset( $conditions[ $id ] ) ) {
				$wpdb->insert(
					$rules_table,
					array(
						'rule_type'   => 'badge_condition',
						'target_id'   => $id,
						'rule_config' => wp_json_encode( $conditions[ $id ] ),
						'is_active'   => 1,
					),
					array( '%s', '%s', '%s', '%d' )
				);
			}
		}
	}

	/**
	 * Seed the default level progression (Newcomer through Champion).
	 * Runs only on fresh installs — skipped when the levels table already has rows.
	 */
	private static function seed_default_levels(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_levels';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix, no user data.
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) > 0 ) {
			return;
		}

		$levels = array(
			array(
				'name'       => 'Newcomer',
				'min_points' => 0,
				'sort_order' => 1,
			),
			array(
				'name'       => 'Member',
				'min_points' => 100,
				'sort_order' => 2,
			),
			array(
				'name'       => 'Contributor',
				'min_points' => 500,
				'sort_order' => 3,
			),
			array(
				'name'       => 'Regular',
				'min_points' => 1500,
				'sort_order' => 4,
			),
			array(
				'name'       => 'Champion',
				'min_points' => 5000,
				'sort_order' => 5,
			),
		);

		foreach ( $levels as $level ) {
			$wpdb->insert( $table, $level );
		}
	}
}
