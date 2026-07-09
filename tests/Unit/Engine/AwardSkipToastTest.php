<?php
/**
 * Unit tests for the member-facing award-skip toast policy.
 *
 * Locks the 1.6.3 UX invariant: EVERY award-skip reason (cooldown, daily cap,
 * weekly cap) is silent to the member by default. Gamification is positive
 * reinforcement - members see reward toasts, never a "you earned nothing"
 * message, which reads as an error and demotivates at scale. A site owner can
 * opt specific reasons back in via the wb_gam_award_skip_toast_reasons filter.
 *
 * on_award_skipped() short-circuits before push() for a non-surfaced reason,
 * and push()'s first WordPress call is get_transient(), so "no toast queued"
 * is provable by asserting get_transient()/set_transient() are never reached;
 * "toast queued" is provable by asserting get_transient() IS reached.
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
		// Pass-through: the reasons filter returns its default (2nd arg).
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A transient cooldown must not queue a member toast by default.
	 *
	 * @test
	 * @covers ::on_award_skipped
	 */
	public function cooldown_skip_shows_no_toast_by_default(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		NotificationBridge::on_award_skipped( 7, 'bn_post_created', 'cooldown', array( 'cooldown_seconds' => 30 ) );
	}

	/**
	 * A resetting limit (daily / weekly cap) is ALSO silent by default — the
	 * default surfaced set is empty. Members never get a "you earned nothing"
	 * toast for normal activity.
	 *
	 * @test
	 * @covers ::on_award_skipped
	 */
	public function resetting_caps_are_silent_by_default(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		NotificationBridge::on_award_skipped( 7, 'bn_post_created', 'daily_cap', array() );
		NotificationBridge::on_award_skipped( 7, 'bn_post_created', 'weekly_cap', array() );
	}

	/**
	 * The surfaced reasons are governed by wb_gam_award_skip_toast_reasons — a
	 * site owner can opt a reason back in, and then it DOES reach the toast queue.
	 *
	 * @test
	 * @covers ::on_award_skipped
	 */
	public function opting_a_reason_in_surfaces_the_toast(): void {
		$reached_push = false;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( &$reached_push ) {
				if ( 'wb_gam_award_skip_toast_reasons' === $tag ) {
					return array( 'daily_cap' );
				}
				if ( 'wb_gam_toast_data' === $tag ) {
					// Entering push() proves the reason was surfaced. Return empty
					// so push() bails immediately (no deep transient/meta mocking).
					$reached_push = true;
					return array();
				}
				return $value;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		// push() bailed on empty toast data, so the queue is never touched.
		Functions\expect( 'get_transient' )->never();

		NotificationBridge::on_award_skipped( 7, 'bn_post_created', 'daily_cap', array() );

		$this->assertTrue( $reached_push, 'push() must be entered when a reason is opted in' );
	}
}
