<?php
/**
 * Unit tests for RuleEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Event;
use WBGam\Engine\RuleEngine;

/**
 * @coversDefaultClass \WBGam\Engine\RuleEngine
 */
class RuleEngineTest extends TestCase {

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
	 * @test
	 * @covers ::apply_multipliers
	 */
	public function returns_input_when_points_are_zero_or_negative(): void {
		$event = new Event( array( 'action_id' => 'test', 'user_id' => 1 ) );

		$this->assertSame( 0,    RuleEngine::apply_multipliers( 0,    $event ) );
		$this->assertSame( -10,  RuleEngine::apply_multipliers( -10,  $event ) );
	}

	/**
	 * @test
	 * @covers ::apply_multipliers
	 */
	public function returns_input_unchanged_when_no_multiplier_rules_exist(): void {
		$event = new Event( array( 'action_id' => 'test', 'user_id' => 1 ) );

		Functions\when( 'wp_cache_get' )->justReturn( array() );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$this->assertSame( 25, RuleEngine::apply_multipliers( 25, $event ) );
	}
}
