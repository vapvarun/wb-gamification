<?php
/**
 * The community notification contract: the payload WB Gamification hands a
 * host plugin (BuddyNext) through `wb_gam_notification_created`, the declared
 * types, the visibility answer, and the removal hook.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\CommunityNotifications;

#[CoversClass( \WBGam\Engine\CommunityNotifications::class )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'on_badge_awarded' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'on_level_changed' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'on_kudos_given' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'on_kudos_revoked' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'notify' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'on_personal_record' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'filter_types' )]
#[CoversMethod( \WBGam\Engine\CommunityNotifications::class, 'on_badge_deleted' )]
class CommunityNotificationsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every do_action() call this test process observed: [ hook, args ].
	 *
	 * @var array<int,array{0:string,1:array<int,mixed>}>
	 */
	private array $fired = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->fired = array();

		// MemberUrl::resolve() runs for real inside notification_url(); its own
		// suite (MemberUrlTest) proves that chain, so here `wb_gam_member_url`
		// is just pinned to a fixed destination and every other filter passes
		// through the value already computed.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$rest ) {
				return 'wb_gam_member_url' === $hook ? 'https://example.test/u/member/' : $value;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) {
				$this->fired[] = array( $hook, $args );
			}
		);
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'bp_members_get_user_url' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( (string) $key ) );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( 'number_format' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'get_post_status' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( '' );
		Functions\when( 'current_time' )->alias( static fn( $type ) => 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_userdata' )->alias(
			static fn( $user_id ) => (object) array(
				'ID'           => $user_id,
				'user_login'   => 'member' . $user_id,
				'display_name' => 'Member ' . $user_id,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function lastPayload(): ?array {
		foreach ( array_reverse( $this->fired ) as [ $hook, $args ] ) {
			if ( 'wb_gam_notification_created' === $hook ) {
				return $args[0];
			}
		}
		return null;
	}

	/**
	 * @return array<int,array{0:string,1:int}>
	 */
	private function removals(): array {
		$out = array();
		foreach ( $this->fired as [ $hook, $args ] ) {
			if ( 'wb_gam_community_notification_removed' === $hook ) {
				$out[] = array( $args[0], $args[1] );
			}
		}
		return $out;
	}

	#[Test]
	public function badge_awarded_fires_the_contract_payload(): void {
		CommunityNotifications::on_badge_awarded( 7, array( 'name' => 'First Post' ), 'first_post' );

		$payload = $this->lastPayload();
		$this->assertNotNull( $payload );
		$this->assertSame( 7, $payload['recipient_id'] );
		$this->assertSame( 'badge_awarded', $payload['type'] );
		$this->assertSame( 'badge', $payload['object_type'] );
		$this->assertSame( (int) crc32( 'first_post' ), $payload['object_id'] );
		$this->assertStringContainsString( 'First Post', $payload['message'] );
		$this->assertSame( 'https://example.test/u/member/', $payload['url'] );
		$this->assertSame( 'badge_awarded_first_post', $payload['group_key'] );
	}

	#[Test]
	public function level_up_only_notifies_on_a_climb(): void {
		$new = array(
			'id'         => 2,
			'name'       => 'Contributor',
			'min_points' => 100,
		);
		$old = array(
			'id'         => 1,
			'name'       => 'Newcomer',
			'min_points' => 0,
		);

		CommunityNotifications::on_level_changed( 7, $new, $old );
		$payload = $this->lastPayload();
		$this->assertNotNull( $payload );
		$this->assertSame( 'level_up', $payload['type'] );
		$this->assertSame( 2, $payload['object_id'] );
		$this->assertStringContainsString( 'Contributor', $payload['message'] );

		$this->fired = array();
		CommunityNotifications::on_level_changed( 7, $old, $new ); // A drop.
		$this->assertNull( $this->lastPayload(), 'a level drop must not notify' );
	}

	#[Test]
	public function kudos_is_attributed_to_the_giver_and_removable(): void {
		CommunityNotifications::on_kudos_given( 3, 7, 'Nice work', 55 );

		$payload = $this->lastPayload();
		$this->assertSame( 7, $payload['recipient_id'] );
		$this->assertSame( 3, $payload['actor_id'] );
		$this->assertSame( 'kudos_received', $payload['type'] );
		$this->assertSame( 'kudos', $payload['object_type'] );
		$this->assertSame( 55, $payload['object_id'] );
		$this->assertStringContainsString( 'Member 3', $payload['message'] );

		$this->fired = array();
		CommunityNotifications::on_kudos_revoked( 55 );
		$this->assertContains( array( 'kudos', 55 ), $this->removals() );
	}

	#[Test]
	public function never_fires_for_the_actor_notifying_themself(): void {
		CommunityNotifications::on_kudos_given( 7, 7, 'to myself', 1 );
		$this->assertNull( $this->lastPayload() );
	}

	#[Test]
	public function personal_record_groups_by_period_bucket_and_skips_trivial_daily_bests(): void {
		CommunityNotifications::on_personal_record( 7, 'day', 5, 3, 'New daily best' );
		$this->assertNull( $this->lastPayload(), 'a daily best is not announced by default' );

		CommunityNotifications::on_personal_record( 7, 'week', 50, 20, 'New weekly best' );
		$payload = $this->lastPayload();
		$this->assertNotNull( $payload );
		$this->assertSame( 'personal_record', $payload['type'] );
		$this->assertSame( 'New weekly best', $payload['message'] );
		$this->assertStringStartsWith( 'personal_record_week_', $payload['group_key'] );

		// A second fire in the SAME bucket carries the SAME group_key, so
		// BuddyNext's contract receiver merges it into the existing row
		// instead of inserting a second one.
		$first_key = $payload['group_key'];
		CommunityNotifications::on_personal_record( 7, 'week', 80, 50, 'Even better' );
		$this->assertSame( $first_key, $this->lastPayload()['group_key'] );
		// ...and says renotify => false, so BuddyNext refreshes that row quietly
		// instead of re-surfacing it and pushing again on every new best.
		$this->assertFalse( $this->lastPayload()['renotify'] );
	}

	#[Test]
	public function declares_all_eight_types(): void {
		$types = CommunityNotifications::filter_types( array() );

		foreach ( array( 'badge_awarded', 'level_up', 'kudos_received', 'challenge_completed', 'reward_fulfilled', 'credential_expired', 'personal_record', 'streak_milestone' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $types, "{$slug} must be declared" );
			$this->assertNotSame( '', $types[ $slug ]['label'] );
			$this->assertTrue( $types[ $slug ]['default_on'] );
		}
	}

	#[Test]
	public function badge_deleted_removes_by_the_same_hashed_id_used_to_notify(): void {
		CommunityNotifications::on_badge_awarded( 7, array( 'name' => 'First Post' ), 'first_post' );
		$awarded_id = $this->lastPayload()['object_id'];

		$this->fired = array();
		CommunityNotifications::on_badge_deleted( 'first_post' );

		$this->assertContains( array( 'badge', $awarded_id ), $this->removals(), 'removal must target the same object_id the award used' );
	}
}
