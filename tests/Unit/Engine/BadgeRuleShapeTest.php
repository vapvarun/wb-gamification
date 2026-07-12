<?php
/**
 * A badge rule has exactly ONE shape.
 *
 * Today a rule is a single condition:
 *
 *     { "condition_type": "action_count", "action_id": "wp_publish_post", "count": 10 }
 *
 * One condition, forever. An owner cannot express "published 10 posts AND reached Champion" --
 * the thing every competing plugin does, and the thing owners compare on.
 *
 * The new shape is a flat list plus a match mode:
 *
 *     { "match": "all", "conditions": [ { "type": "action_count", ... }, { "type": "level_reached", ... } ] }
 *
 * There is deliberately NO read-time normalizer. The migration rewrites every row once, and after
 * that exactly one shape exists in the database and exactly one reader parses it. A plugin that
 * accepts two shapes forever ends up with two code paths forever, and the second one rots.
 *
 * That makes the transform the load-bearing piece: it runs once, over live rules, and if it is
 * wrong it is wrong permanently. So it is pure, and it is tested before it is written.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeRule;

/**
 * @coversDefaultClass \WBGam\Engine\BadgeRule
 */
class BadgeRuleShapeTest extends TestCase {

	/**
	 * M1 — a legacy single-condition rule becomes a one-condition group.
	 *
	 * Note what MUST survive: every field other than `condition_type` rides along untouched.
	 * Dropping `points` or `count` here would silently change what the badge requires, on a live
	 * site, with nothing to notice it.
	 *
	 * @return void
	 */
	public function test_legacy_rule_becomes_a_one_condition_group(): void {
		$legacy = array(
			'condition_type' => 'action_count',
			'action_id'      => 'wp_publish_post',
			'count'          => 10,
		);

		$this->assertSame(
			array(
				'match'      => 'all',
				'conditions' => array(
					array(
						'type'      => 'action_count',
						'action_id' => 'wp_publish_post',
						'count'     => 10,
					),
				),
			),
			BadgeRule::from_legacy( $legacy ),
			'condition_type becomes type; every other field rides along untouched.'
		);
	}

	/**
	 * The other two shipped types migrate too — point_milestone and admin_awarded.
	 *
	 * @return void
	 */
	public function test_every_shipped_condition_type_migrates(): void {
		$milestone = BadgeRule::from_legacy( array( 'condition_type' => 'point_milestone', 'points' => 100 ) );
		$this->assertSame( 'point_milestone', $milestone['conditions'][0]['type'] );
		$this->assertSame( 100, $milestone['conditions'][0]['points'] );

		$manual = BadgeRule::from_legacy( array( 'condition_type' => 'admin_awarded' ) );
		$this->assertSame( 'admin_awarded', $manual['conditions'][0]['type'] );
		$this->assertSame( 'all', $manual['match'] );
	}

	/**
	 * M2 — IDEMPOTENT. A rule already in the new shape is returned unchanged.
	 *
	 * This is what lets the migration be safe to re-run, and safe to run on a site that was
	 * half-migrated when a request timed out.
	 *
	 * @return void
	 */
	public function test_a_rule_already_in_the_new_shape_is_untouched(): void {
		$already = array(
			'match'      => 'any',
			'conditions' => array(
				array( 'type' => 'level_reached', 'level_id' => 4 ),
				array( 'type' => 'streak_days', 'days' => 7 ),
			),
		);

		$this->assertSame( $already, BadgeRule::from_legacy( $already ), 'Running the migration twice must be a no-op.' );
		$this->assertTrue( BadgeRule::is_group( $already ) );
		$this->assertFalse( BadgeRule::is_group( array( 'condition_type' => 'action_count' ) ) );
	}

	/**
	 * M4 — garbage in does not corrupt the row. A rule we cannot understand is left ALONE, not
	 * rewritten into something plausible-looking.
	 *
	 * Guessing here would be the worst possible behaviour: it would produce a rule that reads as
	 * valid, evaluates to something, and silently awards (or refuses) a badge nobody configured.
	 *
	 * @return void
	 */
	public function test_an_unrecognisable_rule_is_left_alone(): void {
		$this->assertNull( BadgeRule::from_legacy( array() ), 'Empty config: nothing to migrate.' );
		$this->assertNull( BadgeRule::from_legacy( array( 'points' => 100 ) ), 'No condition_type: not a rule we recognise.' );
		$this->assertNull( BadgeRule::from_legacy( array( 'condition_type' => '' ) ), 'Blank condition_type is not a condition.' );
	}

	/**
	 * A group with an empty condition list is INVALID, and must never award.
	 *
	 * An empty ALL is vacuously true in formal logic -- "every condition is satisfied" -- which
	 * would hand the badge to every member on the site the moment someone saved a rule with no
	 * conditions in it. That is the single most dangerous default available here, so it is pinned
	 * with a test rather than left to whoever writes the evaluator.
	 *
	 * @return void
	 */
	public function test_a_group_with_no_conditions_is_invalid(): void {
		$this->assertFalse( BadgeRule::is_valid( array( 'match' => 'all', 'conditions' => array() ) ) );
		$this->assertFalse( BadgeRule::is_valid( array( 'match' => 'all' ) ) );
		$this->assertTrue(
			BadgeRule::is_valid(
				array( 'match' => 'all', 'conditions' => array( array( 'type' => 'point_milestone', 'points' => 10 ) ) )
			)
		);
	}

	/**
	 * `match` only ever means one of two things. Anything else falls back to the safer one.
	 *
	 * "all" is safer than "any": requiring every condition awards fewer badges than requiring one
	 * of them, and under-awarding is recoverable while over-awarding is not (we never revoke).
	 *
	 * @return void
	 */
	public function test_match_mode_defaults_to_all(): void {
		$this->assertSame( 'all', BadgeRule::match_mode( array( 'conditions' => array() ) ) );
		$this->assertSame( 'all', BadgeRule::match_mode( array( 'match' => 'nonsense', 'conditions' => array() ) ) );
		$this->assertSame( 'any', BadgeRule::match_mode( array( 'match' => 'any', 'conditions' => array() ) ) );
		$this->assertSame( 'all', BadgeRule::match_mode( array( 'match' => 'ALL', 'conditions' => array() ) ) );
	}

	/**
	 * The library chip asks the rule ONE question: does this badge award itself?
	 *
	 * It used to answer that question by reading `condition_type` off the raw config -- a SECOND
	 * reader of the rule shape, living in the template. The moment the migration grouped every rule,
	 * that key stopped existing, the reader silently fell through to its `admin_awarded` default, and
	 * the library chipped MANUAL on all 42 badges -- including ones with ten thousand earners.
	 *
	 * That is the exact failure this feature was built to end (seven badges lying MANUAL), inverted
	 * onto the whole library. So the answer lives HERE, with the shape, where there is one reader and
	 * a test -- not in a template that a future migration will forget about again.
	 *
	 * @return void
	 */
	public function test_a_rule_knows_whether_it_awards_itself(): void {
		$this->assertTrue(
			BadgeRule::is_auto_award(
				array( 'match' => 'all', 'conditions' => array( array( 'type' => 'tenure_days', 'days' => 365 ) ) )
			),
			'A badge with a real condition awards itself. The chip must say so.'
		);

		// Manual means one thing and one thing only: nothing the member can do will earn it.
		$this->assertFalse(
			BadgeRule::is_auto_award(
				array( 'match' => 'all', 'conditions' => array( array( 'type' => 'admin_awarded' ) ) )
			)
		);
		$this->assertFalse( BadgeRule::is_auto_award( array( 'match' => 'all', 'conditions' => array() ) ) );
		$this->assertFalse( BadgeRule::is_auto_award( array() ), 'No rule row at all: manual.' );

		// An UNMIGRATED rule reads as manual -- and that is the correct answer, not a shrug.
		//
		// The evaluator reads the same row through the same class and finds no conditions, so it will
		// never award it either. The chip agreeing with the evaluator is the property that matters: a
		// badge the engine cannot award must not be advertised as auto-awarding. The migration is what
		// guarantees no such row survives; this pins what happens if one ever does.
		$this->assertFalse(
			BadgeRule::is_auto_award( array( 'condition_type' => 'action_count', 'action_id' => 'wp_publish_post' ) ),
			'The chip must always say what the evaluator will actually do with this row.'
		);
	}
}
