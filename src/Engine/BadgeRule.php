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
}
