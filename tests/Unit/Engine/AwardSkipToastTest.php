<?php
/**
 * Unit tests for the member-facing award-skip toast policy.
 *
 * Locks the 1.6.4 product invariant: a member is NEVER told they earned nothing.
 * There is no configuration of this product in which "You're on cooldown",
 * "You've hit your daily limit", or "You've hit your weekly limit" reaches a
 * member. The action they took SUCCEEDED - the only thing that did not happen is
 * an invisible points increment they never asked about. A points cap is an
 * anti-farming guard: the site's business, not the member's.
 *
 * History, because this invariant has been walked back twice:
 *   1.4.1 - added skip toasts (cooldown / daily_cap / weekly_cap).
 *   1.6.3 - made them opt-in behind a default-empty
 *           `wb_gam_award_skip_toast_reasons` filter.
 *   1.6.4 - removed the mechanism outright. An opt-in lever is not neutral: it
 *           keeps a member-hostile surface alive, keeps writing `skip` rows into
 *           the durable notification queue, and invites a site owner to switch a
 *           demotivator back on.
 *
 * These tests fail if anyone re-registers a listener on `wb_gam_award_skipped`
 * or reintroduces a skip-toast producer.
 *
 * The `wb_gam_award_skipped` action itself is still FIRED by PointsEngine and is
 * still a supported extension point - gamification just ships no toast for it.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\NotificationBridge;

/**
 * @coversDefaultClass \WBGam\Engine\NotificationBridge
 */
class AwardSkipToastTest extends TestCase {

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
	 * Boot must not subscribe to `wb_gam_award_skipped`. This is the guard that
	 * actually prevents the toast: with no listener, a skip can never reach
	 * push(), can never be queued, and can never be rendered.
	 *
	 * @test
	 * @covers ::init
	 */
	public function boot_registers_no_listener_for_award_skipped(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( true );
		Functions\when( 'wp_schedule_event' )->justReturn( true );

		NotificationBridge::init();

		$this->assertFalse(
			has_action( 'wb_gam_award_skipped' ),
			'wb_gam_award_skipped must have NO listener - a member is never told they earned nothing.'
		);
	}

	/**
	 * The skip-toast producer is gone, not merely disabled. A dormant
	 * `on_award_skipped()` would be one add_action() away from returning.
	 *
	 * @test
	 */
	public function the_skip_toast_producer_no_longer_exists(): void {
		$this->assertFalse(
			method_exists( NotificationBridge::class, 'on_award_skipped' ),
			'on_award_skipped() must not exist - the mechanism is removed, not opt-in.'
		);
	}

	/**
	 * The opt-in lever is gone too. While it existed, a site owner could switch
	 * the demotivator back on for 100k members.
	 *
	 * @test
	 */
	public function the_opt_in_filter_is_gone(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/NotificationBridge.php' );

		$this->assertStringNotContainsString(
			"apply_filters(\n\t\t\t'wb_gam_award_skip_toast_reasons'",
			$source,
			'The wb_gam_award_skip_toast_reasons opt-in must not be reintroduced.'
		);
	}

	/**
	 * None of the three "you earned nothing" strings may be produced anywhere in
	 * the bridge. This catches a reintroduction that renames the method or moves
	 * the switch rather than restoring it verbatim.
	 *
	 * @test
	 */
	public function no_you_earned_nothing_copy_survives_in_the_bridge(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/NotificationBridge.php' );

		// Only the explanatory comment block may mention these; assert no
		// translatable string wraps them (i.e. no __( "You're on cooldown"... ).
		foreach ( array( "You're on cooldown", "You've hit your daily limit", "You've hit your weekly limit" ) as $copy ) {
			$this->assertStringNotContainsString(
				'__( "' . $copy,
				$source,
				"Translatable member-facing copy '{$copy}' must not exist - members are never told they earned nothing."
			);
		}
	}

	/**
	 * The copy is not deleted, it is RELOCATED. A caller that POSTs to /events
	 * explicitly fired an award and is entitled to know why it did not land -
	 * that is diagnostics it asked for, not a message pushed at someone who
	 * didn't. PointsEngine::skip_reason_message() is the only place this copy may
	 * live, and EventsController is its only consumer.
	 *
	 * @test
	 */
	public function skip_copy_survives_for_api_callers(): void {
		Functions\when( '__' )->returnArg( 1 );

		foreach ( array( 'cooldown', 'daily_cap', 'weekly_cap' ) as $reason ) {
			$this->assertNotSame(
				'',
				\WBGam\Engine\PointsEngine::skip_reason_message( $reason ),
				"An API caller must still be able to resolve '{$reason}' to a human explanation."
			);
		}

		// Engine-internal vetoes have no caller-facing explanation.
		$this->assertSame( '', \WBGam\Engine\PointsEngine::skip_reason_message( 'sandboxed' ) );
		$this->assertSame( '', \WBGam\Engine\PointsEngine::skip_reason_message( 'self_action' ) );
	}
}
