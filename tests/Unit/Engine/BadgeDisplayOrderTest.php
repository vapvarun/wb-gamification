<?php
/**
 * Regression tests for badge board ordering (1.6.4).
 *
 * Locks the fix for a customer report that badges "display out of order". The order was
 * `category, name` — deterministic, and alphabetical, which is not how anyone reads a ladder:
 * it put "10-Year Member" between "1-Year" and "2-Year", and ordered the points ladder
 * 100, 500, 5000, 10000, 1000. Both were shipped defaults, so every install showed it.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeRule;

/**
 * @coversDefaultClass \WBGam\Engine\BadgeRule
 */
class BadgeDisplayOrderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array $conditions Conditions.
	 * @param string $match Match mode.
	 * @return array Rule config.
	 */
	private function rule( array $conditions, string $match = 'all' ): array {
		return array(
			'match'      => $match,
			'conditions' => $conditions,
		);
	}

	/**
	 * The points ladder — the case the name sort got wrong and a natural-name sort still would,
	 * because these names carry no digits at all.
	 *
	 * @test
	 * @covers ::display_threshold
	 */
	public function points_ladder_orders_by_threshold_not_by_name(): void {
		$ladder = array(
			'Century Club'        => $this->rule( array( array( 'type' => 'point_milestone', 'points' => 100 ) ) ),
			'Five Hundred Strong' => $this->rule( array( array( 'type' => 'point_milestone', 'points' => 500 ) ) ),
			'Thousand Points Club' => $this->rule( array( array( 'type' => 'point_milestone', 'points' => 1000 ) ) ),
			'Five Thousand Strong' => $this->rule( array( array( 'type' => 'point_milestone', 'points' => 5000 ) ) ),
			'Ten Thousand Club'   => $this->rule( array( array( 'type' => 'point_milestone', 'points' => 10000 ) ) ),
		);

		$thresholds = array();
		foreach ( $ladder as $name => $rule ) {
			$thresholds[ $name ] = BadgeRule::display_threshold( $rule );
		}
		asort( $thresholds );

		$this->assertSame(
			array( 'Century Club', 'Five Hundred Strong', 'Thousand Points Club', 'Five Thousand Strong', 'Ten Thousand Club' ),
			array_keys( $thresholds )
		);
	}

	/**
	 * @test
	 * @covers ::display_threshold
	 */
	public function tenure_ladder_orders_one_two_five_ten(): void {
		$ladder = array(
			'10-Year Member' => $this->rule( array( array( 'type' => 'tenure_days', 'days' => 3650 ) ) ),
			'1-Year Member'  => $this->rule( array( array( 'type' => 'tenure_days', 'days' => 365 ) ) ),
			'5-Year Member'  => $this->rule( array( array( 'type' => 'tenure_days', 'days' => 1825 ) ) ),
			'2-Year Member'  => $this->rule( array( array( 'type' => 'tenure_days', 'days' => 730 ) ) ),
		);

		$thresholds = array();
		foreach ( $ladder as $name => $rule ) {
			$thresholds[ $name ] = BadgeRule::display_threshold( $rule );
		}
		asort( $thresholds );

		$this->assertSame(
			array( '1-Year Member', '2-Year Member', '5-Year Member', '10-Year Member' ),
			array_keys( $thresholds )
		);
	}

	/**
	 * Under `all` you need every condition, so the hardest one is what the badge really costs.
	 *
	 * @test
	 * @covers ::display_threshold
	 */
	public function match_all_takes_the_hardest_condition(): void {
		$rule = $this->rule(
			array(
				array( 'type' => 'point_milestone', 'points' => 100 ),
				array( 'type' => 'action_count', 'action_id' => 'post', 'count' => 900 ),
			),
			'all'
		);

		$this->assertSame( 900, BadgeRule::display_threshold( $rule ) );
	}

	/**
	 * Under `any` one condition will do, so the easiest one is what it really costs.
	 *
	 * @test
	 * @covers ::display_threshold
	 */
	public function match_any_takes_the_easiest_condition(): void {
		$rule = $this->rule(
			array(
				array( 'type' => 'point_milestone', 'points' => 100 ),
				array( 'type' => 'action_count', 'action_id' => 'post', 'count' => 900 ),
			),
			'any'
		);

		$this->assertSame( 100, BadgeRule::display_threshold( $rule ) );
	}

	/**
	 * Badges with nothing numeric to compare sort last — there is no ladder for them to be in.
	 *
	 * @test
	 * @covers ::display_threshold
	 */
	public function non_numeric_badges_sort_last(): void {
		$this->assertSame( PHP_INT_MAX, BadgeRule::display_threshold( $this->rule( array( array( 'type' => 'admin_awarded' ) ) ) ) );
		$this->assertSame( PHP_INT_MAX, BadgeRule::display_threshold( $this->rule( array( array( 'type' => 'badge_earned', 'badge_id' => 'x' ) ) ) ) );
		$this->assertSame( PHP_INT_MAX, BadgeRule::display_threshold( array() ) );
	}
}
