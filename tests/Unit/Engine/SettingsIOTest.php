<?php
/**
 * Tests for settings import guards (1.5.3).
 *
 * Locks the import contract: only a genuine WB Gamification export is applied,
 * only wb_gam_ keys are written, and runtime/schema keys are never imported
 * even if present in the file.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\SettingsIO;

/**
 * @coversDefaultClass \WBGam\Engine\SettingsIO
 */
class SettingsIOTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Option names that update_option() was called with this test.
	 *
	 * @var string[]
	 */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->written = array();
		Functions\when( 'update_option' )->alias(
			function ( $name ) {
				$this->written[] = $name;
				return true;
			}
		);
		// PointsEngine::flush_exclusion_cache() is called at the end of import().
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_users' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::import
	 */
	public function rejects_a_foreign_document(): void {
		$res = SettingsIO::import( array( 'plugin' => 'something-else', 'options' => array( 'wb_gam_x' => 1 ) ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 0, $res['applied'] );
		$this->assertEmpty( $this->written );
	}

	/**
	 * @test
	 * @covers ::import
	 */
	public function rejects_a_document_without_options(): void {
		$res = SettingsIO::import( array( 'plugin' => 'wb-gamification' ) );
		$this->assertFalse( $res['ok'] );
	}

	/**
	 * @test
	 * @covers ::import
	 */
	public function applies_wb_gam_settings_and_skips_runtime_plus_foreign_keys(): void {
		$res = SettingsIO::import(
			array(
				'plugin'  => 'wb-gamification',
				'options' => array(
					'wb_gam_points_bp_activity_update' => 25,   // applied
					'wb_gam_excluded_roles'            => array( 'administrator' ), // applied
					'wb_gam_db_version'                => '9.9', // skipped (runtime)
					'wb_gam_feature_point_types_v'     => 3,     // skipped (schema gate)
					'some_other_plugin_option'         => 'x',   // skipped (foreign prefix)
				),
			)
		);

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 2, $res['applied'] );
		$this->assertSame( 3, $res['skipped'] );
		$this->assertContains( 'wb_gam_points_bp_activity_update', $this->written );
		$this->assertContains( 'wb_gam_excluded_roles', $this->written );
		$this->assertNotContains( 'wb_gam_db_version', $this->written );
		$this->assertNotContains( 'wb_gam_feature_point_types_v', $this->written );
		$this->assertNotContains( 'some_other_plugin_option', $this->written );
	}
}
