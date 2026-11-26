<?php
/**
 * Every trigger declares how its award leaves: `reverse_on` or `permanent`.
 *
 * Owner rule, 18 Nov 2026: if adding something pays, removing it takes the points back,
 * the same way for every integration. A trigger written without its exit is how 1.6.8
 * had to be fixed plugin by plugin. Source-level: the
 * failure is a missing key in a PHP array, catchable without booting WordPress.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Integrations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class ManifestExitContractTest extends TestCase {

	/**
	 * Trigger blocks per manifest: action id => block source.
	 *
	 * @return array<string, string>
	 */
	private function triggers(): array {
		$out = array();
		foreach ( array_merge( glob( dirname( __DIR__, 3 ) . '/integrations/*.php' ) ?: array(), glob( dirname( __DIR__, 3 ) . '/integrations/contrib/*.php' ) ?: array() ) as $file ) {
			$src = (string) file_get_contents( $file );
			preg_match_all( "/^\t{2,3}'id'\s*=>\s*'([a-z0-9_]+)'/m", $src, $m, PREG_OFFSET_CAPTURE );
			foreach ( $m[1] as $k => $hit ) {
				$start = (int) $m[0][ $k ][1];
				$end   = isset( $m[0][ $k + 1 ] ) ? (int) $m[0][ $k + 1 ][1] : strlen( $src );
				$out[ basename( $file ) . ' ' . $hit[0] ] = substr( $src, $start, $end - $start );
			}
		}
		return $out;
	}

	#[Test]
	public function every_trigger_declares_its_exit(): void {
		$triggers = $this->triggers();
		$this->assertGreaterThan( 100, count( $triggers ), 'Manifest scan found too few triggers; the pattern is broken.' );

		$missing = array();
		foreach ( $triggers as $name => $block ) {
			if ( ! preg_match( "/'reverse_on'|(comment|post)_object_hooks\(|'permanent'\s*=>\s*'[^']+'/", $block ) ) {
				$missing[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"These triggers pay on the way in but say nothing about the way out.\n"
			. "Add 'reverse_on' (the partner's delete / leave / cancel / refund hooks) or\n"
			. "'permanent' => 'why it can never be undone'. See CLAUDE.md, Integration rules.\n"
			. implode( "\n", $missing )
		);
	}

	#[Test]
	public function a_reversible_trigger_knows_its_object(): void {
		$bad = array();
		foreach ( $this->triggers() as $name => $block ) {
			if ( str_contains( $block, "'reverse_on'" ) && ! str_contains( $block, "'once_per_object'" ) ) {
				$bad[] = $name;
			}
		}
		$this->assertSame( array(), $bad, "reverse_on needs once_per_object, so the way out finds the award the way in made:\n" . implode( "\n", $bad ) );
	}
}
