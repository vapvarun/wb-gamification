<?php
/**
 * Mission-Aligned Mode
 *
 * Replaces gamification terminology with community-type appropriate language.
 * Configured via `wb_gam_mission_mode` option (set in Settings page).
 *
 * Built-in modes:
 *   default     — Points, Badges, Level, Streak (generic)
 *   nonprofit   — Contributions, Recognitions, Impact Stage, Consistency
 *   professional — Credits, Credentials, Rank, Commitment
 *   fitness     — Points, Achievements, Fitness Level, Streak
 *   education   — XP, Achievements, Grade, Study Streak
 *   coaching    — Points, Milestones, Coaching Level, Commitment Streak
 *
 * Usage in templates/blocks:
 *   echo WBGam\Engine\MissionMode::term( 'points' );  // → "Contributions" in nonprofit mode
 *
 * Third parties can add modes via the `wb_gamification_mission_modes` filter.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class MissionMode {

	private const BUILT_IN_MODES = array(
		'default'      => array(
			'points'      => 'Points',
			'badges'      => 'Badges',
			'level'       => 'Level',
			'streak'      => 'Streak',
			'kudos'       => 'Kudos',
			'rank'        => 'Rank',
			'leaderboard' => 'Leaderboard',
			'challenge'   => 'Challenge',
		),
		'nonprofit'    => array(
			'points'      => 'Contributions',
			'badges'      => 'Recognitions',
			'level'       => 'Impact Stage',
			'streak'      => 'Consistency',
			'kudos'       => 'Appreciations',
			'rank'        => 'Impact Rank',
			'leaderboard' => 'Impact Board',
			'challenge'   => 'Mission',
		),
		'professional' => array(
			'points'      => 'Credits',
			'badges'      => 'Credentials',
			'level'       => 'Rank',
			'streak'      => 'Commitment',
			'kudos'       => 'Endorsements',
			'rank'        => 'Professional Rank',
			'leaderboard' => 'Rankings',
			'challenge'   => 'Objective',
		),
		'fitness'      => array(
			'points'      => 'Points',
			'badges'      => 'Achievements',
			'level'       => 'Fitness Level',
			'streak'      => 'Streak',
			'kudos'       => 'Shoutouts',
			'rank'        => 'Fitness Rank',
			'leaderboard' => 'Leaderboard',
			'challenge'   => 'Fitness Challenge',
		),
		'education'    => array(
			'points'      => 'XP',
			'badges'      => 'Achievements',
			'level'       => 'Grade',
			'streak'      => 'Study Streak',
			'kudos'       => 'High Fives',
			'rank'        => 'Class Rank',
			'leaderboard' => 'Honour Roll',
			'challenge'   => 'Assignment',
		),
		'coaching'     => array(
			'points'      => 'Points',
			'badges'      => 'Milestones',
			'level'       => 'Coaching Level',
			'streak'      => 'Commitment Streak',
			'kudos'       => 'Kudos',
			'rank'        => 'Coaching Rank',
			'leaderboard' => 'Progress Board',
			'challenge'   => 'Goal',
		),
	);

	/** @var array|null Cached resolved dictionary for current request. */
	private static ?array $resolved = null;

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Get the localised term for a gamification concept.
	 *
	 * @param string $key   One of: points, badges, level, streak, kudos, rank, leaderboard, challenge.
	 * @param bool   $lower Return in lower-case (for use mid-sentence).
	 * @return string
	 */
	public static function term( string $key, bool $lower = false ): string {
		$dict = self::get_dictionary();
		$term = $dict[ $key ] ?? $key;
		return $lower ? mb_strtolower( $term ) : $term;
	}

	/**
	 * Get the current mode slug.
	 */
	public static function current_mode(): string {
		return sanitize_key( get_option( 'wb_gam_mission_mode', 'default' ) );
	}

	/**
	 * Get all available modes (for settings dropdown).
	 *
	 * @return array<string, string> slug → human label
	 */
	public static function get_available_modes(): array {
		$modes = array_keys( self::BUILT_IN_MODES );

		/**
		 * Add or remove mission modes.
		 *
		 * @param string[] $modes Mode slugs.
		 */
		$modes = (array) apply_filters( 'wb_gamification_mission_modes', $modes );

		return array_combine(
			$modes,
			array_map( fn( $m ) => ucfirst( str_replace( '_', ' ', $m ) ), $modes )
		);
	}

	/**
	 * Export the full dictionary for the current mode — used to seed
	 * JS-side localisation (`wp_add_inline_script`).
	 *
	 * @return array<string, string>
	 */
	public static function get_dictionary(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$mode     = self::current_mode();
		$built_in = self::BUILT_IN_MODES[ $mode ] ?? self::BUILT_IN_MODES['default'];

		/**
		 * Override individual terms for the active mode.
		 *
		 * @param array  $terms  Term key → label map.
		 * @param string $mode   Active mode slug.
		 */
		$terms = (array) apply_filters( 'wb_gamification_mission_terms', $built_in, $mode );

		self::$resolved = $terms;
		return $terms;
	}

	/**
	 * Flush the in-memory cache (call after saving settings).
	 */
	public static function flush(): void {
		self::$resolved = null;
	}
}
