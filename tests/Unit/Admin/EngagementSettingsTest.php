<?php
/**
 * Unit tests for the Engagement settings tier parser.
 *
 * The login-bonus tier ladder is entered as free-text "day:points" lines and
 * must normalise to the same int=>int map shape LoginBonusEngine::get_tiers()
 * consumes. These tests lock that parsing contract: valid lines in, garbage
 * dropped, sorted by day.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\SettingsPage;

/**
 * @coversDefaultClass \WBGam\Admin\SettingsPage
 */
class EngagementSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// absint() is used inside the parser.
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Call the private static parser via reflection.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array<int,int>
	 */
	private function parse( string $raw ): array {
		$m = new \ReflectionMethod( SettingsPage::class, 'parse_login_bonus_tiers' );
		$m->setAccessible( true );
		return $m->invoke( null, $raw );
	}

	/**
	 * @test
	 * @covers ::parse_login_bonus_tiers
	 */
	public function parses_valid_lines_into_day_points_map(): void {
		$this->assertSame(
			array( 1 => 10, 3 => 20, 7 => 50 ),
			$this->parse( "1:10\n3:20\n7:50" )
		);
	}

	/**
	 * @test
	 * @covers ::parse_login_bonus_tiers
	 */
	public function sorts_by_day_regardless_of_input_order(): void {
		$this->assertSame(
			array( 2 => 15, 5 => 40, 30 => 250 ),
			$this->parse( "30:250\n2:15\n5:40" )
		);
	}

	/**
	 * @test
	 * @covers ::parse_login_bonus_tiers
	 */
	public function drops_blank_malformed_and_non_positive_lines(): void {
		// Blank, no-colon, zero-day and zero-points lines all drop. (absint
		// coerces a negative day to positive — that is WP's sanitizer behaviour,
		// not a separate rejection path, so it is not tested here.)
		$this->assertSame(
			array( 4 => 25 ),
			$this->parse( "\n  \nnotanumber\n0:99\n4:25\n7:0" )
		);
	}

	/**
	 * @test
	 * @covers ::parse_login_bonus_tiers
	 */
	public function empty_input_returns_empty_array_for_default_restore(): void {
		$this->assertSame( array(), $this->parse( "   \n\n" ) );
	}

	/**
	 * @test
	 * @covers ::parse_login_bonus_tiers
	 */
	public function tolerates_whitespace_and_crlf(): void {
		$this->assertSame(
			array( 1 => 10, 14 => 100 ),
			$this->parse( "  1 : 10 \r\n 14:100 " )
		);
	}
}
