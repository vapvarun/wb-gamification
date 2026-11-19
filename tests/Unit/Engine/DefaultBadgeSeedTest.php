<?php
/**
 * A fresh install creates the seven tenure and site-first badges the readme promises.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\FeatureFlags;
use WBGam\Engine\Installer;

/**
 * Runs the private seed against a fake $wpdb and reads back what it wrote.
 */
#[CoversClass( Installer::class )]
class DefaultBadgeSeedTest extends TestCase {

	/**
	 * Fake database with the rows the seed wrote.
	 *
	 * @var object
	 */
	private object $db;

	/**
	 * Run the seed.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'current_time' )->justReturn( '2026-10-31 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->db = new class() {
			public string $prefix = 'wp_';
			public array $defs    = array();
			public array $rules   = array();

			public function prepare( $sql, ...$args ) {
				return $sql;
			}

			public function get_var( $sql ) {
				return str_contains( $sql, 'COUNT(*)' ) ? 0 : 5; // No badges yet; Champion is level 5.
			}

			public function insert( $table, $row, $format ) {
				if ( str_ends_with( $table, 'badge_defs' ) ) {
					$this->defs[ $row['id'] ] = $row;
				} else {
					$this->rules[ $row['target_id'] ] = json_decode( $row['rule_config'], true )['conditions'][0];
				}
				return 1;
			}
		};

		$GLOBALS['wpdb'] = $this->db;

		( new \ReflectionMethod( Installer::class, 'seed_default_badges' ) )->invoke( null );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_library_has_37_badges_each_with_a_rule(): void {
		$this->assertCount( 37, $this->db->defs );
		$this->assertSame( array_keys( $this->db->defs ), array_keys( $this->db->rules ) );
	}

	public function test_tenure_badges_have_their_day_counts_and_no_earner_limit(): void {
		foreach ( array( 'tenure_1yr' => 365, 'tenure_2yr' => 730, 'tenure_5yr' => 1825, 'tenure_10yr' => 3650 ) as $id => $days ) {
			$this->assertSame( array( 'type' => 'tenure_days', 'days' => $days ), $this->db->rules[ $id ] );
			$this->assertNull( $this->db->defs[ $id ]['max_earners'] );
		}
	}

	public function test_site_first_badges_go_to_one_member_only(): void {
		$this->assertSame( array( 'type' => 'level_reached', 'level_id' => 5 ), $this->db->rules['first_champion'] );
		$this->assertSame( array( 'type' => 'point_milestone', 'points' => 10000 ), $this->db->rules['first_10k_points'] );
		$this->assertSame( array( 'type' => 'streak_days', 'days' => 100 ), $this->db->rules['first_100_day_streak'] );

		foreach ( array( 'first_champion', 'first_10k_points', 'first_100_day_streak' ) as $id ) {
			$this->assertSame( 1, $this->db->defs[ $id ]['max_earners'] );
		}
	}

	public function test_the_other_badges_have_no_earner_limit(): void {
		$this->assertNull( $this->db->defs['welcome']['max_earners'] );
	}

	public function test_the_removed_switches_are_gone_from_the_flag_list(): void {
		$this->assertArrayNotHasKey( 'tenure_badges', FeatureFlags::get_defaults() );
		$this->assertArrayNotHasKey( 'site_first_badges', FeatureFlags::get_defaults() );
	}
}
