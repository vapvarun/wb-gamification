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
 * The migration and the shape logic are PURE -- no database, no globals, no state. They run once,
 * over live rules, and if the transform is wrong it is wrong permanently, so they were tested before
 * they were written. (The sanitizer reaches for `sanitize_key`, and the signal map for
 * `apply_filters`; both are stateless, and neither is on the migration path.)
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
	 * Build a rule from whatever a caller posted.
	 *
	 * This is the ONE door into the database. Three vocabularies arrive here, and all three leave as
	 * the same grouped shape:
	 *
	 *   - `{ match, conditions: [...] }`  -- the admin repeater.
	 *   - `{ type, ... }`                 -- one condition, the badges REST contract.
	 *   - `{ condition_type, ... }`       -- one condition, the RULES REST contract (documented, and
	 *                                        the shape every rule written before 1.6.4 used).
	 *
	 * Tolerating three input shapes at the boundary is not the same as reading three shapes: they are
	 * normalised HERE, once, and only the grouped shape is ever written or read back. That is the
	 * difference between a compatible API and two code paths that rot.
	 *
	 * @param array|null $payload Raw condition payload from a request.
	 * @return array|null The grouped rule, or null when nothing valid was posted (a manual badge).
	 */
	public static function from_request( ?array $payload ): ?array {
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( isset( $payload['conditions'] ) && is_array( $payload['conditions'] ) ) {
			$conditions = array();

			foreach ( $payload['conditions'] as $raw ) {
				$clean = self::sanitize_condition( is_array( $raw ) ? $raw : array() );
				if ( null !== $clean ) {
					$conditions[] = $clean;
				}
			}

			if ( ! $conditions ) {
				return null; // Every row was manual or junk: the badge is manual.
			}

			return array(
				'match'      => self::MATCH_ANY === ( $payload['match'] ?? self::MATCH_ALL ) ? self::MATCH_ANY : self::MATCH_ALL,
				'conditions' => $conditions,
			);
		}

		// A single condition, in either vocabulary.
		if ( isset( $payload['condition_type'] ) && ! isset( $payload['type'] ) ) {
			$payload['type'] = $payload['condition_type'];
		}

		$single = self::sanitize_condition( $payload );

		return null === $single
			? null
			: array(
				'match'      => self::MATCH_ALL,
				'conditions' => array( $single ),
			);
	}

	/**
	 * Sanitize ONE condition, and drop anything we do not recognise.
	 *
	 * Returns null for `admin_awarded` and for unknown types: a manual badge is a badge with no rule,
	 * and an unknown type must never be written -- it would sit in the database evaluating to
	 * whatever a filter happened to say, on a configuration nobody chose.
	 *
	 * @param array $raw Raw condition from a request.
	 * @return array|null
	 */
	public static function sanitize_condition( array $raw ): ?array {
		$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );

		switch ( $type ) {
			case 'point_milestone':
				return array(
					'type'   => $type,
					'points' => max( 1, (int) ( $raw['points'] ?? 100 ) ),
				);

			case 'action_count':
				return array(
					'type'      => $type,
					'action_id' => sanitize_key( (string) ( $raw['action_id'] ?? '' ) ),
					'count'     => max( 1, (int) ( $raw['count'] ?? 1 ) ),
				);

			case 'level_reached':
				return array(
					'type'     => $type,
					'level_id' => max( 1, (int) ( $raw['level_id'] ?? 1 ) ),
				);

			case 'badge_earned':
				return array(
					'type'     => $type,
					'badge_id' => sanitize_key( (string) ( $raw['badge_id'] ?? '' ) ),
				);

			case 'streak_days':
			case 'tenure_days':
				return array(
					'type' => $type,
					'days' => max( 1, (int) ( $raw['days'] ?? 1 ) ),
				);

			case 'points_in_period':
				$period = (string) ( $raw['period'] ?? 'week' );
				return array(
					'type'   => $type,
					'points' => max( 1, (int) ( $raw['points'] ?? 50 ) ),
					'period' => in_array( $period, array( 'day', 'week', 'month' ), true ) ? $period : 'week',
				);

			case 'admin_awarded':
			default:
				return null;
		}
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
	 * Does this badge award itself?
	 *
	 * The Badge Library prints one of two chips on every card -- AUTO-AWARD or MANUAL -- and that
	 * chip is the owner's only at-a-glance answer to "do I have to hand this out myself?"
	 *
	 * The question is about the RULE, so it is answered here, once. The library used to answer it
	 * inside the template by reading `condition_type` off the raw config, which made the template a
	 * second reader of the shape. The migration grouped every rule, that key stopped existing, the
	 * template fell through to its `admin_awarded` default, and the library chipped MANUAL on all 42
	 * badges -- including ones with ten thousand earners. It was the precise lie this feature exists
	 * to end, told about every badge instead of seven.
	 *
	 * A badge is MANUAL when nothing a member does can earn it: no conditions, or the single
	 * `admin_awarded` condition. Everything else awards itself.
	 *
	 * @param array $config Decoded rule_config. An empty array (no rule row at all) is manual.
	 * @return bool
	 */
	public static function is_auto_award( array $config ): bool {
		foreach ( self::conditions( $config ) as $condition ) {
			if ( 'admin_awarded' !== (string) ( $condition['type'] ?? '' ) ) {
				return true;
			}
		}

		return false;
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
				// Tenure changes with the CALENDAR, not with anything a member does. It is never
				// relevant to an award -- evaluating it there would put it on every award on the
				// site, forever, to answer a question that can only change at midnight.
				//
				// But it still has to be evaluated by SOMETHING, or the badge never awards at all.
				// That was TenureBadgeEngine's whole job, and deleting that engine without giving
				// tenure a signal would have silently killed four badges. The daily badge pass
				// emits `cron`, and this is the only condition type that answers to it.
				return array( 'cron' );

			case 'admin_awarded':
				// Never auto-evaluates. Not on an award, not on a cron, not ever.
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
