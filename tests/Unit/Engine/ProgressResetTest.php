<?php
/**
 * Safety tests for member-progress reset (1.5.3).
 *
 * The reset truncates a fixed allowlist of progress/derived tables. This test
 * guards against the dangerous mistake of ever adding a CONFIGURATION or
 * DEFINITION table to that list - doing so would silently destroy a site's
 * setup on reset.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\ProgressReset;

/**
 * @coversDefaultClass \WBGam\Engine\ProgressReset
 */
class ProgressResetTest extends TestCase {

	/**
	 * @return string[]
	 */
	private function progressTables(): array {
		$ref = new ReflectionClass( ProgressReset::class );
		return (array) $ref->getConstant( 'PROGRESS_TABLES' );
	}

	/**
	 * @test
	 */
	public function config_and_definition_tables_are_never_wiped(): void {
		$protected = array(
			'wb_gam_badge_defs',
			'wb_gam_levels',
			'wb_gam_rules',
			'wb_gam_challenges',
			'wb_gam_community_challenges',
			'wb_gam_point_types',
			'wb_gam_point_type_conversions',
			'wb_gam_redemption_items',
			'wb_gam_member_prefs',
			'wb_gam_webhooks',
			'wb_gam_api_keys',
		);
		$wiped = $this->progressTables();
		foreach ( $protected as $table ) {
			$this->assertNotContains( $table, $wiped, "{$table} is configuration and must never be in the reset list." );
		}
	}

	/**
	 * @test
	 */
	public function the_core_progress_tables_are_wiped(): void {
		$wiped = $this->progressTables();
		foreach ( array( 'wb_gam_points', 'wb_gam_events', 'wb_gam_user_badges', 'wb_gam_streaks', 'wb_gam_user_totals' ) as $table ) {
			$this->assertContains( $table, $wiped );
		}
	}
}
