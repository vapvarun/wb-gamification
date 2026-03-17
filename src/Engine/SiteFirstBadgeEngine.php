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

final class SiteFirstBadgeEngine {

	/** @var array<string, array> Badge definitions seeded on install. */
	private const SITE_FIRST_BADGES = [
		'first_champion' => [
			'name'        => 'First Champion',
			'description' => 'The first member of this community to reach Champion rank.',
			'category'    => 'special',
			'trigger'     => 'level_changed',
		],
		'first_10k_points' => [
			'name'        => 'First to 10,000',
			'description' => 'The first member to earn 10,000 total points in this community.',
			'category'    => 'special',
			'trigger'     => 'points_awarded',
		],
		'first_100_day_streak' => [
			'name'        => 'Century Streak Pioneer',
			'description' => 'The first member to reach a 100-day activity streak.',
			'category'    => 'special',
			'trigger'     => 'streak_milestone',
		],
	];

	public static function init(): void {
		add_action( 'wb_gamification_level_changed',    [ __CLASS__, 'on_level_changed' ],    10, 3 );
		add_action( 'wb_gamification_points_awarded',   [ __CLASS__, 'on_points_awarded' ],   30, 3 );
		add_action( 'wb_gamification_streak_milestone', [ __CLASS__, 'on_streak_milestone' ], 10, 2 );
		add_action( 'plugins_loaded', [ __CLASS__, 'ensure_badges_exist' ], 20 );
	}

	// ── Hooks ────────────────────────────────────────────────────────────────

	public static function on_level_changed( int $user_id, array $old_level, array $new_level ): void {
		if ( 'Champion' === ( $new_level['name'] ?? '' ) ) {
			self::maybe_award( 'first_champion', $user_id );
		}
	}

	public static function on_points_awarded( int $user_id, Event $event, int $points ): void {
		$total = PointsEngine::get_total( $user_id );
		if ( $total >= 10000 ) {
			self::maybe_award( 'first_10k_points', $user_id );
		}
	}

	public static function on_streak_milestone( int $user_id, int $streak_length ): void {
		if ( $streak_length >= 100 ) {
			self::maybe_award( 'first_100_day_streak', $user_id );
		}
	}

	// ── Core logic ───────────────────────────────────────────────────────────

	/**
	 * Award badge only if no one else has earned it yet.
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

	public static function ensure_badges_exist(): void {
		global $wpdb;

		foreach ( self::SITE_FIRST_BADGES as $id => $def ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = %s", $id )
			);

			if ( ! $exists ) {
				$wpdb->insert(
					$wpdb->prefix . 'wb_gam_badge_defs',
					[
						'id'            => $id,
						'name'          => $def['name'],
						'description'   => $def['description'],
						'category'      => $def['category'],
						'is_credential' => 1,
					],
					[ '%s', '%s', '%s', '%s', '%d' ]
				);
			}
		}
	}
}
