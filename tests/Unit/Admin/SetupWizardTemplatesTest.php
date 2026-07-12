<?php
/**
 * Every starter-template action id must actually exist.
 *
 * The setup wizard is the first screen a site owner sees, and its whole job is the
 * first impression. Until 1.6.4 two of its five templates were dead on arrival:
 *
 *   Coaching Platform  seeded  check_in (15), goal_complete (50)
 *   Nonprofit/Mission  seeded  volunteer_hours (30)
 *
 * None of those three action ids exists in any manifest under integrations/. They can
 * never fire. An owner who picked "Coaching Platform" got a config where 2 of its 3
 * actions were inert, and nothing anywhere said so — apply_template() wrote a
 * `wb_gam_points_{id}` option for whatever string it was handed.
 *
 * This test reads the templates and the manifests from source and asserts the ids
 * intersect. It is deliberately source-level rather than runtime: the failure mode is
 * a typo or a stale id in a PHP array, and that is catchable without booting WordPress.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \WBGam\Admin\SetupWizard
 */
class SetupWizardTemplatesTest extends TestCase {

	/**
	 * Every `'id' => '...'` declared across integrations/*.php.
	 *
	 * @return string[]
	 */
	private function registered_action_ids(): array {
		$root = dirname( __DIR__, 3 );
		$ids  = array();

		$files = glob( $root . '/integrations/*.php' ) ?: array();
		$files = array_merge( $files, glob( $root . '/integrations/**/*.php' ) ?: array() );

		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match_all( "/'id'\s*=>\s*'([a-z0-9_]+)'/i", $src, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Every `action_id => points` pair in get_template_configs().
	 *
	 * @return array<string, string[]> template slug => action ids
	 */
	private function template_action_ids(): array {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/SetupWizard.php' );

		// Isolate get_template_configs() so we don't sweep up unrelated arrays.
		$start = strpos( $src, 'function get_template_configs' );
		$this->assertNotFalse( $start, 'get_template_configs() not found' );
		$body = substr( $src, $start );

		$out = array();
		// Each template block: 'slug' => array( ... 'points' => array( ... ) ... )
		if ( preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*array\(\s*\n\s*'label'/", $body, $tpl, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tpl[1] as $i => $match ) {
				$slug  = $match[0];
				$from  = $tpl[0][ $i ][1];
				$to    = isset( $tpl[0][ $i + 1 ] ) ? $tpl[0][ $i + 1 ][1] : strlen( $body );
				$chunk = substr( $body, $from, $to - $from );

				$pstart = strpos( $chunk, "'points'" );
				if ( false === $pstart ) {
					continue;
				}
				$points = substr( $chunk, $pstart );

				if ( preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*\d+/", $points, $pm ) ) {
					$out[ $slug ] = $pm[1];
				}
			}
		}

		return $out;
	}

	/**
	 * The manifests must actually yield action ids — otherwise this test is vacuous
	 * and would pass no matter how broken the templates were.
	 *
	 * @test
	 */
	public function the_manifest_scan_finds_actions(): void {
		$ids = $this->registered_action_ids();
		$this->assertGreaterThan(
			50,
			count( $ids ),
			'Expected to parse many action ids from integrations/. If this fails the parser is broken '
			. 'and the real assertion below would pass vacuously.'
		);
		$this->assertContains( 'wp_publish_post', $ids );
	}

	/**
	 * No starter template may seed an action that does not exist.
	 *
	 * @test
	 */
	public function every_template_action_id_is_registered(): void {
		$registered = $this->registered_action_ids();
		$templates  = $this->template_action_ids();

		$this->assertNotEmpty( $templates, 'Parsed no templates — the parser is broken.' );

		$orphans = array();
		foreach ( $templates as $slug => $ids ) {
			foreach ( $ids as $id ) {
				if ( ! in_array( $id, $registered, true ) ) {
					$orphans[] = "{$slug} => {$id}";
				}
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			"Setup-wizard template(s) seed action ids that exist in NO integration manifest.\n"
			. "These can never fire: the wizard writes a wb_gam_points_{id} option for them and the\n"
			. "owner gets dead config on the plugin's first-impression screen.\n"
			. 'Orphans: ' . implode( ', ', $orphans )
		);
	}
}
