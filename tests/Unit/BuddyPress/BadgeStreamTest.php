<?php
/**
 * Badge activity grouping: the group key is the member's SITE day, not the UTC day.
 *
 * Owner decision Q2 (1.6.8): other badges a member earns on the same site day join that day's
 * post. The window comes from Clock (site calendar, stored UTC), so a badge at 23:50 and one at
 * 00:10 site time land in two posts even where both instants fall on the same UTC date.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\BuddyPress;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\BuddyPress\Stream\ActivityCard;
use WBGam\BuddyPress\Stream\BadgeStream;

#[CoversClass( \WBGam\BuddyPress\Stream\BadgeStream::class )]
#[CoversMethod( \WBGam\BuddyPress\Stream\BadgeStream::class, 'site_day_window' )]
#[CoversMethod( \WBGam\BuddyPress\Stream\BadgeStream::class, 'day_date_query' )]
#[CoversMethod( \WBGam\BuddyPress\Stream\ActivityCard::class, 'allowed_tags' )]
#[CoversMethod( \WBGam\BuddyPress\Stream\ActivityCard::class, 'has_card' )]
class BadgeStreamTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		ActivityCard::clear_mark( '' );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Pin "now" (UTC) and the site zone.
	 *
	 * @param string $utc  UTC moment.
	 * @param string $zone Site timezone.
	 */
	private function at( string $utc, string $zone ): void {
		$ts = strtotime( $utc . ' UTC' );
		Functions\when( 'current_time' )->alias( static fn( $type, $gmt = 0 ) => $ts );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( $zone ) );
	}

	/**
	 * Whether a UTC moment falls inside a [start, end) window.
	 *
	 * @param string                   $utc    Moment.
	 * @param array{0:string,1:string} $window Window.
	 */
	private function inside( string $utc, array $window ): bool {
		return $utc >= $window[0] && $utc < $window[1];
	}

	#[Test]
	public function badges_either_side_of_site_midnight_do_not_group(): void {
		// Kolkata: 18:20 UTC is 23:50 on the 13th, 18:40 UTC is 00:10 on the 14th. Same UTC date.
		$this->at( '2026-07-13 18:20:00', 'Asia/Kolkata' );
		$late = BadgeStream::site_day_window();
		$this->assertSame( array( '2026-07-12 18:30:00', '2026-07-13 18:30:00' ), $late );

		$this->at( '2026-07-13 18:40:00', 'Asia/Kolkata' );
		$early = BadgeStream::site_day_window();
		$this->assertSame( array( '2026-07-13 18:30:00', '2026-07-14 18:30:00' ), $early );

		$this->assertFalse( $this->inside( '2026-07-13 18:20:00', $early ), 'The 23:50 post must not take the 00:10 badge.' );
	}

	#[Test]
	public function badges_on_one_site_day_group_across_a_utc_date_change(): void {
		// Los Angeles: 06:00 UTC on the 14th is 23:00 on the 13th; 20:00 UTC on the 13th is 13:00 the 13th.
		$this->at( '2026-07-14 06:00:00', 'America/Los_Angeles' );
		$window = BadgeStream::site_day_window();
		$this->assertSame( array( '2026-07-13 07:00:00', '2026-07-14 07:00:00' ), $window );
		$this->assertTrue( $this->inside( '2026-07-13 20:00:00', $window ), 'Same site day, different UTC date: one post.' );
	}

	#[Test]
	public function day_query_is_one_bp_accepts_and_half_open(): void {
		$query = BadgeStream::day_date_query( '2026-07-12 18:30:00', '2026-07-13 18:30:00' );

		// BuddyPress targets a.date_recorded itself. A clause-level 'column' that WP_Date_Query does
		// not know falls back to wp_posts.post_date: an SQL error on every award (BuddyPress 14.5.4).
		foreach ( $query as $clause ) {
			$this->assertArrayNotHasKey( 'column', $clause );
		}
		// Start inclusive, next day's start exclusive: a badge at exactly midnight opens a new day.
		$this->assertSame(
			array(
				array(
					'after'     => '2026-07-12 18:30:00',
					'inclusive' => true,
				),
				array(
					'before'    => '2026-07-13 18:30:00',
					'inclusive' => false,
				),
			),
			$query
		);
	}

	#[Test]
	public function card_tags_are_allowed_only_for_our_component(): void {
		$base = array(
			'div'  => array(),
			'span' => array(),
		);

		// A member's own post: no class on div survives.
		ActivityCard::mark_display( '<div class="x">hi</div>', (object) array( 'component' => 'activity' ) );
		$this->assertSame( $base, ActivityCard::allowed_tags( $base ) );

		// Our card: the wrapper keeps its class.
		ActivityCard::mark_display( '', (object) array( 'component' => ActivityCard::COMPONENT ) );
		$tags = ActivityCard::allowed_tags( $base );
		$this->assertTrue( $tags['div']['class'] );
		$this->assertTrue( $tags['img']['src'] );

		// After BuddyPress has filtered it, the next post is a stranger again.
		ActivityCard::clear_mark( '' );
		$this->assertSame( $base, ActivityCard::allowed_tags( $base ) );
	}

	#[Test]
	public function stripped_cards_are_detected_for_repair(): void {
		$good = '<p class="wb-gam-activity-card-wrap"><span class="wb-gam-activity-card wb-gam-activity-card--badge"><img class="wb-gam-activity-card__icon" src="x" alt="" /><span class="wb-gam-activity-card__body"><span>a</span></span></span></p>';
		$this->assertTrue( ActivityCard::has_card( $good ) );
		// The 1.6.8 development <div> card is redrawn too.
		$this->assertFalse( ActivityCard::has_card( '<div class="wb-gam-activity-card wb-gam-activity-card--badge"><img class="wb-gam-activity-card__icon" src="x" alt="" /><div class="wb-gam-activity-card__body"><span>a</span></div></div>' ) );
		// BuddyBoss kept the img class but dropped both divs.
		$this->assertFalse( ActivityCard::has_card( '<img class="wb-gam-activity-card__icon" src="x" /><span class="wb-gam-activity-card__eyebrow">Badge earned</span>' ) );
		// BuddyPress dropped every class.
		$this->assertFalse( ActivityCard::has_card( '<img src="x" /><span>Badge earned</span>' ) );
		$this->assertFalse( ActivityCard::has_card( '' ) );
	}
}
