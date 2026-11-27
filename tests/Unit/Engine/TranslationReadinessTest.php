<?php
/**
 * Member-facing text that used to ship as bare English.
 *
 * Each case calls the code with `__` / `_n` stubbed to tag whatever they translate, so a literal
 * that is not wrapped for translation comes back untagged and fails.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\CohortEngine;
use WBGam\Engine\NudgeEngine;

#[CoversClass( NudgeEngine::class )]
#[CoversClass( CohortEngine::class )]
class TranslationReadinessTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->alias( static fn( $text ) => 'T:' . $text );
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 'T:' . ( 1 === (int) $n ? $one : $many ) );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Call a private static method.
	 *
	 * @param class-string $class  Class.
	 * @param string       $method Method.
	 * @param array        $args   Arguments.
	 * @return mixed
	 */
	private function call( string $class, string $method, array $args = array() ) {
		$m = new \ReflectionMethod( $class, $method );
		$m->setAccessible( true );
		return $m->invoke( null, ...$args );
	}

	/**
	 * Every hub nudge sentence is translated.
	 *
	 * @return void
	 */
	public function test_hub_nudges_are_translated(): void {
		$none = $this->call( NudgeEngine::class, 'check_no_challenges', array( array() ) );
		$this->assertSame( 'T:Try a challenge to earn bonus points', $none['message'] );

		$done = $this->call( NudgeEngine::class, 'check_unclaimed_reward', array( array( array( 'completed' => true, 'title' => 'Ten posts', 'bonus_points' => 5 ) ) ) );
		$this->assertSame( 'T:You completed Ten posts! +5 bonus points earned', $done['message'] );

		$near = $this->call( NudgeEngine::class, 'check_challenge_in_progress', array( array( array( 'target' => 10, 'progress' => 8, 'title' => 'Ten posts' ) ) ) );
		$this->assertSame( 'T:Ten posts is 80% done - 2 more to complete it', $near['message'] );
	}

	/**
	 * The shipped cohort tier names are translated.
	 *
	 * @return void
	 */
	public function test_cohort_tier_names_are_translated(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( 'T:Bronze', CohortEngine::default_tier_name( 0 ) );
		$this->assertSame( 'T:Obsidian', CohortEngine::get_tier_name( 4 ) );
	}

	/**
	 * Strings that cannot be reached without a database or a shop: pinned in the source so a literal
	 * cannot come back unwrapped.
	 *
	 * @return void
	 */
	public function test_known_literals_stay_wrapped(): void {
		$root  = dirname( __DIR__, 3 ) . '/';
		$wraps = array(
			'src/API/SSEController.php'         => "__( 'Server-Sent Events transport is not enabled on this install.', 'wb-gamification' )",
			'src/API/IntelligenceController.php' => "__( 'No intelligence signals available for this user yet.', 'wb-gamification' )",
			'src/Engine/ImportRunner.php'       => "__( 'Unknown import phase \"%s\".', 'wb-gamification' )",
			'src/Engine/ImportUndo.php'         => "esc_html__( 'Removing a page of imported events was rolled back.', 'wb-gamification' )",
			'src/Engine/RedemptionEngine.php'   => "__( 'Redeemed via WB Gamification - %s', 'wb-gamification' )",
			'src/Admin/ApiKeysPage.php'         => "esc_attr__( 'e.g. MediaVerse Production', 'wb-gamification' )",
			'src/Admin/BadgeAdminPage.php'      => "esc_attr__( 'e.g. first_post', 'wb-gamification' )",
			'src/Admin/AnalyticsDashboard.php'  => "__( '%1\$s: %2\$s pts', 'wb-gamification' )",
			'src/Admin/IntegrationsTab.php'     => "__( 'Installing...', 'wb-gamification' )",
			'src/Blocks/streak/render.php'      => "_x( 'M j', 'short date format: month and day', 'wb-gamification' )",
			'src/Engine/Installer.php'          => "__( 'Newcomer', 'wb-gamification' )",
		);
		foreach ( $wraps as $file => $needle ) {
			$this->assertStringContainsString( $needle, (string) file_get_contents( $root . $file ), $file );
		}

		$this->assertStringNotContainsString( "wp_date( 'M j, Y", (string) file_get_contents( $root . 'src/Admin/RedemptionStorePage.php' ), 'Use the site date and time format.' );
	}
}
