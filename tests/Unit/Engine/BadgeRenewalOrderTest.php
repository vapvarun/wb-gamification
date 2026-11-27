<?php
/**
 * A lapsed credential must be cleared before the award insert, or re-earning it does nothing.
 *
 * wb_gam_user_badges is UNIQUE(user_id, badge_id) and an expired row stays. has_badge() does not
 * count it, so award_badge() reached INSERT IGNORE, which was ignored: no renewal and no
 * wb_gam_badge_awarded. Source-level because a full award needs the whole WordPress stack.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BadgeRenewalOrderTest extends TestCase {

	#[Test]
	public function a_lapsed_row_is_cleared_before_the_award_insert(): void {
		$src    = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/BadgeEngine.php' );
		$start  = strpos( $src, 'public static function award_badge(' );
		$body   = substr( $src, (int) $start );
		$delete = strpos( $body, 'DELETE FROM `{$badges_table}`' );
		$insert = strpos( $body, 'INSERT IGNORE INTO' );

		$this->assertNotFalse( $delete, 'award_badge() must clear a lapsed row' );
		$this->assertLessThan( $insert, $delete, 'the lapsed row is cleared before the insert' );
		$this->assertStringContainsString( 'expires_at IS NOT NULL AND expires_at <= %s', $body, 'only a lapsed row is cleared' );
	}
}
