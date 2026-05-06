<?php
/**
 * Site-First Badge Engine
 *
 * Awards one-time "first ever" badges to the first member who hits a milestone.
 * Once awarded to one user, no other user can earn that badge — it becomes a
 * permanent community record (Xbox/Steam first achievement model).
 *
 * Tracked milestones:
 *   first_champion      — First member to reach "Champion" level
 *   first_10k_points    — First member to reach 10,000 total points
 *   first_100_day_streak — First member to reach a 100-day streak
 *
 * Hooks into the existing badge system — seeds definitions in wb_gam_badge_defs,
 * conditions are evaluated here (not via the generic BadgeEngine condition loop)
 * because they require a site-wide uniqueness check.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Awards one-time site-first badges to the first member who hits each milestone.
 *
 * @package WB_Gamification
 */
final class SiteFirstBadgeEngine {

	/**
	 * Badge definitions seeded on install.
	 *
	 * @var array<string, array>
	 */
	private const SITE_FIRST_BADGES = array(
		'first_champion'       => array(
			'name'        => 'First Champion',
			'description' => 'The first member of this community to reach Champion rank.',
			'category'    => 'special',
			'trigger'     => 'level_changed',
		),
		'first_10k_points'     => array(
			'name'        => 'First to 10,000',
			'description' => 'The first member to earn 10,000 total points in this community.',
			'category'    => 'special',
			'trigger'     => 'points_awarded',
		),
		'first_100_day_streak' => array(
			'name'        => 'Century Streak Pioneer',
			'description' => 'The first member to reach a 100-day activity streak.',
			'category'    => 'special',
			'trigger'     => 'streak_milestone',
		),
	);

	/**
	 * Register hooks for level-changed, points-awarded, streak-milestone, and badge seeding.
	 */
	public static function init(): void {
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_changed' ), 10, 3 );
		add_action( 'wb_gam_points_awarded', array( __CLASS__, 'on_points_awarded' ), 30, 3 );
		add_action( 'wb_gam_streak_milestone', array( __CLASS__, 'on_streak_milestone' ), 10, 2 );
		add_action( 'plugins_loaded', array( __CLASS__, 'ensure_badges_exist' ), 20 );
	}

	// ── Hooks ────────────────────────────────────────────────────────────────

	/**
	 * Check for first-champion badge on level change.
	 *
	 * LevelEngine fires: do_action( 'wb_gam_level_changed', $user_id, $old_level_id, $new_level_id )
	 *
	 * @param int $user_id      User who levelled up.
	 * @param int $old_level_id Previous level ID.
	 * @param int $new_level_id New level ID.
	 */
	public static function on_level_changed( int $user_id, int $old_level_id, int $new_level_id ): void {
		global $wpdb;

		$level_name = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}wb_gam_levels WHERE id = %d",
				$new_level_id
			)
		);

		if ( 'Champion' === $level_name ) {
			self::maybe_award( 'first_champion', $user_id );
		}
	}

	/**
	 * Check for first-10k-points badge on each point award.
	 *
	 * @param int   $user_id User who earned points.
	 * @param Event $event   Source event.
	 * @param int   $points  Points awarded.
	 */
	public static function on_points_awarded( int $user_id, Event $event, int $points ): void {
		$total = PointsEngine::get_total( $user_id );
		if ( $total >= 10000 ) {
			self::maybe_award( 'first_10k_points', $user_id );
		}
	}

	/**
	 * Check for first-100-day-streak badge on streak milestone.
	 *
	 * @param int $user_id       User who hit the milestone.
	 * @param int $streak_length Current streak length in days.
	 */
	public static function on_streak_milestone( int $user_id, int $streak_length ): void {
		if ( $streak_length >= 100 ) {
			self::maybe_award( 'first_100_day_streak', $user_id );
		}
	}

	// ── Core logic ───────────────────────────────────────────────────────────

	/**
	 * Award badge only if no one else has earned it yet.
	 *
	 * @param string $badge_id Badge ID to check and award.
	 * @param int    $user_id  User to award the badge to if still unclaimed.
	 */
	private static function maybe_award( string $badge_id, int $user_id ): void {
		global $wpdb;

		// Check if the badge has already been awarded to anyone.
		$already_awarded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id = %s",
				$badge_id
			)
		);

		if ( $already_awarded > 0 ) {
			return; // Already claimed — site-first is gone.
		}

		// Race-safe: use a transient lock before awarding.
		$lock_key = 'wb_gam_site_first_' . $badge_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 10 );

		// Double-check inside lock.
		$already_awarded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id = %s",
				$badge_id
			)
		);

		if ( 0 === $already_awarded ) {
			BadgeEngine::award_badge( $user_id, $badge_id );
		}

		delete_transient( $lock_key );
	}

	// ── Badge seeding ────────────────────────────────────────────────────────

	/**
	 * Seed site-first badge definitions into wb_gam_badge_defs if they do not yet exist.
	 */
	public static function ensure_badges_exist(): void {
		global $wpdb;

		foreach ( self::SITE_FIRST_BADGES as $id => $def ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = %s", $id )
			);

			if ( ! $exists ) {
				$wpdb->insert(
					$wpdb->prefix . 'wb_gam_badge_defs',
					array(
						'id'            => $id,
						'name'          => $def['name'],
						'description'   => $def['description'],
						'category'      => $def['category'],
						'is_credential' => 1,
					),
					array( '%s', '%s', '%s', '%s', '%d' )
				);
			}
		}
	}
}
