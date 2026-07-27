<?php
/**
 * Regression tests for member profile URL resolution (1.6.4).
 *
 * Locks the fix for the bug where a site without classic BuddyPress got three
 * different answers to "where does this member's name link to?" — the author
 * archive on some surfaces, no link at all on others, and the correct public
 * profile on exactly one. The author archive is not a destination this plugin
 * ever wants: it lists the member's posts, not their achievements, and plenty
 * of sites disable it entirely.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\MemberUrl;

/**
 * @coversDefaultClass \WBGam\Engine\MemberUrl
 */
class MemberUrlTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Defaults: options return their default (2nd arg) so the public-profile
		// kill switch reads '1' and the slug base reads 'u'; no per-user privacy
		// meta; filters pass through.
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		// Brain Monkey leaves a stubbed function DEFINED for the rest of the
		// process, so a later test's `function_exists()` would see whatever an
		// earlier test defined. Pin it: BuddyPress hands back nothing by
		// default, which is what UserUrl::resolve() returns on a site without
		// BuddyPress and is the input MemberUrl is being tested against.
		Functions\when( 'bp_members_get_user_url' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $user_id ) {
				return (object) array(
					'ID'           => $user_id,
					'user_login'   => 'member' . $user_id,
					'display_name' => 'Member ' . $user_id,
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * BuddyPress owns the member profile wherever it is active — a BP site must
	 * see exactly the behaviour it saw before 1.6.4.
	 *
	 * @test
	 * @covers ::resolve
	 */
	public function buddypress_url_wins_when_buddypress_is_active(): void {
		Functions\when( 'bp_members_get_user_url' )->justReturn( 'https://example.test/members/member7/' );

		$this->assertSame( 'https://example.test/members/member7/', MemberUrl::resolve( 7 ) );
	}

	/**
	 * The bug: with no BuddyPress, callers fell back to the author archive.
	 * The public profile is the right answer, and it is the one we now give.
	 *
	 * @test
	 * @covers ::resolve
	 */
	public function falls_back_to_the_public_profile_when_buddypress_is_absent(): void {
		$this->assertSame( 'https://example.test/u/member7/', MemberUrl::resolve( 7 ) );
	}

	/**
	 * The trap in the originally-suggested fix: /u/{login} 404s when the member
	 * has opted their profile private, so building it unconditionally would have
	 * traded a wrong link for a broken one.
	 *
	 * @test
	 * @covers ::resolve
	 */
	public function returns_nothing_when_the_member_opted_their_profile_private(): void {
		Functions\when( 'get_user_meta' )->justReturn( '0' );

		$this->assertSame( '', MemberUrl::resolve( 7 ) );
	}

	/**
	 * Same, for the owner's site-wide kill switch.
	 *
	 * @test
	 * @covers ::resolve
	 */
	public function returns_nothing_when_public_profiles_are_switched_off_site_wide(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', MemberUrl::resolve( 7 ) );
	}

	/**
	 * @test
	 * @covers ::resolve
	 */
	public function returns_nothing_for_an_invalid_user_id(): void {
		$this->assertSame( '', MemberUrl::resolve( 0 ) );
		$this->assertSame( '', MemberUrl::resolve( -3 ) );
	}

	/**
	 * The guard that would have caught the original bug on every surface at once:
	 * whatever the configuration, the resolver never hands back an author archive.
	 *
	 * @test
	 * @covers ::resolve
	 */
	public function never_resolves_to_an_author_archive(): void {
		Functions\when( 'get_author_posts_url' )->justReturn( 'https://example.test/author/member7/' );

		$configurations = array(
			'default'            => static function (): void {},
			'profile private'    => static function (): void {
				Functions\when( 'get_user_meta' )->justReturn( '0' );
			},
			'profiles off'       => static function (): void {
				Functions\when( 'get_option' )->justReturn( '' );
			},
			'buddypress active'  => static function (): void {
				Functions\when( 'bp_members_get_user_url' )->justReturn( 'https://example.test/members/member7/' );
			},
		);

		foreach ( $configurations as $label => $configure ) {
			$configure();
			$this->assertStringNotContainsString(
				'/author/',
				MemberUrl::resolve( 7 ),
				"Resolver returned an author archive URL with configuration: {$label}"
			);
		}
	}

	/**
	 * @test
	 * @covers ::resolve
	 */
	public function filter_can_point_at_a_third_party_profile_system(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $user_id = 0 ) {
				return 'wb_gam_member_url' === $hook ? "https://example.test/people/{$user_id}" : $value;
			}
		);

		$this->assertSame( 'https://example.test/people/7', MemberUrl::resolve( 7 ) );
	}

	/**
	 * @test
	 * @covers ::wrap
	 */
	public function wrap_renders_an_anchor_when_there_is_a_destination(): void {
		$this->assertSame(
			'<a href="https://example.test/u/member7/" class="wb-gam-name">Member 7</a>',
			MemberUrl::wrap( 'https://example.test/u/member7/', 'Member 7', 'wb-gam-name' )
		);
	}

	/**
	 * A name with nowhere to go is not a dead anchor — but it still needs the
	 * class the anchor was carrying, or the block loses its styling.
	 *
	 * @test
	 * @covers ::wrap
	 */
	public function wrap_keeps_the_class_on_a_span_when_there_is_no_destination(): void {
		$this->assertSame(
			'<span class="wb-gam-name">Member 7</span>',
			MemberUrl::wrap( '', 'Member 7', 'wb-gam-name' )
		);
	}

	/**
	 * @test
	 * @covers ::wrap
	 */
	public function wrap_returns_bare_content_when_there_is_no_destination_and_no_class(): void {
		$this->assertSame( 'Member 7', MemberUrl::wrap( '', 'Member 7' ) );
	}
}
