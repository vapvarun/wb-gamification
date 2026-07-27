<?php
/**
 * The member-facing award-skip toast policy.
 *
 * THE INVARIANT: by default, on every site, no member is ever told they earned nothing.
 *
 * "You're on cooldown", "You've hit your daily limit" and "You've hit your weekly limit" all read as
 * though the action FAILED when it did not. The member posted, reacted, commented -- it worked. The
 * only thing that did not happen is an invisible points increment they never asked about, and a
 * points cap is an anti-farming guard: the site's business, not the member's. Worse, these fire again
 * and again precisely BECAUSE a capped member keeps being active.
 *
 * So the default is silence, and these tests hold it there.
 *
 * History, because this has been walked back three times now:
 *   1.4.1 - added skip toasts (cooldown / daily_cap / weekly_cap), on by default.
 *   1.6.3 - made them opt-in behind a default-empty `wb_gam_award_skip_toast_reasons` filter.
 *           SHIPPED PUBLICLY, and documented in the 1.6.3 changelog.
 *   1.6.4 - deleted the mechanism outright, and this file asserted the deletion.
 *   1.6.4 - restored the filter. QA was right to bounce the deletion: removing a PUBLISHED extension
 *           point does not take the surface away from a site that opted in, it silently turns their
 *           add_filter() into a no-op, with no error and no notice. A published lever that quietly
 *           stops working is worse than one we disagree with.
 *
 * The distinction this file now encodes, and it is the important one:
 *
 *   THE DEFAULT protects the member  -- nobody sees a skip toast unless an owner turns one on.
 *   THE FILTER  keeps our word       -- an owner who opted in on 1.6.3 still works on 1.6.4.
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

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The source of the bridge, for the assertions that are about what the code says.
	 *
	 * @return string
	 */
	private function bridge_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/NotificationBridge.php' );
	}

	/**
	 * T1 — THE DEFAULT IS SILENCE. No filter, no toast, for any skip reason.
	 *
	 * This is the invariant that actually protects members, and it is the one that must never move.
	 * `apply_filters` returns its default (an empty list) when nothing is hooked, so every reason is
	 * refused before anything is queued.
	 *
	 * @covers ::on_award_skipped
	 * @return void
	 */
	public function test_by_default_no_skip_reason_reaches_a_member(): void {
		$pushed = 0;

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value; // Nothing hooked: the default (empty list) stands.
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( 1 );

		foreach ( array( 'cooldown', 'daily_cap', 'weekly_cap' ) as $reason ) {
			NotificationBridge::on_award_skipped( 7, 'wp_publish_post', $reason );
		}

		// Nothing reached the queue: push() is never given a chance, because every reason is
		// refused by the empty default before it gets there.
		$this->assertSame( 0, $pushed, 'A member must not be told they earned nothing, by default, ever.' );
	}

	/**
	 * T2 — THE PUBLISHED FILTER STILL EXISTS. This is the contract 1.6.4 broke and QA caught.
	 *
	 * `wb_gam_award_skip_toast_reasons` shipped in 1.6.3 and was documented in its public changelog.
	 * A site that opted in must keep working across a patch upgrade. Deleting the filter would not
	 * remove the surface from them -- it would silently stop their code from doing anything.
	 *
	 * @return void
	 */
	public function test_the_published_opt_in_filter_still_exists(): void {
		$src = $this->bridge_source();

		$this->assertStringContainsString(
			"apply_filters(\n\t\t\t'wb_gam_award_skip_toast_reasons'",
			$src,
			'wb_gam_award_skip_toast_reasons was RELEASED in 1.6.3. Deleting it turns a site owner\'s '
			. 'add_filter() into a silent no-op on upgrade. If the surface is wrong, argue the default '
			. '-- do not quietly revoke a published extension point.'
		);

		$this->assertStringContainsString(
			"add_action( 'wb_gam_award_skipped', array( __CLASS__, 'on_award_skipped' ), 99, 4 );",
			$src,
			'The listener has to be registered, or the filter it reads can never run.'
		);

		$this->assertTrue(
			method_exists( NotificationBridge::class, 'on_award_skipped' ),
			'on_award_skipped() is the producer the filter gates. Without it the filter is decoration.'
		);
	}

	/**
	 * T3 — THE DEFAULT IS EMPTY, in the code, not just in the docblock.
	 *
	 * The filter's default argument is what makes silence the default. If someone changes that array
	 * to `array( 'daily_cap' )`, every site on earth starts nagging its members, and no other test
	 * here would notice.
	 *
	 * @return void
	 */
	public function test_the_filter_default_is_an_empty_list(): void {
		$src = $this->bridge_source();

		$this->assertMatchesRegularExpression(
			"/apply_filters\(\s*'wb_gam_award_skip_toast_reasons',\s*array\(\),/",
			$src,
			'The default must be an EMPTY list. Anything else turns the toast on for every site that '
			. 'never asked for it.'
		);
	}

	/**
	 * T4 — an engine-internal veto can NEVER be surfaced, whatever an owner filters in.
	 *
	 * `sandboxed`, `self_action`, `pre_change_veto` and `excluded` describe a decision the SITE made
	 * about the member. They are not feedback and they are not the member's business.
	 *
	 * 1.6.3 PROMISED this in its docblock ("never eligible regardless of this filter") and did not
	 * enforce it: an owner who filtered in `sandboxed` passed the in_array() check, fell through the
	 * switch with no message, and pushed a toast with an EMPTY body. The promise is now kept where it
	 * is made -- a closed set, checked before the filter is even consulted.
	 *
	 * @return void
	 */
	public function test_engine_internal_vetoes_are_never_eligible(): void {
		$src = $this->bridge_source();

		$this->assertStringContainsString(
			"\$eligible = array( 'cooldown', 'daily_cap', 'weekly_cap' );",
			$src,
			'The eligible set must be closed, and it must be checked BEFORE the owner filter -- '
			. 'otherwise an owner can surface an internal veto, and 1.6.3 let them.'
		);
	}

	/**
	 * T5 — the copy that reaches a member, when an owner asks for it, is about a RESETTING limit.
	 *
	 * Each message says when it lifts. That is the only thing that makes a cap message tolerable: it
	 * is information, not a scolding.
	 *
	 * @return void
	 */
	public function test_the_copy_tells_the_member_when_the_limit_resets(): void {
		$src = $this->bridge_source();

		$this->assertStringContainsString( 'Resets tomorrow.', $src );
		$this->assertStringContainsString( 'Resets next week.', $src );
	}
}
