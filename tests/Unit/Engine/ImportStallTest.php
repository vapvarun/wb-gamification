<?php
/**
 * A page queued as an async job in a dead queue must count as stalled.
 *
 * as_next_scheduled_action() returns true for a pending async job as well as a running one, and
 * every page of an import is an async job, so "true" alone can never mean healthy.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ImportRunner;

/**
 * Drives the private has_live_job() with each answer Action Scheduler can give.
 */
#[CoversClass( ImportRunner::class )]
class ImportStallTest extends TestCase {

	/**
	 * Set up Brain Monkey and the Action Scheduler status constant.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! class_exists( 'ActionScheduler_Store', false ) && ! class_exists( 'ActionScheduler_Store' ) ) {
			eval( 'class ActionScheduler_Store { const STATUS_RUNNING = "in-progress"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Each answer from Action Scheduler and whether the job is live.
	 *
	 * @return array<string, array{0: int|bool, 1: int[], 2: bool}>
	 */
	public static function answers(): array {
		return array(
			'pending async job, nothing running' => array( true, array(), false ),
			'job actually running'               => array( true, array( 12 ), true ),
			'scheduled job long overdue'         => array( time() - 600, array(), false ),
			'scheduled job due soon'             => array( time() + 30, array(), true ),
			'no job at all'                      => array( false, array(), false ),
		);
	}

	/**
	 * The job is live only when it is running or not yet overdue.
	 *
	 * @param int|bool $next     as_next_scheduled_action() answer.
	 * @param int[]    $running  as_get_scheduled_actions() answer.
	 * @param bool     $expected Whether the job counts as live.
	 */
	#[DataProvider( 'answers' )]
	public function test_a_job_is_live_only_when_running_or_not_yet_overdue( $next, array $running, bool $expected ): void {
		Functions\when( 'as_next_scheduled_action' )->justReturn( $next );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( $running );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$method = new \ReflectionMethod( ImportRunner::class, 'has_live_job' );
		$this->assertSame( $expected, $method->invoke( null, 'mycred', 'run', 0 ) );
	}
}
