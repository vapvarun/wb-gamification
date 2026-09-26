<?php
/**
 * get_user_badges() returns earned badges in display (ladder) order, not most-recent first.
 *
 * The BuddyNext profile, REST and apps all list a member's badges through this call. It
 * returned earned_at DESC, so a member who earned tenure badges out of order (or all at once,
 * by import) saw 10-Year before 2-Year on their profile.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeEngine;

/**
 * @coversDefaultClass \WBGam\Engine\BadgeEngine
 */
class UserBadgesOrderTest extends TestCase {

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
	 * @test
	 * @covers ::get_user_badges
	 */
	public function earned_badges_come_back_in_ladder_order(): void {
		$tenure = static fn( int $days ): array => array(
			'rule_config' => wp_json_encode_stub(
				array(
					'match'      => 'all',
					'conditions' => array( array( 'type' => 'tenure_days', 'days' => $days ) ),
				)
			),
		);

		// Active badge rules, as get_active_rules() caches them.
		Functions\when( 'wp_cache_get' )->justReturn(
			array(
				array( 'badge_id' => 'y10' ) + $tenure( 3650 ),
				array( 'badge_id' => 'y1' ) + $tenure( 365 ),
				array( 'badge_id' => 'y5' ) + $tenure( 1825 ),
				array( 'badge_id' => 'y2' ) + $tenure( 730 ),
			)
		);

		$row = static fn( string $id, string $name, string $earned ): array => array(
			'id'            => $id,
			'name'          => $name,
			'description'   => '',
			'image_url'     => '',
			'is_credential' => 0,
			'category'      => 'tenure',
			'earned_at'     => $earned,
			'expires_at'    => null,
		);

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// The query hands them back most-recent first, all earned the same second (an import).
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				$row( 'y10', '10-Year Member', '2026-09-01 10:00:00' ),
				$row( 'y2', '2-Year Member', '2026-09-01 10:00:00' ),
				$row( 'y5', '5-Year Member', '2026-09-01 10:00:00' ),
				$row( 'y1', '1-Year Member', '2026-09-01 10:00:00' ),
			)
		);

		$this->assertSame(
			array( '1-Year Member', '2-Year Member', '5-Year Member', '10-Year Member' ),
			array_column( BadgeEngine::get_user_badges( 7 ), 'name' )
		);
	}
}

/**
 * JSON-encode for the fixture (wp_json_encode is not loaded in unit tests).
 *
 * @param array $data Data.
 * @return string
 */
function wp_json_encode_stub( array $data ): string {
	return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture.
}
