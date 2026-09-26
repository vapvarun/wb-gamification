<?php
/**
 * Every icon a bundled integration manifest declares must exist in the bundled icon font.
 *
 * The earning guide prints the manifest `icon` verbatim as a class. A Dashicons name, or a
 * Lucide name the font renamed (check-circle -> circle-check), paints an empty box.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ManifestIconsTest extends TestCase {

	/**
	 * @test
	 */
	public function every_manifest_icon_exists_in_the_lucide_font(): void {
		$root = dirname( __DIR__, 3 );
		$font = (string) file_get_contents( $root . '/assets/fonts/lucide.css' );

		$missing = array();
		foreach ( glob( $root . '/integrations/*.php' ) as $file ) {
			preg_match_all( "/'icon'\s*=>\s*'([^']+)'/", (string) file_get_contents( $file ), $m );
			foreach ( $m[1] as $icon ) {
				if ( ! str_contains( $font, '.' . $icon . ':' ) ) {
					$missing[] = basename( $file ) . ': ' . $icon;
				}
			}
		}

		$this->assertSame( array(), $missing );
	}
}
