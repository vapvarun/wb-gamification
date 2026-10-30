<?php
/**
 * Regression tests for personal records (1.6.8, Basecamp 10435809454).
 *
 * Until 1.6.8 the stored best was the running total of the period in progress,
 * so every award "beat" the subtotal before it and members got a "New personal
 * record!" notice on almost every award.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\PersonalRecordEngine;

#[CoversClass( PersonalRecordEngine::class )]
class PersonalRecordTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory user meta for user 7.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * Periods `wb_gam_personal_record` fired for.
	 *
	 * @var string[]
	 */
	private array $fired = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta  = array();
		$this->fired = array();

		Functions\when( 'current_time' )->alias( static fn( $type ) => 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $uid, $key, $value ) {
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->returnArg( 2 );
		// No point types configured: the message falls back to "Points".
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
			public function get_row( ...$args ) {
				return null;
			}
			public function get_var( ...$args ) {
				return null;
			}
			public function get_results( ...$args ) {
				return array();
			}
		};
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) {
				if ( 'wb_gam_personal_record' === $hook ) {
					$this->fired[] = $args[1];
				}
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function award( int $period_total ): void {
		$m = new \ReflectionMethod( PersonalRecordEngine::class, 'maybe_record' );
		$m->invoke( null, 7, 'week', $period_total );
	}

	#[Test]
	public function a_new_member_gets_no_record_in_their_first_period(): void {
		foreach ( array( 10, 25, 70, 75 ) as $total ) {
			$this->award( $total );
		}
		$this->assertSame( array(), $this->fired );
	}

	#[Test]
	public function passing_last_periods_best_fires_once_per_period(): void {
		// Last week finished at 70.
		$this->meta['wb_gam_pr_run_week'] = array(
			'key'      => '2000-01',
			'total'    => 70,
			'notified' => false,
		);

		$this->award( 50 );
		$this->assertSame( array(), $this->fired, 'Below last week: no record.' );
		$this->assertSame( 70, $this->meta['wb_gam_pr_best_week'], 'The finished week became the best.' );

		$this->award( 71 );
		$this->award( 90 );
		$this->award( 120 );
		$this->assertSame( array( 'week' ), $this->fired, 'One notice for the week, however many awards follow.' );
	}

	#[Test]
	public function the_first_period_after_updating_from_1_6_7_stays_silent(): void {
		// 1.6.7 left the running total of this week as the "best" and no run meta.
		$this->meta['wb_gam_pr_best_week'] = 70;

		$this->award( 75 );
		$this->award( 80 );
		$this->assertSame( array(), $this->fired );
	}
}
