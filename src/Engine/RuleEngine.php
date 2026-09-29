<?php
/**
 * WB Gamification Rule Engine (v1)
 *
 * Evaluates stored rules from the wb_gam_rules table against incoming events.
 *
 * Phase 0 scope:
 *   - Points multipliers (rule_type = 'points_multiplier')
 *     Supported condition types: day_of_week, action_id_match, metadata_gte
 *
 * Phase 2 scope (not yet built):
 *   - Badge conditions (rule_type = 'badge_condition')
 *
 * Adding a new condition type = add one case to evaluate_condition().
 * Adding a new rule type = add one method here.
 * Neither requires changes to Engine.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Evaluates stored rules from wb_gam_rules against incoming events (e.g. points multipliers).
 *
 * @package WB_Gamification
 */
final class RuleEngine {

	/**
	 * Apply any active multiplier rules to the base points for this event.
	 *
	 * Rules in wb_gam_rules with rule_type = 'points_multiplier' are evaluated
	 * in ID order. Each matching rule multiplies the running points total.
	 *
	 * @param int   $points Base points (before multipliers).
	 * @param Event $event  The event being processed.
	 * @return int          Adjusted points (never negative).
	 */
	public static function apply_multipliers( int $points, Event $event ): int {
		if ( $points <= 0 ) {
			return $points;
		}

		global $wpdb;

		// Object-cached to avoid hitting the DB on every single event.
		// All active multiplier rules are loaded at once and filtered in-memory
		// per action_id, since the full set is typically tiny (~5-20 rows).
		$cache_key = 'wb_gam_multiplier_rules';
		$all_rules = wp_cache_get( $cache_key, 'wb_gamification' );

		if ( false === $all_rules ) {
			$all_rules = $wpdb->get_results(
				"SELECT target_id, rule_config
				   FROM {$wpdb->prefix}wb_gam_rules
				  WHERE rule_type = 'points_multiplier'
				    AND is_active = 1
				  ORDER BY id ASC",
				ARRAY_A
			) ?: array();

			wp_cache_set( $cache_key, $all_rules, 'wb_gamification', 300 ); // 5 min TTL.
		}

		// Filter to rules that apply to this specific action (or globally).
		$rules = array_filter(
			$all_rules,
			static function ( array $row ) use ( $event ): bool {
				return empty( $row['target_id'] ) || $row['target_id'] === $event->action_id;
			}
		);

		if ( empty( $rules ) ) {
			return $points;
		}

		foreach ( $rules as $row ) {
			$config = json_decode( $row['rule_config'], true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( ! self::in_window( $config ) || ! self::evaluate_condition( (array) ( $config['condition'] ?? array() ), $event ) ) {
				continue;
			}

			$multiplier = (float) ( $config['multiplier'] ?? 1.0 );
			$points     = (int) round( $points * $multiplier );
		}

		return max( 0, $points );
	}

	/**
	 * Whether a multiplier's optional campaign window (starts_at / ends_at) is open now.
	 *
	 * The REST API always accepted and stored the window, but nothing read it, so a campaign that
	 * ended kept multiplying forever (card 10344274152). No window = always on.
	 *
	 * @param array $config Decoded rule_config.
	 * @return bool
	 */
	private static function in_window( array $config ): bool {
		$now   = time();
		$start = self::window_timestamp( $config['starts_at'] ?? null, false );
		$end   = self::window_timestamp( $config['ends_at'] ?? null, true );
		return ( null === $start || $now >= $start ) && ( null === $end || $now < $end );
	}

	/**
	 * A window edge as a UTC timestamp, read in the site's time zone (the clock the owner means).
	 *
	 * A bare date ("2026-10-04") on the end edge covers that whole day. Empty or unreadable = null;
	 * RulesController rejects unreadable values before they are stored.
	 *
	 * @param mixed $value  Date or datetime string.
	 * @param bool  $is_end Whether this is the end edge.
	 * @return int|null
	 */
	public static function window_timestamp( $value, bool $is_end ): ?int {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		try {
			$at = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		if ( $is_end && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$at = $at->modify( '+1 day' );
		}
		return $at->getTimestamp();
	}

	/**
	 * Evaluate a single rule condition against an event.
	 *
	 * Returns true if the condition matches (rule should be applied).
	 * An empty condition array matches everything.
	 *
	 * @param array<string, mixed> $condition Decoded condition from rule_config.
	 * @param Event                $event     The event being evaluated.
	 * @return bool
	 */
	private static function evaluate_condition( array $condition, Event $event ): bool {
		if ( empty( $condition ) ) {
			return true;
		}

		$type = $condition['type'] ?? '';

		switch ( $type ) {

			case 'day_of_week':
				// The SITE's day of the week, not UTC's.
				//
				// An owner who configures a rule for "Saturdays" means their community's Saturday. This
				// evaluated UTC's: on a UTC-7 site the rule switched on at 17:00 Friday local and off at
				// 17:00 Saturday local. The owner's schedule, running on somebody else's clock.
				//
				// No column is compared here, so no gate can catch this one -- it is a pure PHP
				// evaluation. That is exactly why it survived a sweep that looked for SQL bounds.
				//
				// 'days' array uses PHP 'w' values: 0 = Sunday, 6 = Saturday.
				$days = (array) ( $condition['days'] ?? array() );
				return in_array( (int) wp_date( 'w' ), $days, true ); // The site's weekday.

			case 'action_id_match':
				return ( $condition['action_id'] ?? '' ) === $event->action_id;

			case 'metadata_gte':
				$field = (string) ( $condition['field'] ?? '' );
				$value = $condition['value'] ?? 0;
				return isset( $event->metadata[ $field ] )
					&& (float) $event->metadata[ $field ] >= (float) $value;

			default:
				/**
				 * Allow plugins to handle custom condition types.
				 *
				 * @param bool                 $matches   Default false — unknown type = no match.
				 * @param array<string, mixed> $condition The condition config.
				 * @param Event                $event     The event being evaluated.
				 */
				return (bool) apply_filters(
					'wb_gam_rule_condition',
					false,
					$condition,
					$event
				);
		}
	}
}
