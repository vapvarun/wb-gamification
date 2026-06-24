<?php
/**
 * Smoke-level cron registration coverage for the three Engine classes
 * whose cron hooks were drifting out of the QA coverage manifest:
 *
 *   - IntelligenceProjector::COMPUTE_CRON    (wb_gam_compute_intelligence)
 *   - SideEffectDispatcher::RECONCILE_CRON   (wb_gam_reconcile_side_effects)
 *   - NotificationBridge::PRUNE_CRON         (wb_gam_notifications_queue_prune)
 *
 * Each Engine boots a cron handler with `add_action()` and self-schedules
 * the recurring event guarded by `wp_next_scheduled()`. Before this test
 * those three hooks had zero coverage and were absent from
 * audit/qa-coverage.json, so a regression that dropped the `add_action`
 * or the schedule guard would ship silently. These are smoke tests: they
 * assert the boot contract (handler wired + event scheduled exactly once),
 * not the per-tick behaviour (which has its own suites).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\IntelligenceProjector;
use WBGam\Engine\NotificationBridge;
use WBGam\Engine\SideEffectDispatcher;

/**
 * @coversNothing
 */
class CronRegistrationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Constants used by the boot methods when self-scheduling.
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The three cron hook names that drifted out of qa-coverage.json. This
	 * is the canonical list — if a new Engine cron is added, register it in
	 * audit/qa-coverage.json AND add it here.
	 *
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function cron_hook_provider(): array {
		return array(
			'intelligence projector' => array( IntelligenceProjector::class, 'COMPUTE_CRON', 'wb_gam_compute_intelligence' ),
			'side effect dispatcher' => array( SideEffectDispatcher::class, 'RECONCILE_CRON', 'wb_gam_reconcile_side_effects' ),
			'notification bridge'    => array( NotificationBridge::class, 'PRUNE_CRON', 'wb_gam_notifications_queue_prune' ),
		);
	}

	/**
	 * Each Engine exposes its cron hook as a public class constant so the
	 * scheduler, the handler wiring and the coverage manifest all reference
	 * a single source of truth (no magic-string duplication).
	 *
	 * @dataProvider cron_hook_provider
	 */
	public function test_cron_hook_constant_matches_manifest( string $class, string $const_name, string $expected_hook ): void {
		$this->assertSame( $expected_hook, constant( $class . '::' . $const_name ) );
	}

	/**
	 * IntelligenceProjector::boot() wires the daily compute handler and
	 * self-schedules the recurring event when one is not already queued.
	 */
	public function test_intelligence_projector_boot_wires_and_schedules(): void {
		$this->assertBootWiresCron(
			'wb_gam_compute_intelligence',
			'daily',
			static function (): void {
				IntelligenceProjector::boot();
			}
		);
	}

	/**
	 * SideEffectDispatcher::boot() wires the reconciler handler and
	 * self-schedules the hourly event when one is not already queued.
	 */
	public function test_side_effect_dispatcher_boot_wires_and_schedules(): void {
		$this->assertBootWiresCron(
			'wb_gam_reconcile_side_effects',
			'hourly',
			static function (): void {
				SideEffectDispatcher::boot();
			}
		);
	}

	/**
	 * NotificationBridge::init() wires the daily queue-prune handler and
	 * self-schedules the recurring event when one is not already queued.
	 * (init() also wires the toast collectors and footer render, which are
	 * pure add_action calls covered by the catch-all stub below.)
	 */
	public function test_notification_bridge_init_wires_and_schedules_prune(): void {
		$this->assertBootWiresCron(
			'wb_gam_notifications_queue_prune',
			'daily',
			static function (): void {
				NotificationBridge::init();
			}
		);
	}

	/**
	 * When the event is already scheduled, boot must NOT double-schedule it
	 * (idempotency — the activation hook and the boot hook can both run).
	 *
	 * @dataProvider cron_hook_provider
	 */
	public function test_boot_does_not_reschedule_when_already_queued( string $class, string $const_name, string $hook ): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1893456000 ); // far-future timestamp.

		// The guard short-circuits before wp_schedule_event — assert it is
		// never called for an already-queued hook.
		Functions\expect( 'wp_schedule_event' )->never();

		$this->bootFor( $class );

		// Reaching here without an unexpected-call failure is the assertion.
		$this->assertTrue( true );
	}

	/**
	 * Shared expectation harness: boot wires the handler via add_action for
	 * the cron hook and schedules the recurring event exactly once at the
	 * expected recurrence when nothing is queued yet.
	 *
	 * @param string   $hook       Cron hook name.
	 * @param string   $recurrence Expected WP-Cron recurrence slug.
	 * @param callable $boot       Invokes the Engine boot/init method.
	 */
	private function assertBootWiresCron( string $hook, string $recurrence, callable $boot ): void {
		$wired = false;
		Functions\when( 'add_action' )->alias(
			static function ( $tag ) use ( $hook, &$wired ) {
				if ( $hook === $tag ) {
					$wired = true;
				}
				return true;
			}
		);

		// Not yet scheduled → boot must schedule it.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		// Engines that arm their cron on the `init` hook (e.g. IntelligenceProjector)
		// schedule directly when init has already fired; simulate that so the
		// wp_schedule_event call happens synchronously within this assertion.
		Functions\when( 'did_action' )->justReturn( 1 );

		$scheduled = null;
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $timestamp, $rec, $tag ) use ( $hook, &$scheduled ) {
				if ( $hook === $tag ) {
					$scheduled = $rec;
				}
				return true;
			}
		);

		$boot();

		$this->assertTrue( $wired, "Cron handler for {$hook} was not wired via add_action()." );
		$this->assertSame( $recurrence, $scheduled, "Cron {$hook} was not scheduled at the expected recurrence." );
	}

	/**
	 * Dispatch to the right boot/init entry point for a given Engine class.
	 *
	 * @param string $class Fully-qualified Engine class name.
	 */
	private function bootFor( string $class ): void {
		switch ( $class ) {
			case IntelligenceProjector::class:
				IntelligenceProjector::boot();
				break;
			case SideEffectDispatcher::class:
				SideEffectDispatcher::boot();
				break;
			case NotificationBridge::class:
				NotificationBridge::init();
				break;
		}
	}
}
