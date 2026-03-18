<?php
/**
 * Unit tests for PointsEngine::get_history().
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\PointsEngine;

class PointsHistoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_history_returns_rows_for_user(): void {
		global $wpdb;

		$wpdb         = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		$fake_rows = array(
			array( 'action_id' => 'wp_create_post', 'points' => '10', 'created_at' => '2026-01-01 10:00:00' ),
			array( 'action_id' => 'bp_update_post',  'points' => '5',  'created_at' => '2026-01-01 09:00:00' ),
		);

		$wpdb->expects( 'prepare' )
			->once()
			->andReturn( 'SELECT...' );

		$wpdb->expects( 'get_results' )
			->once()
			->with( 'SELECT...', \Mockery::any() )
			->andReturn( $fake_rows );

		$rows = PointsEngine::get_history( 42, 20 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'wp_create_post', $rows[0]['action_id'] );
		$this->assertSame( '10', $rows[0]['points'] );
	}

	public function test_get_history_clamps_limit(): void {
		global $wpdb;

		$wpdb         = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		// Limit of 999 should be clamped to 100.
		$wpdb->expects( 'prepare' )
			->once()
			->with( \Mockery::type( 'string' ), 42, 100 )
			->andReturn( 'SELECT...' );

		$wpdb->expects( 'get_results' )
			->once()
			->andReturn( array() );

		$rows = PointsEngine::get_history( 42, 999 );

		$this->assertSame( array(), $rows );
	}

	public function test_get_history_returns_empty_array_on_null(): void {
		global $wpdb;

		$wpdb         = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		$wpdb->expects( 'prepare' )->once()->andReturn( 'SELECT...' );
		$wpdb->expects( 'get_results' )->once()->andReturn( null );

		$rows = PointsEngine::get_history( 1, 20 );

		$this->assertSame( array(), $rows );
	}

	private function mockWpdb(): \Mockery\MockInterface {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		return $wpdb;
	}
}
