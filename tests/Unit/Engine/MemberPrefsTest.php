<?php
/**
 * MemberPrefs::set() writes only what it was given, so simultaneous saves cannot undo each other.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\MemberPrefs;

/**
 * Pins the shape of the write.
 */
#[CoversClass( MemberPrefs::class )]
class MemberPrefsTest extends TestCase {

	/**
	 * Capture every statement instead of running it.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'wp_cache_set_last_changed' )->justReturn( 'x' );
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var string[] */
			public $written = array();
			public function prepare( $sql, ...$args ) {
				return $sql . ' -- ' . implode( '|', $args );
			}
			public function query( $sql ) {
				$this->written[] = $sql;
				return 1;
			}
			public function get_row() {
				return null;
			}
		};
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function saving_one_choice_touches_only_that_column(): void {
		MemberPrefs::set( 5, array( 'leaderboard_opt_out' => true ) );

		$sql = $GLOBALS['wpdb']->written[0];
		$this->assertStringContainsString( 'leaderboard_opt_out = VALUES(leaderboard_opt_out)', $sql );
		$this->assertStringNotContainsString( 'show_rank', $sql );
		$this->assertStringNotContainsString( 'notification_mode', $sql );
	}

	#[Test]
	public function nothing_valid_to_save_writes_nothing(): void {
		MemberPrefs::set( 5, array( 'notification_mode' => 'nonsense' ) );
		MemberPrefs::set( 0, array( 'show_rank' => false ) );

		$this->assertSame( array(), $GLOBALS['wpdb']->written );
	}
}
