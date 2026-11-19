<?php
/**
 * A member returning after several level-ups sees one card: the highest level reached.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\NotificationBridge;

#[CoversClass( \WBGam\Engine\NotificationBridge::class )]
#[CoversMethod( \WBGam\Engine\NotificationBridge::class, 'latest_moments' )]
class NotificationLatestMomentsTest extends TestCase {

	#[Test]
	public function older_level_ups_and_shorter_streaks_are_dropped(): void {
		$events = array(
			array( 'type' => 'level_up', 'title' => 'Member', '_id' => 1 ),
			array( 'type' => 'points', '_id' => 2 ),
			array( 'type' => 'streak_milestone', 'days' => 30, '_id' => 3 ),
			array( 'type' => 'level_up', 'title' => 'Contributor', '_id' => 4 ),
			array( 'type' => 'cohort_promotion', '_id' => 5 ),
			array( 'type' => 'streak_milestone', 'days' => 7, '_id' => 6 ),
			array( 'type' => 'level_up', 'title' => 'Expert', '_id' => 7 ),
		);

		$ids = array_column( NotificationBridge::latest_moments( $events ), '_id' );

		// Points and the league promotion pass through; one level card (Expert), one streak card (30 days).
		$this->assertSame( array( 2, 3, 5, 7 ), $ids );
	}

	#[Test]
	public function a_single_moment_is_left_alone(): void {
		$events = array( array( 'type' => 'level_up', '_id' => 9 ) );
		$this->assertSame( $events, NotificationBridge::latest_moments( $events ) );
	}
}
