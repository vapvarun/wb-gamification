<?php
/**
 * Unit tests for the member-facing award-skip toast policy.
 *
 * Locks the 1.6.3 UX invariant: a transient per-action cooldown is skipped
 * SILENTLY (no "you're on cooldown - try again in a bit" nag), while a real
 * resetting limit (daily / weekly cap) still surfaces. The surfaced set is
 * governed by the wb_gam_award_skip_toast_reasons filter.
 *
 * on_award_skipped() short-circuits before push() for a non-surfaced reason,
 * and push()'s first WordPress call is get_transient(), so "no toast queued"
 * is provable by asserting get_transient()/set_transient() are never reached.
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
	 * The surfaced reasons are governed by wb_gam_award_skip_toast_reasons —
	 * narrowing the set to nothing silences even a daily cap.
	 *
	 * @test
	 * @covers ::on_award_skipped
	 */
	public function skip_toast_reasons_are_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return 'wb_gam_award_skip_toast_reasons' === $tag ? array() : $value;
			}
		);
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		NotificationBridge::on_award_skipped( 7, 'bn_post_created', 'daily_cap', array() );
	}
}
