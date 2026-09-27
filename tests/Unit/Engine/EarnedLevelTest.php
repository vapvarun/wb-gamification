<?php
/**
 * Levels follow points EARNED, and only a climb is announced (owner decision 2026-09-27).
 *
 * Spending on a reward moves points from the balance to `spent`, so earned (balance + spent) and the
 * level hold. A removal lowers both, silently.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\PointsEngine;
use WBGam\Tests\Unit\Support\ResetsPointTypeCache;

/**
 * @coversDefaultClass \WBGam\Engine\LevelEngine
 */
class EarnedLevelTest extends TestCase {

	use ResetsPointTypeCache;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->resetPointTypeCache();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::is_climb
	 */
	public function only_a_climb_is_announced(): void {
		$member      = array( 'min_points' => 100 );
		$contributor = array( 'min_points' => 500 );

		$this->assertTrue( LevelEngine::is_climb( $contributor, $member ) );
		$this->assertTrue( LevelEngine::is_climb( $member, null ), 'A first level is a climb.' );
		$this->assertFalse( LevelEngine::is_climb( $member, $contributor ), 'A drop is applied silently.' );
		$this->assertFalse( LevelEngine::is_climb( null, $member ) );
	}

	/**
	 * @test
	 * @covers \WBGam\Engine\PointsEngine::get_earned
	 */
	public function earned_is_balance_plus_spent(): void {
		Functions\when( 'wp_cache_get' )->alias(
			static function ( $key ) {
				return array(
					'point_types_default'   => 'points',
					'point_types_all'       => array( array( 'slug' => 'points', 'label' => 'Points', 'is_default' => 1 ) ),
					'wb_gam_total_7_points' => 400,
					'wb_gam_spent_7_points' => 150,
				)[ $key ] ?? false;
			}
		);

		$this->assertSame( 550, PointsEngine::get_earned( 7, 'points' ), 'A member who spent 150 of 550 earned keeps a 550 level.' );
	}
}
