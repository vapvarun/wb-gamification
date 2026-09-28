<?php
/**
 * The weekly nudge run only happens when something can deliver it.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardNudge;

#[CoversClass( LeaderboardNudge::class )]
class LeaderboardNudgeChannelTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_bp_no_email_and_no_listener_means_no_channel(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'has_action' )->justReturn( false );

		$this->assertFalse( LeaderboardNudge::has_delivery_channel() );
	}

	public function test_the_email_option_is_a_channel(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'has_action' )->justReturn( false );

		$this->assertTrue( LeaderboardNudge::has_delivery_channel() );
	}

	public function test_a_listener_on_the_sent_hook_is_a_channel(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'has_action' )->alias( static fn( $hook ) => 'wb_gam_weekly_nudge_sent' === $hook ? 10 : false );

		$this->assertTrue( LeaderboardNudge::has_delivery_channel() );
	}

	public function test_the_batch_returns_before_it_locks_or_queries_when_there_is_no_channel(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/LeaderboardNudge.php' );
		$a   = strpos( $src, 'self::has_delivery_channel()' );
		$b   = strpos( $src, 'Lock::run(' );

		$this->assertNotFalse( $a );
		$this->assertLessThan( $b, $a, 'The channel check must come before the dispatch lock, or a skipped run would still burn the hour window.' );
	}
}
