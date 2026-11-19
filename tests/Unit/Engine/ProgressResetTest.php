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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\ProgressReset;

#[CoversClass( \WBGam\Engine\ProgressReset::class )]
class ProgressResetTest extends TestCase {

	/**
	 * @return string[]
	 */
	private function progressTables(): array {
		$ref = new ReflectionClass( ProgressReset::class );
		return (array) $ref->getConstant( 'PROGRESS_TABLES' );
	}

	#[Test]
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

	#[Test]
	public function the_core_progress_tables_are_wiped(): void {
		$wiped = $this->progressTables();
		foreach ( array( 'wb_gam_points', 'wb_gam_events', 'wb_gam_user_badges', 'wb_gam_streaks', 'wb_gam_user_totals' ) as $table ) {
			$this->assertContains( $table, $wiped );
		}
	}

	#[Test]
	public function every_personal_record_key_is_reset(): void {
		$ref      = new ReflectionClass( ProgressReset::class );
		$literals = (array) $ref->getConstant( 'PROGRESS_META' );
		$prefixes = (array) $ref->getConstant( 'PROGRESS_META_PREFIXES' );
		$keys     = array_filter(
			(array) ( new ReflectionClass( \WBGam\Engine\MemberData::class ) )->getConstant( 'USER_META_KEYS' ),
			static fn( $k ) => str_starts_with( $k, 'wb_gam_pr_' )
		);
		$this->assertCount( 6, $keys, 'best and run for day, week, month' );

		foreach ( $keys as $key ) {
			$covered = in_array( $key, $literals, true );
			foreach ( $prefixes as $prefix ) {
				$covered = $covered || str_starts_with( $key, $prefix );
			}
			$this->assertTrue( $covered, "{$key} survives a reset and would announce a stale personal record." );
		}
	}
}
