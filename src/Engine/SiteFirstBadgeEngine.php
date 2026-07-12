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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
			'max_earners' => 1,
		),
		'first_10k_points'     => array(
			'name'        => 'First to 10,000',
			'description' => 'The first member to earn 10,000 total points in this community.',
			'category'    => 'special',
			'trigger'     => 'points_awarded',
			'max_earners' => 1,
		),
		'first_100_day_streak' => array(
			'name'        => 'Century Streak Pioneer',
			'description' => 'The first member to reach a 100-day activity streak.',
			'category'    => 'special',
			'trigger'     => 'streak_milestone',
			'max_earners' => 1,
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
	 * LevelEngine fires the canonical 1.0.0 signature: array level data, not
	 * int IDs. The legacy int variant was removed in 1.0.0 — listeners get
	 * `$new_level['name']` directly so no DB lookup is needed.
	 *
	 * @param int        $user_id   User who levelled up.
	 * @param array|null $new_level New level data (id, name, min_points) or null.
	 * @param array|null $old_level Previous level data or null.
	 */
	public static function on_level_changed( int $user_id, ?array $new_level = null, ?array $old_level = null ): void {
		if ( ! is_array( $new_level ) || empty( $new_level['name'] ) ) {
			return;
		}

		if ( 'Champion' === $new_level['name'] ) {
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
		// Scarcity is not this engine's job, and it never should have been.
		//
		// This method used to enforce "only one member may ever hold this" itself, with three
		// stacked guards, and not one of them was atomic:
		//
		// 1. SELECT COUNT(*) ... then decide          -- two operations
		// 2. get_transient( $lock ) then set_transient -- two operations. Its own comment
		// called this "Race-safe". It was not: both racers see nothing and both set it.
		// 3. a "double-check inside lock" that was another COUNT-then-decide -- two operations
		//
		// Three layers, each of which a second worker walks straight through. Proven on a live
		// site: two concurrent awards, and BOTH members ended up holding "First Champion". The
		// UNIQUE(user_id, badge_id) index does not help -- it stops one member holding a badge
		// twice, and says nothing about two members both being "the first".
		//
		// "Only N members may ever hold this badge" is exactly what max_earners means, and
		// BadgeEngine enforces it under a real database lock. These badges declare max_earners=1
		// (see SITE_FIRST_BADGES), so there is nothing left to do here but ask.
		BadgeEngine::award_badge( $user_id, $badge_id );
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
						'max_earners'   => (int) $def['max_earners'],
					),
					array( '%s', '%s', '%s', '%s', '%d', '%d' )
				);
				continue;
			}

			// Existing installs seeded these badges BEFORE max_earners meant anything here --
			// scarcity was hand-rolled in maybe_award() instead, and it did not work. The rows
			// are already there with max_earners NULL, so seeding alone would leave every
			// upgraded site with an unguarded "first to reach Champion" that two members can
			// still win. Backfill it.
			if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT max_earners FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = %s", $id ) ) ) {
				$wpdb->update(
					$wpdb->prefix . 'wb_gam_badge_defs',
					array( 'max_earners' => (int) $def['max_earners'] ),
					array( 'id' => $id ),
					array( '%d' ),
					array( '%s' )
				);
			}
		}
	}
}
