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

		$rules = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rule_config
				   FROM {$wpdb->prefix}wb_gam_rules
				  WHERE rule_type = 'points_multiplier'
				    AND ( target_id = %s OR target_id IS NULL OR target_id = '' )
				    AND is_active = 1
				  ORDER BY id ASC",
				$event->action_id
			),
			ARRAY_A
		);

		if ( empty( $rules ) ) {
			return $points;
		}

		foreach ( $rules as $row ) {
			$config = json_decode( $row['rule_config'], true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( ! self::evaluate_condition( (array) ( $config['condition'] ?? [] ), $event ) ) {
				continue;
			}

			$multiplier = (float) ( $config['multiplier'] ?? 1.0 );
			$points     = (int) round( $points * $multiplier );
		}

		return max( 0, $points );
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
				// 'days' array uses PHP gmdate 'w' values: 0 = Sunday, 6 = Saturday.
				$days = (array) ( $condition['days'] ?? [] );
				return in_array( (int) gmdate( 'w' ), $days, true );

			case 'action_id_match':
				return $event->action_id === ( $condition['action_id'] ?? '' );

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
					'wb_gamification_rule_condition',
					false,
					$condition,
					$event
				);
		}
	}
}
