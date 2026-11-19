<?php
/**
 * Standalone /u/ profile: an unknown username and a private profile give the same response.
 *
 * Security review of WP16 (1.6.8): a private profile answered 403 while an unknown username
 * answered 404, so the page told anyone which usernames exist. Both now take the same path
 * (ProfilePage::decide() === 'private' -> render_private()), and render_private() takes no
 * member, so status, headers and body cannot differ.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ProfilePage;

#[CoversClass( \WBGam\Engine\ProfilePage::class )]
#[CoversMethod( \WBGam\Engine\ProfilePage::class, 'decide' )]
#[CoversMethod( \WBGam\Engine\ProfilePage::class, 'render_private' )]
class ProfilePagePrivateTest extends TestCase {

	/** @var array<int, int> */
	private array $statuses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->statuses = array();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'get_header' )->justReturn( null );
		Functions\when( 'get_footer' )->justReturn( null );
		Functions\when( 'status_header' )->alias( fn( $code ) => $this->statuses[] = (int) $code );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A member with the given id who opted their profile private.
	 *
	 * @param int $id User id.
	 */
	private function private_member( int $id ): \WP_User {
		Functions\when( 'get_user_meta' )->justReturn( '0' );
		Functions\when( 'user_can' )->justReturn( false );
		$user     = \Mockery::mock( 'WP_User' );
		$user->ID = $id;
		$user->display_name = 'Priya Nair';
		return $user;
	}

	/**
	 * Run render_private() and capture its body.
	 */
	private function render(): string {
		ob_start();
		ProfilePage::render_private();
		return (string) ob_get_clean();
	}

	#[Test]
	public function unknown_username_and_private_profile_get_the_same_response(): void {
		$unknown = ProfilePage::decide( null );
		$private = ProfilePage::decide( $this->private_member( 42 ) );

		$this->assertSame( 'private', $unknown );
		$this->assertSame( $unknown, $private, 'Both cases must take the same path.' );

		$body_unknown = $this->render();
		$body_private = $this->render();
		$this->assertSame( $body_unknown, $body_private );
		$this->assertSame( array( 403, 403 ), $this->statuses );
		$this->assertStringNotContainsString( 'Priya', $body_private, 'No name on the private page.' );
		$this->assertStringNotContainsString( '<img', $body_private, 'No avatar on the private page.' );
		$this->assertTrue( has_filter( 'wp_robots', 'wp_robots_no_robots' ) !== false, 'noindex is set.' );
	}

	#[Test]
	public function owner_still_sees_the_full_page(): void {
		$member = $this->private_member( 42 );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( 'profile', ProfilePage::decide( $member ) );
	}
}
