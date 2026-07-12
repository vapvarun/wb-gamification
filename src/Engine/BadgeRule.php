<?php
/**
 * The shape of a badge rule.
 *
 * A rule used to be a single condition and could never be anything else:
 *
 *     { "condition_type": "action_count", "action_id": "wp_publish_post", "count": 10 }
 *
 * So an owner could say "published 10 posts". They could not say "published 10 posts AND reached
 * Champion" -- which is the thing every competing plugin does, and the first thing an owner
 * compares on. The single condition was a ceiling, not a simplification.
 *
 * The shape now is a flat list plus a match mode:
 *
 *     {
 *       "match": "all",                        // "all" | "any"
 *       "conditions": [
 *         { "type": "action_count",  "action_id": "wp_publish_post", "count": 10 },
 *         { "type": "level_reached", "level_id": 4 }
 *       ]
 *     }
 *
 * Flat, deliberately. No nesting, no boolean tree, no recursive JSON -- because real badges do not
 * need one, and a tree would demand a tree UI that an owner has to learn.
 *
 * ONE SHAPE, ONE READER. There is no read-time normalizer: DbUpgrader rewrites every row once, and
 * after that exactly one shape exists in the database. A plugin that tolerates two shapes forever
 * carries two code paths forever, and the second one silently rots until someone trusts it.
 *
 * This class holds NO WordPress. The migration runs once, over live rules, and if the transform is
 * wrong it is wrong permanently -- so it is pure, and it was tested before it was written.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shape, validation and migration for a badge rule.
 *
 * @package WB_Gamification
 */
final class BadgeRule {

	/**
	 * Match modes. Anything else is coerced to ALL.
	 */
	public const MATCH_ALL = 'all';
	public const MATCH_ANY = 'any';

	/**
	 * Is this config already the new (grouped) shape?
	 *
	 * @param array $config Decoded rule_config.
	 * @return bool
	 */
	public static function is_group( array $config ): bool {
		return isset( $config['conditions'] ) && is_array( $config['conditions'] );
	}

	/**
	 * Migrate a legacy single-condition rule to a one-condition group.
	 *
	 * Returns the config UNCHANGED when it is already a group (so the migration is idempotent and
	 * safe to re-run, including on a site that was half-migrated when a request timed out).
	 *
	 * Returns NULL when the config is not something we recognise. That is deliberate: an
	 * unrecognisable rule is LEFT ALONE, never rewritten into something plausible-looking.
	 * Guessing would be the worst option available -- it would produce a rule that reads as valid,
	 * evaluates to something, and silently awards or refuses a badge nobody configured.
	 *
	 * @param array $config Decoded rule_config.
	 * @return array|null The grouped config, or null if there is nothing safe to do.
	 */
	public static function from_legacy( array $config ): ?array {
		if ( self::is_group( $config ) ) {
			return $config;
		}

		$type = isset( $config['condition_type'] ) ? (string) $config['condition_type'] : '';
		if ( '' === $type ) {
			return null;
		}

		// Every field other than condition_type rides along untouched. Dropping `count` or `points`
		// here would silently change what a badge requires, on a live site, with nothing to notice.
		$condition = $config;
		unset( $condition['condition_type'] );
		$condition = array_merge( array( 'type' => $type ), $condition );

		return array(
			'match'      => self::MATCH_ALL,
			'conditions' => array( $condition ),
		);
	}

	/**
	 * Is this a rule we can actually evaluate?
	 *
	 * An empty condition list is INVALID and must never award.
	 *
	 * In formal logic an empty ALL is vacuously true -- "every condition is satisfied" -- so a
	 * naive evaluator would hand the badge to every member on the site the moment somebody saved a
	 * rule with no conditions in it. That is the most dangerous default available here, which is
	 * why it is pinned in this class rather than left to whoever writes the evaluator next.
	 *
	 * @param array $config Decoded rule_config.
	 * @return bool
	 */
	public static function is_valid( array $config ): bool {
		if ( ! self::is_group( $config ) ) {
			return false;
		}

		foreach ( $config['conditions'] as $condition ) {
			if ( ! is_array( $condition ) || empty( $condition['type'] ) ) {
				return false;
			}
		}

		return array() !== $config['conditions'];
	}

	/**
	 * The match mode, coerced.
	 *
	 * Unknown values fall back to ALL, and ALL is the safe direction: requiring every condition
	 * awards FEWER badges than requiring one of them. Under-awarding is recoverable -- the member
	 * earns it later, or the owner grants it. Over-awarding is not, because we never revoke.
	 *
	 * @param array $config Decoded rule_config.
	 * @return string self::MATCH_ALL | self::MATCH_ANY
	 */
	public static function match_mode( array $config ): string {
		$mode = isset( $config['match'] ) ? (string) $config['match'] : self::MATCH_ALL;

		return self::MATCH_ANY === $mode ? self::MATCH_ANY : self::MATCH_ALL;
	}

	/**
	 * The conditions, as a list.
	 *
	 * @param array $config Decoded rule_config.
	 * @return array<int,array<string,mixed>>
	 */
	public static function conditions( array $config ): array {
		return self::is_group( $config ) ? array_values( $config['conditions'] ) : array();
	}

	/**
	 * Which signals can change this condition's truth?
	 *
	 * THIS IS THE CONTRACT A NEW CONDITION TYPE IMPLEMENTS, and it is the whole reason the award
	 * path stays cheap as the vocabulary grows. A type that declares no signals is never evaluated
	 * on an award -- which is exactly right for tenure (changes on a cron) and for admin_awarded
	 * (never auto-evaluates at all).
	 *
	 * @param array $condition One condition.
	 * @return string[] Signal names. Empty means "no award can ever change this".
	 */
	public static function condition_signals( array $condition ): array {
		$type = isset( $condition['type'] ) ? (string) $condition['type'] : '';

		switch ( $type ) {
			case 'action_count':
				// Only THIS action matters. A publish-10-posts badge does not care that someone
				// reacted to a comment -- and today it pays a COUNT(*) to find that out.
				return array( 'action:' . (string) ( $condition['action_id'] ?? '' ) );

			case 'point_milestone':
			case 'points_in_period':
				// Every award changes the total, so these are relevant to all of them.
				return array( 'points' );

			case 'level_reached':
				return array( 'level' );

			case 'streak_days':
				return array( 'streak' );

			case 'badge_earned':
				return array( 'badge:' . (string) ( $condition['badge_id'] ?? '' ) );

			case 'tenure_days':
				// Tenure changes with the calendar, not with anything a member does. Evaluating a
				// tenure badge on the award path would put it on EVERY award on the site, forever,
				// to answer a question that can only change at midnight.
			case 'admin_awarded':
				return array();

			default:
				/**
				 * Signals for a third-party condition type.
				 *
				 * Return an empty array (the default) and the type is never evaluated on the award
				 * path -- safe, but it will only ever be reached by a cron or a backfill. Declare
				 * your signals to be evaluated when they fire.
				 *
				 * @since 1.6.4
				 *
				 * @param string[] $signals   Signal names.
				 * @param string   $type      Condition type.
				 * @param array    $condition Full condition config.
				 */
				return (array) apply_filters( 'wb_gam_badge_condition_signals', array(), $type, $condition );
		}
	}

	/**
	 * Is this badge worth evaluating for the signals that just fired?
	 *
	 * ANY ONE relevant condition is enough -- for BOTH `all` and `any`, and that is the subtle half.
	 *
	 * Under `all`, a badge requiring "10 posts AND Champion" must still be evaluated when a post is
	 * published: that award may be the one that completes it. Requiring every condition to have
	 * received a signal would mean the badge never awards at all, because no single event ever
	 * touches all of them at once. Getting this backwards produces a badge that is impossible to
	 * earn and no error anywhere.
	 *
	 * @param array    $rule    Grouped rule config.
	 * @param string[] $signals Signals emitted by whatever just happened.
	 * @return bool
	 */
	public static function is_relevant( array $rule, array $signals ): bool {
		foreach ( self::conditions( $rule ) as $condition ) {
			foreach ( self::condition_signals( (array) $condition ) as $needed ) {
				if ( in_array( $needed, $signals, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Cheapest first.
	 *
	 * Conditions that cost ZERO queries are evaluated before any that hit the database, so a badge
	 * whose in-memory condition already fails never runs SQL at all. With `all` short-circuiting on
	 * the first false, ordering is not cosmetic -- it is most of the saving.
	 *
	 * @param array $conditions Conditions.
	 * @return array Same conditions, cheap ones first.
	 */
	public static function by_cost( array $conditions ): array {
		$cost = array(
			// Free: answered from state already primed for this pass.
			'point_milestone'  => 0,
			'level_reached'    => 0,
			'badge_earned'     => 0,
			'tenure_days'      => 0,
			'admin_awarded'    => 0,
			// One indexed lookup.
			'streak_days'      => 1,
			// One indexed COUNT / range scan.
			'action_count'     => 2,
			'points_in_period' => 2,
		);

		usort(
			$conditions,
			static function ( $a, $b ) use ( $cost ) {
				$ca = $cost[ (string) ( $a['type'] ?? '' ) ] ?? 3;
				$cb = $cost[ (string) ( $b['type'] ?? '' ) ] ?? 3;
				return $ca <=> $cb;
			}
		);

		return $conditions;
	}
}
