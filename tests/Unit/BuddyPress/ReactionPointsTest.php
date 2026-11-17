<?php
/**
 * A reaction pays the owner's amount, never a floor the plugin forces (1.6.8).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\BuddyPress;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\BuddyPress\ActivityIntegration;

#[CoversNothing]
class ReactionPointsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function nothing_overrides_the_points_the_owner_set_for_a_reaction(): void {
		Functions\when( 'buddypress' )->justReturn( (object) array() );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		ActivityIntegration::init();

		$this->assertFalse( has_filter( 'wb_gam_points_for_action' ), 'A filter here can lift an amount the owner lowered.' );
	}
}
