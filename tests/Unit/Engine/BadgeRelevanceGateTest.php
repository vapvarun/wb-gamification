<?php
/**
 * A badge is only evaluated when something that could change its answer actually happened.
 *
 * THE PROBLEM THIS SOLVES IS ALREADY IN PRODUCTION.
 *
 * `BadgeEngine::evaluate_condition()` short-circuits an `action_count` condition only when the
 * required count is exactly 1:
 *
 *     if ( 1 === $required && $event->action_id !== $action_id ) {
 *         return false;                                  // <- only THIS case escapes
 *     }
 *     return PointsEngine::get_action_count( $user_id, $action_id ) >= $required;
 *
 * So a "publish 10 posts" badge issues a COUNT(*) every time a member reacts to a comment. On the
 * live site there are 35 active rules, and 12 of them are action_count with count > 1 -- twelve
 * COUNT queries, on every award, for badges the event could not possibly have advanced. One award
 * costs 30 queries today.
 *
 * Multi-condition badges would multiply that: 35 badges x N conditions, several query-backed. It
 * does not survive 100k members. So the gate is not an optimisation bolted on afterwards -- it is
 * the thing that makes the feature affordable, and it makes the award path FASTER THAN IT IS NOW.
 *
 * THE CONTRACT: every condition type declares which SIGNALS can change its truth. An award emits a
 * signal set. A badge is evaluated only if at least one of its conditions cares about a signal that
 * actually fired.
 *
 * These tests are the performance contract. G1 is mutation-tested: remove the gate and it must go
 * red, or the gate is decorative.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeRule;

/**
 * @coversDefaultClass \WBGam\Engine\BadgeRule
 */
class BadgeRelevanceGateTest extends TestCase {

	/**
	 * Each condition type declares its signal. This is the contract a new type implements, and it
	 * is what keeps the award path cheap as the vocabulary grows.
	 *
	 * @return void
	 */
	public function test_every_condition_type_declares_its_signals(): void {
		$this->assertSame(
			array( 'action:wp_publish_post' ),
			BadgeRule::condition_signals( array( 'type' => 'action_count', 'action_id' => 'wp_publish_post' ) )
		);
		$this->assertSame( array( 'points' ), BadgeRule::condition_signals( array( 'type' => 'point_milestone' ) ) );
		$this->assertSame( array( 'points' ), BadgeRule::condition_signals( array( 'type' => 'points_in_period' ) ) );
		$this->assertSame( array( 'level' ), BadgeRule::condition_signals( array( 'type' => 'level_reached' ) ) );
		$this->assertSame( array( 'streak' ), BadgeRule::condition_signals( array( 'type' => 'streak_days' ) ) );
		$this->assertSame(
			array( 'badge:early_bird' ),
			BadgeRule::condition_signals( array( 'type' => 'badge_earned', 'badge_id' => 'early_bird' ) )
		);
	}

	/**
	 * Tenure changes on a CRON, never on an award. A tenure badge must never be evaluated on the
	 * award path -- if it were, every award on the site would carry it for nothing.
	 *
	 * @return void
	 */
	public function test_tenure_and_manual_conditions_have_no_award_signal(): void {
		$this->assertSame( array(), BadgeRule::condition_signals( array( 'type' => 'tenure_days' ) ) );
		$this->assertSame( array(), BadgeRule::condition_signals( array( 'type' => 'admin_awarded' ) ) );
	}

	/**
	 * G1 — THE BUG THIS FEATURE FIXES.
	 *
	 * A "publish 10 posts" badge is NOT relevant to a member reacting to a comment. Today it is
	 * evaluated anyway, and runs a COUNT(*). Remove the gate and this test must fail.
	 *
	 * @return void
	 */
	public function test_a_publish_badge_is_not_evaluated_when_a_reaction_fires(): void {
		$rule = array(
			'match'      => 'all',
			'conditions' => array( array( 'type' => 'action_count', 'action_id' => 'wp_publish_post', 'count' => 10 ) ),
		);

		$this->assertFalse(
			BadgeRule::is_relevant( $rule, array( 'points', 'action:wp_add_reaction' ) ),
			'A publish-10-posts badge must NOT be evaluated because someone reacted to a comment. '
			. 'Today it is, and it pays a COUNT(*) for the privilege.'
		);
	}

	/**
	 * G2 — and it IS evaluated when the action it cares about fires.
	 *
	 * @return void
	 */
	public function test_the_same_badge_is_evaluated_when_a_post_is_published(): void {
		$rule = array(
			'match'      => 'all',
			'conditions' => array( array( 'type' => 'action_count', 'action_id' => 'wp_publish_post', 'count' => 10 ) ),
		);

		$this->assertTrue( BadgeRule::is_relevant( $rule, array( 'points', 'action:wp_publish_post' ) ) );
	}

	/**
	 * G3 — a tenure badge is never relevant to an award, whatever the award was.
	 *
	 * @return void
	 */
	public function test_a_tenure_badge_is_never_relevant_to_an_award(): void {
		$rule = array(
			'match'      => 'all',
			'conditions' => array( array( 'type' => 'tenure_days', 'days' => 365 ) ),
		);

		$this->assertFalse( BadgeRule::is_relevant( $rule, array( 'points', 'action:wp_publish_post', 'level', 'streak' ) ) );
	}

	/**
	 * A points badge is relevant to EVERY award, because every award changes the total.
	 *
	 * @return void
	 */
	public function test_a_points_badge_is_relevant_to_any_award(): void {
		$rule = array(
			'match'      => 'all',
			'conditions' => array( array( 'type' => 'point_milestone', 'points' => 100 ) ),
		);

		$this->assertTrue( BadgeRule::is_relevant( $rule, array( 'points' ) ) );
	}

	/**
	 * ANY ONE relevant condition makes the whole badge worth evaluating -- for both ALL and ANY.
	 *
	 * This is the subtle half of the gate and the easy place to get it wrong. Under `all`, a badge
	 * requiring "10 posts AND Champion level" must still be evaluated when a post is published:
	 * that award may be the one that completes it. Skipping the badge because its OTHER condition
	 * did not receive a signal would mean it never awards at all.
	 *
	 * @return void
	 */
	public function test_one_relevant_condition_is_enough_to_evaluate_the_badge(): void {
		$rule = array(
			'match'      => 'all',
			'conditions' => array(
				array( 'type' => 'action_count', 'action_id' => 'wp_publish_post', 'count' => 10 ),
				array( 'type' => 'level_reached', 'level_id' => 4 ),
			),
		);

		// The post-published signal touches the first condition only -- and that is enough.
		$this->assertTrue( BadgeRule::is_relevant( $rule, array( 'points', 'action:wp_publish_post' ) ) );

		// A level change touches the second -- also enough.
		$this->assertTrue( BadgeRule::is_relevant( $rule, array( 'points', 'level' ) ) );

		// A comment touches NEITHER. Skip it.
		$this->assertFalse( BadgeRule::is_relevant( $rule, array( 'action:wp_leave_comment' ) ) );
	}

	/**
	 * A badge with no evaluable conditions is never relevant -- and an empty rule never awards.
	 *
	 * @return void
	 */
	public function test_an_empty_or_manual_rule_is_never_relevant(): void {
		$this->assertFalse( BadgeRule::is_relevant( array( 'match' => 'all', 'conditions' => array() ), array( 'points' ) ) );
		$this->assertFalse(
			BadgeRule::is_relevant(
				array( 'match' => 'all', 'conditions' => array( array( 'type' => 'admin_awarded' ) ) ),
				array( 'points', 'level', 'streak' )
			),
			'A manual badge must never be auto-evaluated, whatever happens.'
		);
	}
}
