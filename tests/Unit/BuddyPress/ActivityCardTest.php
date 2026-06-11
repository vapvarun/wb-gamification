<?php
/**
 * Unit tests for the activity-card action headline contract.
 *
 * Locks the de-duplication fix (1.5.5): the BuddyPress action headline must be
 * a GENERIC verb ("X earned a badge") so it never repeats the specific name the
 * content card already shows. A regression that puts the badge / level /
 * challenge / recipient name back into the headline shows every gamification
 * activity's text twice — exactly the bug QA flagged.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\BuddyPress;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\BuddyPress\Stream\ActivityCard;

/**
 * @coversDefaultClass \WBGam\BuddyPress\Stream\ActivityCard
 */
class ActivityCardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// __() passthrough — return the template untranslated.
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Each type maps to its generic verb and interpolates the actor link.
	 *
	 * @covers ::action_line
	 * @dataProvider provideTypes
	 *
	 * @param string $type     Card type.
	 * @param string $expected Expected generic headline.
	 */
	public function test_action_line_is_generic_per_type( string $type, string $expected ): void {
		$this->assertSame( $expected, ActivityCard::action_line( '<a href="#">Sam</a>', $type ) );
	}

	/**
	 * Data provider: type => expected generic headline (actor = "<a href="#">Sam</a>").
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function provideTypes(): array {
		return array(
			'badge'     => array( 'badge', '<a href="#">Sam</a> earned a badge' ),
			'level'     => array( 'level', '<a href="#">Sam</a> reached a new level' ),
			'kudos'     => array( 'kudos', '<a href="#">Sam</a> gave kudos' ),
			'challenge' => array( 'challenge', '<a href="#">Sam</a> completed a challenge' ),
		);
	}

	/**
	 * An unknown type falls back to the badge verb rather than erroring.
	 *
	 * @covers ::action_line
	 */
	public function test_unknown_type_falls_back_to_badge(): void {
		$this->assertSame( 'Sam earned a badge', ActivityCard::action_line( 'Sam', 'mystery' ) );
	}

	/**
	 * The regression lock: a generic headline must NOT contain the specific
	 * name the content card carries (no badge/recipient name, no <strong>).
	 *
	 * @covers ::action_line
	 */
	public function test_headline_never_repeats_specific_name(): void {
		$specifics = array( 'Active Member', 'Five Hundred Strong', 'Andre Dubus' );
		foreach ( array( 'badge', 'level', 'kudos', 'challenge' ) as $type ) {
			$headline = ActivityCard::action_line( '<a href="#">Sam</a>', $type );
			$this->assertStringNotContainsString( '<strong>', $headline, "Headline for {$type} must not wrap a specific name in <strong>." );
			foreach ( $specifics as $name ) {
				$this->assertStringNotContainsString( $name, $headline, "Headline for {$type} must not contain the specific name '{$name}'." );
			}
		}
	}
}
