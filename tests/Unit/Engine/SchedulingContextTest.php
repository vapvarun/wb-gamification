<?php
/**
 * Recurring jobs are checked only in cron, wp-admin and WP-CLI: each check is a
 * database query, and on `init` it was paid by every page, image and heartbeat.
 *
 * @package WB_Gamification
 */

declare( strict_types=1 );

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\SchedulingContext;

#[CoversClass( SchedulingContext::class )]
final class SchedulingContextTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Front-end / REST / AJAX request: the scheduler is never asked.
	 */
	#[Test]
	public function ordinary_request_never_queries_the_scheduler(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\expect( 'as_has_scheduled_action' )->never();

		$this->assertFalse( SchedulingContext::is_scheduling_request() );
		LeaderboardEngine::maybe_schedule();
	}

	/**
	 * Admin-ajax (heartbeat) is an admin request but must not count.
	 */
	#[Test]
	public function heartbeat_ajax_is_not_a_scheduling_request(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );

		$this->assertFalse( SchedulingContext::is_scheduling_request() );
	}

	/**
	 * The cron runner checks and arms the job.
	 */
	#[Test]
	public function cron_runner_checks_the_schedule(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\expect( 'as_has_scheduled_action' )->once()->andReturn( false );

		$this->assertTrue( SchedulingContext::is_scheduling_request() );
		LeaderboardEngine::maybe_schedule();
	}
}
