<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class InstallerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp(); Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
	}
	protected function tearDown(): void {
		unset( $_POST['slug'] );
		Monkey\tearDown(); parent::tearDown();
	}

	/** @test */
	public function blocks_users_without_install_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$captured = null;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data, $code = 0 ) use ( &$captured ) { $captured = array( $data, $code ); throw new \RuntimeException( 'halt' ); } );
		try { \Wbcom\Family\Installer::handle(); } catch ( \RuntimeException $e ) { /* expected halt */ }
		$this->assertSame( 403, $captured[1] );
	}

	/** @test */
	public function blocks_users_with_install_but_lacking_activate_capability(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'install_plugins' === $cap );
		$captured = null;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data, $code = 0 ) use ( &$captured ) { $captured = array( $data, $code ); throw new \RuntimeException( 'halt' ); } );
		try { \Wbcom\Family\Installer::handle(); } catch ( \RuntimeException $e ) { /* expected halt */ }
		$this->assertSame( 403, $captured[1] );
	}

	/** @test */
	public function refuses_pro_or_unknown_members(): void {
		$_POST['slug'] = 'learnomy'; // learnomy has wporg_slug=null in the registry
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		$captured = null;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data, $code = 0 ) use ( &$captured ) { $captured = array( $data, $code ); throw new \RuntimeException( 'halt' ); } );
		try { \Wbcom\Family\Installer::handle(); } catch ( \RuntimeException $e ) { /* expected */ }
		$this->assertSame( 400, $captured[1] );
		$this->assertStringContainsStringIgnoringCase( 'install', (string) ( $captured[0]['message'] ?? '' ) );
	}
}
