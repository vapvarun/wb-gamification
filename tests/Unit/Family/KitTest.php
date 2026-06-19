<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class KitTest extends TestCase {
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void {
		$r = new \ReflectionClass( \Wbcom\Family\Kit::class );
		foreach ( array( 'config' => array(), 'booted' => false ) as $prop => $val ) {
			$p = $r->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, $val );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @test */
	public function boot_registers_installer_ajax_once(): void {
		Functions\expect( 'add_action' )->once()->with( 'wp_ajax_wbcom_family_install', \Mockery::type( 'array' ) );
		\Wbcom\Family\Kit::boot( array( 'host' => 'wb-gamification', 'onboarding_url' => null ) );
		\Wbcom\Family\Kit::boot( array( 'host' => 'wb-gamification', 'onboarding_url' => null ) ); // second call must NOT re-add
		$this->addToAssertionCount( 1 );
	}

	/** @test */
	public function render_returns_page_html(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'http://x/wp-admin/' . $p ); // needed by Page::render outcome rows
		\Wbcom\Family\Kit::boot( array( 'host' => 'wb-gamification', 'onboarding_url' => null ) );
		$html = \Wbcom\Family\Kit::render();
		$this->assertStringContainsString( 'data-region="outcomes"', $html );
	}
}
