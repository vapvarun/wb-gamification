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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Event;
use WBGam\Engine\RuleEngine;

#[CoversClass( \WBGam\Engine\RuleEngine::class )]
#[CoversMethod( \WBGam\Engine\RuleEngine::class, 'apply_multipliers' )]
#[CoversMethod( \WBGam\Engine\RuleEngine::class, 'window_timestamp' )]
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

	#[Test]
	public function returns_input_when_points_are_zero_or_negative(): void {
		$event = new Event( array( 'action_id' => 'test', 'user_id' => 1 ) );

		$this->assertSame( 0,    RuleEngine::apply_multipliers( 0,    $event ) );
		$this->assertSame( -10,  RuleEngine::apply_multipliers( -10,  $event ) );
	}

	#[Test]
	public function returns_input_unchanged_when_no_multiplier_rules_exist(): void {
		$event = new Event( array( 'action_id' => 'test', 'user_id' => 1 ) );

		Functions\when( 'wp_cache_get' )->justReturn( array() );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$this->assertSame( 25, RuleEngine::apply_multipliers( 25, $event ) );
	}

	/**
	 * A multiplier's campaign window is honoured: before, inside and after (card 10344274152).
	 * Before 1.6.5 the window was stored but never read, so an ended campaign kept doubling.
	 */
	#[Test]
	public function multiplier_applies_only_inside_its_campaign_window(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		$event = new Event( array( 'action_id' => 'wp_publish_post', 'user_id' => 1 ) );
		$rule  = static fn( array $window ): array => array(
			array(
				'target_id'   => '',
				'rule_config' => json_encode( array( 'multiplier' => 2 ) + $window ),
			),
		);
		$day   = static fn( int $offset ): string => gmdate( 'Y-m-d', time() + $offset * 86400 );

		Functions\when( 'wp_cache_get' )->justReturn( $rule( array( 'starts_at' => '2024-01-01', 'ends_at' => '2024-01-07' ) ) );
		$this->assertSame( 10, RuleEngine::apply_multipliers( 10, $event ), 'An ended campaign must not multiply.' );

		Functions\when( 'wp_cache_get' )->justReturn( $rule( array( 'starts_at' => $day( 1 ) ) ) );
		$this->assertSame( 10, RuleEngine::apply_multipliers( 10, $event ), 'A campaign that has not started must not multiply.' );

		Functions\when( 'wp_cache_get' )->justReturn( $rule( array( 'starts_at' => $day( -1 ), 'ends_at' => $day( 0 ) ) ) );
		$this->assertSame( 20, RuleEngine::apply_multipliers( 10, $event ), 'A bare end date covers the whole of that day.' );

		Functions\when( 'wp_cache_get' )->justReturn( $rule( array() ) );
		$this->assertSame( 20, RuleEngine::apply_multipliers( 10, $event ), 'No window means always on.' );

		$this->assertNull( RuleEngine::window_timestamp( 'next tuesday-ish', true ), 'Unreadable dates are rejected, not guessed.' );
	}
}
