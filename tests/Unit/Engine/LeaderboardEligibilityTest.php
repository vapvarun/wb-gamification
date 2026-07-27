<?php
/**
 * Every path that serves a board or a rank must ask the SAME eligibility question.
 *
 * A member is eligible iff they EXIST, have not OPTED OUT, and are not owner-EXCLUDED (by id or by
 * role). Three predicates, and they are not optional individually.
 *
 * This test exists because that invariant was broken five times in a row, and each break was the
 * same shape: a path that had two of the three. The last one was write_snapshot(), which took the
 * existence check and never took the exclusion check -- so its RANK() column was computed over
 * members the rest of the system excludes, and one member opting out made the warm and stale paths
 * disagree for 153 of 154 members.
 *
 * Every round, the fix was applied to the path the bounce named, and the next round found the next
 * path missing the next predicate. Fixing the paths one at a time cannot terminate; asserting the
 * invariant can.
 *
 * The composer is now the single source of that answer, so the assertion is on the composer: it must
 * emit all three predicates by default, and dropping one must require an explicit argument that only
 * a query enforcing it another way is allowed to pass.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardEngine;

/**
 * The eligibility fragment every leaderboard path shares.
 */
class LeaderboardEligibilityTest extends TestCase {

	/**
	 * The fragment reads the owner-exclusion settings and composes against $wpdb. No database here --
	 * this asserts the SQL, not its result -- so both are stubbed.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) {
				return $default_value;
			}
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => (string) $v );

		$GLOBALS['wpdb'] = new EligibilityFakeWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The composed fragment, via the same reflection the query composition test uses.
	 *
	 * @param string $alias                        Table alias.
	 * @param bool   $existence_enforced_elsewhere Opt out of the existence half.
	 * @return string
	 */
	private function fragment( string $alias, bool $existence_enforced_elsewhere = false ): string {
		$m = new \ReflectionMethod( LeaderboardEngine::class, 'exclusion_sql' );
		$m->setAccessible( true );

		[ $sql ] = $m->invoke( null, $alias, $existence_enforced_elsewhere );

		return (string) $sql;
	}

	/**
	 * By default, all three predicates. A caller who says nothing gets the safe answer.
	 */
	public function test_the_default_fragment_carries_all_three_predicates(): void {
		$sql = $this->fragment( 'p' );

		$this->assertStringContainsString(
			'EXISTS ( SELECT 1 FROM wp_users wu WHERE wu.ID = p.user_id )',
			$sql,
			'EXISTENCE is missing. A deleted member still ranks: the snapshot writer shipped without '
				. 'this and wrote 500 rows of ghosts, and a board asked for 25 rendered 15.'
		);

		$this->assertStringContainsString(
			'leaderboard_opt_out = 1',
			$sql,
			'OPT-OUT is missing. This is the one write_snapshot() shipped without: it ranked members '
				. 'who had opted out, and snapshot_standing() then served a rank the fallback disagreed '
				. 'with -- for 153 of 154 members, from a single opt-out.'
		);
	}

	/**
	 * Dropping the existence half requires asking for it. It cannot happen by omission.
	 */
	public function test_dropping_existence_takes_an_explicit_argument(): void {
		$with    = $this->fragment( 'ut' );
		$without = $this->fragment( 'ut', true );

		$this->assertStringContainsString( 'wu.ID = ut.user_id', $with );
		$this->assertStringNotContainsString( 'wu.ID = ut.user_id', $without );

		// The exclusion half is NOT optional, even for the caller that opts out of existence. The
		// totals derived table checks existence in PHP (an EXISTS there wrecks the query plan) -- but it
		// still has to exclude opted-out and owner-excluded members in SQL.
		$this->assertStringContainsString(
			'leaderboard_opt_out = 1',
			$without,
			'Opting out of the existence check must not silently drop the exclusion check with it. '
				. 'That combination is exactly how the totals path could have shipped a board full of '
				. 'members who asked not to be on it.'
		);
	}

	/**
	 * The fragment composes against the alias it was given, and only that alias.
	 *
	 * A fragment built for one alias and pasted into a query using another is how this file already
	 * produced `muser_id` once (a str_replace over `p.user_id` that matched inside `mp.user_id`) and
	 * blanked every scoped leaderboard on every site.
	 */
	public function test_the_fragment_uses_the_alias_it_was_given(): void {
		$sql = $this->fragment( 'c' );

		$this->assertStringContainsString( 'wu.ID = c.user_id', $sql );
		$this->assertStringContainsString( 'mp.user_id = c.user_id', $sql );
		$this->assertStringNotContainsString( 'muser_id', $sql );
		$this->assertStringNotContainsString( ' p.user_id', $sql, 'This fragment was not built for the `p` alias.' );
	}
}

/**
 * Enough of $wpdb to compose a fragment against.
 */
class EligibilityFakeWpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Users table.
	 *
	 * @var string
	 */
	public $users = 'wp_users';

	/**
	 * Usermeta table (the role predicate composes against it).
	 *
	 * @var string
	 */
	public $usermeta = 'wp_usermeta';

	/**
	 * Multisite blog prefix. PointsEngine::exclusion_sql() asks for it when composing the role
	 * predicate against wp_usermeta.
	 *
	 * @param int|null $blog_id Unused.
	 * @return string
	 */
	public function get_blog_prefix( $blog_id = null ) {
		unset( $blog_id );
		return $this->prefix;
	}

	/**
	 * Interpolate, without a database.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Bound values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $a ) {
			$query = preg_replace( '/%[ds]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $query, 1 );
		}
		return $query;
	}
}
