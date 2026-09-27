<?php
/**
 * Kudos limits withhold POINTS; they never refuse the kudos (1.6.5).
 *
 * Members were told "you reached the limit" after writing a kudos to a sixth person. The daily
 * limit and the per-receiver window exist to stop point farming, so they now decide only whether
 * a kudos earns points. The one refusal left is a spam ceiling the forms hide themselves before.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\KudosEngine;

/**
 * @coversDefaultClass \WBGam\Engine\KudosEngine
 */
class KudosPointsLimitTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		Functions\when( 'current_time' )->alias( static fn( $type, $gmt = 0 ) => 'timestamp' === $type ? strtotime( '2026-09-26 12:00:00 UTC' ) : '2026-09-26 12:00:00' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_option' )->returnArg( 2 ); // Defaults: 5 points-earning kudos a day.
		Functions\when( 'apply_filters' )->returnArg( 2 ); // Defaults: 1h window, 50/day ceiling.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fake the two counts the engine reads: kudos sent today, and kudos to this receiver in the window.
	 *
	 * @param int $sent_today  Kudos the giver sent today.
	 * @param int $to_receiver Kudos to this receiver inside the cooldown window.
	 */
	private function counts( int $sent_today, int $to_receiver = 0 ): void {
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			fn( $q ) => str_contains( $q, 'receiver_id' ) ? $to_receiver : $sent_today
		);
	}

	/**
	 * @test
	 * @covers ::earns_points
	 */
	public function first_five_kudos_a_day_earn_points(): void {
		$this->counts( 4 );
		$this->assertTrue( KudosEngine::earns_points( 1, 2 ) );
	}

	/**
	 * @test
	 * @covers ::earns_points
	 * @covers ::can_send
	 */
	public function sixth_kudos_still_sends_but_earns_nothing(): void {
		$this->counts( 5 );
		$this->assertFalse( KudosEngine::earns_points( 1, 2 ) );
		$this->assertTrue( KudosEngine::can_send( 1 ), 'Past the points limit the member must still be able to send.' );
	}

	/**
	 * @test
	 * @covers ::earns_points
	 */
	public function repeat_to_same_member_within_the_hour_earns_nothing(): void {
		$this->counts( 1, 1 );
		$this->assertFalse( KudosEngine::earns_points( 1, 2 ) );
		$this->assertTrue( KudosEngine::can_send( 1 ) );
	}

	/**
	 * @test
	 * @covers ::can_send
	 */
	public function only_the_spam_ceiling_stops_sending(): void {
		$this->counts( 49 );
		$this->assertTrue( KudosEngine::can_send( 1 ) );

		$this->counts( 50 );
		$this->assertFalse( KudosEngine::can_send( 1 ) );
	}

	/**
	 * @test
	 * @covers ::points_kudos_remaining
	 */
	public function remaining_counts_points_earning_kudos_and_never_goes_negative(): void {
		$this->counts( 3 );
		$this->assertSame( 2, KudosEngine::points_kudos_remaining( 1 ) );

		$this->counts( 12 );
		$this->assertSame( 0, KudosEngine::points_kudos_remaining( 1 ) );
	}

	/**
	 * Off means off for every caller: BuddyNext calls the engine directly, not the REST routes
	 * the Modules switch guards (card 10343975302).
	 *
	 * @test
	 * @covers ::can_send
	 */
	public function kudos_module_off_refuses_every_caller(): void {
		$this->counts( 0 );
		Functions\when( 'get_option' )->alias(
			fn( $name, $fallback = false ) => 'wb_gam_modules' === $name ? array( 'kudos' => '0' ) : $fallback
		);

		$this->assertFalse( KudosEngine::can_send( 1 ), 'No form may be drawn while kudos is off.' );
	}
}
