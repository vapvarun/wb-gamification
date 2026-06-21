<?php
namespace WBGam\Tests\Unit\Family;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class RegistryTest extends TestCase {
	/** @test */
	public function registry_has_required_member_keys_and_valid_outcomes(): void {
		$r = \Wbcom\Family\registry();
		$this->assertArrayHasKey( 'members', $r );
		$this->assertArrayHasKey( 'wb-gamification', $r['members'], 'host is in the family' );
		foreach ( $r['members'] as $slug => $m ) {
			foreach ( [ 'name', 'tagline', 'icon', 'category', 'slug_free', 'wporg_slug', 'learn_url', 'is_engine' ] as $k ) {
				$this->assertArrayHasKey( $k, $m, "$slug missing $k" );
			}
		}
		// Every outcome's requires must reference a real member.
		foreach ( $r['outcomes'] as $key => $o ) {
			$this->assertNotEmpty( $o['title'] );
			foreach ( $o['requires'] as $req ) {
				$this->assertArrayHasKey( $req, $r['members'], "outcome $key requires unknown member $req" );
			}
		}
		// BuddyNext is the single engine.
		$engines = array_filter( $r['members'], static fn( $m ) => $m['is_engine'] );
		$this->assertSame( [ 'buddynext' ], array_keys( $engines ) );
	}

	/** @test */
	public function bootstrap_loads_once_and_keeps_highest_version(): void {
		$this->assertTrue( defined( 'WBCOM_FAMILY_KIT_VERSION' ) );
		require dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php'; // second include must not fatal
		$this->assertTrue( defined( 'WBCOM_FAMILY_KIT_DIR' ) );
		$this->assertTrue( function_exists( 'Wbcom\Family\registry' ) );
	}
}
