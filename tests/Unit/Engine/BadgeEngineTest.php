<?php
/**
 * Unit tests for BadgeEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeEngine;

/**
 * @coversDefaultClass \WBGam\Engine\BadgeEngine
 */
class BadgeEngineTest extends TestCase {

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
	 * @covers ::has_badge
	 * @covers ::get_user_earned_badge_ids
	 */
	public function reports_badge_held_when_present_in_cached_list(): void {
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key ) => 'wb_gam_earned_badges_42' === $key
				? array( 'first_post', 'champion' )
				: false
		);

		$this->assertTrue( BadgeEngine::has_badge( 42, 'first_post' ) );
		$this->assertTrue( BadgeEngine::has_badge( 42, 'champion' ) );
		$this->assertFalse( BadgeEngine::has_badge( 42, 'never_earned' ) );
	}

	/**
	 * @test
	 * @covers ::has_badge
	 * @covers ::get_user_earned_badge_ids
	 */
	public function user_with_no_badges_returns_empty_set(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			fn( $q, ...$args ) => vsprintf( str_replace( '%d', '%s', $q ), array_map( 'strval', $args ) )
		);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );

		$this->assertFalse( BadgeEngine::has_badge( 99, 'any_badge' ) );
	}

	// ── award_badge() — gating logic ─────────────────────────────────────────
	//
	// award_badge protects the user_badges table behind 4 gates:
	//   1. has_badge → already-held → no-op
	//   2. closes_at cutoff in the past → expired-promo → no-op
	//   3. max_earners cap reached → no-op
	//   4. wb_gamification_should_award_badge filter returns false → no-op
	// All four MUST short-circuit before $wpdb->insert is called.

	private function setup_award_environment( array $cache_overrides = array() ): void {
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key ) => $cache_overrides[ $key ] ?? false
		);
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'current_time' )->alias(
			static fn ( $type ) => 'mysql' === $type ? '2026-05-03 10:00:00' : time()
		);
	}

	/**
	 * @test
	 * @covers ::award_badge
	 */
	public function award_returns_false_when_user_already_holds_badge(): void {
		$this->setup_award_environment(
			array( 'wb_gam_earned_badges_7' => array( 'first_post' ) )
		);

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( BadgeEngine::award_badge( 7, 'first_post' ) );
	}

	/**
	 * @test
	 * @covers ::award_badge
	 */
	public function award_returns_false_after_closes_at_cutoff(): void {
		$this->setup_award_environment();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $q ) => $q );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'        => 'expired_promo',
				'closes_at' => '2020-01-01 00:00:00',
				'is_active' => 1,
			)
		);
		$wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( BadgeEngine::award_badge( 7, 'expired_promo' ) );
	}

	/**
	 * @test
	 * @covers ::award_badge
	 */
	public function award_returns_false_when_max_earners_reached(): void {
		$this->setup_award_environment();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $q ) => $q );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'          => 'limited_pioneer',
				'max_earners' => 10,
				'closes_at'   => null,
				'is_active'   => 1,
			)
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( '10' ); // earner count.
		$wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( BadgeEngine::award_badge( 7, 'limited_pioneer' ) );
	}

	/**
	 * @test
	 * @covers ::award_badge
	 */
	public function should_award_filter_can_block_award(): void {
		$this->setup_award_environment();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $q ) => $q );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 'first_post', 'closes_at' => null, 'max_earners' => null )
		);
		$wpdb->shouldNotReceive( 'insert' );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				return 'wb_gamification_should_award_badge' === $hook ? false : $value;
			}
		);

		$this->assertFalse( BadgeEngine::award_badge( 7, 'first_post' ) );
	}

	/**
	 * @test
	 * @covers ::award_badge
	 */
	public function award_inserts_when_all_gates_pass(): void {
		$this->setup_award_environment();

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $q ) => $q );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'            => 'first_post',
				'closes_at'     => null,
				'max_earners'   => null,
				'validity_days' => 0,
			)
		);
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_wb_gam_user_badges',
				Mockery::on(
					static fn ( $row ) => 7 === $row['user_id'] && 'first_post' === $row['badge_id']
				),
				Mockery::any()
			)
			->andReturn( 1 );
		// Post-award hook fans out to WebhookDispatcher, which looks up
		// subscribers via $wpdb->get_results — return an empty list so
		// the webhook fan-out is a no-op for this assertion.
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$this->assertTrue( BadgeEngine::award_badge( 7, 'first_post' ) );
	}

	/**
	 * @test
	 * @covers ::award_badge
	 */
	public function award_returns_false_and_logs_when_insert_fails(): void {
		$this->setup_award_environment();

		global $wpdb;
		$wpdb             = Mockery::mock();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = 'duplicate key';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $q ) => $q );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 'first_post', 'closes_at' => null, 'max_earners' => null, 'validity_days' => 0 )
		);
		$wpdb->shouldReceive( 'insert' )->andReturn( false );

		$this->assertFalse( BadgeEngine::award_badge( 7, 'first_post' ) );
	}
}
