<?php
/**
 * Unit tests for the Appearance accent-color engine.
 *
 * Locks the "override if set, else theme default" contract: an empty option
 * emits no CSS (theme fallback applies); a set option emits a :root override
 * (light + dark) derived from the chosen hex; invalid input degrades to the
 * default rather than poisoning the frontend.
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
use WBGam\Engine\Appearance;

#[CoversClass( \WBGam\Engine\Appearance::class )]
#[CoversMethod( \WBGam\Engine\Appearance::class, 'get_accent' )]
#[CoversMethod( \WBGam\Engine\Appearance::class, 'inline_css' )]
#[CoversMethod( \WBGam\Engine\Appearance::class, 'set_accent' )]
class AppearanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		// Core hex sanitizer: accept #rgb / #rrggbb, else null (WP behavior).
		Functions\when( 'sanitize_hex_color' )->alias(
			static function ( $color ) {
				return ( is_string( $color ) && preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) )
					? $color
					: null;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function empty_option_means_theme_default(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		$this->assertSame( '', Appearance::get_accent() );
	}

	#[Test]
	public function valid_hex_is_returned_lowercased(): void {
		Functions\when( 'get_option' )->justReturn( '#5B4CDB' );
		$this->assertSame( '#5b4cdb', Appearance::get_accent() );
	}

	#[Test]
	public function corrupt_option_degrades_to_default(): void {
		Functions\when( 'get_option' )->justReturn( 'rgb(1,2,3)' );
		$this->assertSame( '', Appearance::get_accent() );
	}

	#[Test]
	public function no_override_emits_empty_css(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		$this->assertSame( '', Appearance::inline_css() );
	}

	#[Test]
	public function override_emits_light_and_dark_root_rules(): void {
		Functions\when( 'get_option' )->justReturn( '#059669' );
		$css = Appearance::inline_css();

		$this->assertStringContainsString( '--wb-gam-color-accent:#059669', $css );
		$this->assertStringContainsString( '--wb-gam-color-accent-hover:', $css );
		$this->assertStringContainsString( '--wb-gam-color-accent-ring:', $css );
		// Dark-mode treatment present so a custom accent stays consistent.
		$this->assertStringContainsString( 'data-bx-mode="dark"', $css );
		$this->assertStringContainsString( 'prefers-color-scheme:dark', $css );
	}

	#[Test]
	public function set_accent_stores_valid_hex_lowercased(): void {
		$stored = array();
		Functions\when( 'update_option' )->alias(
			static function ( $k, $v ) use ( &$stored ) {
				$stored = array( $k, $v );
				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		Appearance::set_accent( '#ABCDEF' );
		$this->assertSame( array( 'wb_gam_accent_color', '#abcdef' ), $stored );
	}

	#[Test]
	public function set_accent_empty_or_invalid_deletes_option(): void {
		$deleted = array();
		Functions\when( 'update_option' )->alias(
			static function () {
				throw new \RuntimeException( 'update_option must not run for an empty/invalid accent' );
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $k ) use ( &$deleted ) {
				$deleted[] = $k;
				return true;
			}
		);

		Appearance::set_accent( '' );
		Appearance::set_accent( 'purple' );
		$this->assertSame( array( 'wb_gam_accent_color', 'wb_gam_accent_color' ), $deleted );
	}
}
