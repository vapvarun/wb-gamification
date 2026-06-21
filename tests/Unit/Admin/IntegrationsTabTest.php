<?php
namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\IntegrationsTab;

class IntegrationsTabTest extends TestCase {
	protected function setUp(): void {
		parent::setUp(); Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'http://x/wp-admin/' . $p );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_plugins' )->justReturn( array( 'wb-gamification/wb-gamification.php' => array() ) );
		Functions\when( 'is_plugin_active' )->justReturn( true );
	}
	protected function tearDown(): void {
		// Reset Kit static state so subsequent KitTest::boot_registers_installer_ajax_once
		// starts fresh (Kit::boot() is idempotent once $booted = true).
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
	public function render_outputs_family_guide_with_gamification_onboarding_link(): void {
		IntegrationsTab::init();
		$html = IntegrationsTab::render();
		$this->assertStringContainsString( 'data-region="outcomes"', $html );
		$this->assertStringContainsString( 'page=wb-gamification-setup', $html );
	}

	/** @test */
	public function enqueue_does_not_load_assets_on_non_gamification_hook(): void {
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		IntegrationsTab::enqueue( 'edit.php' );
		$this->addToAssertionCount( 1 );
	}

	/** @test */
	public function enqueue_loads_assets_on_gamification_hook(): void {
		Functions\expect( 'wp_enqueue_style' )->atLeast()->once();
		Functions\expect( 'wp_enqueue_script' )->atLeast()->once();
		Functions\expect( 'wp_localize_script' )->atLeast()->once();
		IntegrationsTab::enqueue( 'toplevel_page_wb-gamification' );
		$this->addToAssertionCount( 1 );
	}
}
