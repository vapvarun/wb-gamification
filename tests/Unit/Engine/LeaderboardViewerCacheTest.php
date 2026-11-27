<?php
/**
 * A cached board answer must never be served to a viewer it was not computed for.
 *
 * A scoped board resolves its members through `wb_gam_leaderboard_scope_user_ids`, and a host may
 * answer per viewer: BuddyNext returns nobody to a viewer who may not see a private space's roster.
 * The engine cached the total, the rows and the rank on the board arguments alone, so with a
 * persistent object cache the first viewer's answer was served to everyone after them: a non-member
 * saw 0 (right), then a member saw 0 too (wrong, 5). Card 10549329578.
 *
 * The object cache is modelled as one array shared by both calls, which is what a persistent cache
 * is within a request and across requests.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardEngine;

/**
 * Non-member first, then member, same request, same cache.
 */
class LeaderboardViewerCacheTest extends TestCase {

	/**
	 * The modelled object cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = array();

	/**
	 * Members the scope filter resolves to for the current viewer.
	 *
	 * @var int[]
	 */
	private array $viewer_scope = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cache = array( 'wb_gamification:point_types_default' => 'points' );

		Functions\when( 'wp_cache_get' )->alias(
			fn( $key, $group = '' ) => $this->cache[ $group . ':' . $key ] ?? false
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group = '' ) {
				$this->cache[ $group . ':' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get_last_changed' )->justReturn( '1' );
		Functions\when( 'get_option' )->alias( static fn( $key, $default_value = false ) => $default_value );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_list_pluck' )->alias( static fn( $list, $field ) => array_column( $list, $field ) );
		Functions\when( 'cache_users' )->justReturn( null );
		Functions\when( 'get_avatar_url' )->justReturn( '' );

		Filters\expectApplied( 'wb_gam_leaderboard_scope_user_ids' )->zeroOrMoreTimes()->andReturnUsing(
			fn() => $this->viewer_scope
		);

		$GLOBALS['wpdb'] = new ViewerCacheFakeWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * The card's own repro: the total.
	 */
	public function test_a_member_after_a_non_member_gets_the_members_total(): void {
		$this->viewer_scope = array();
		$this->assertSame( 0, LeaderboardEngine::get_total( 'all', 'bn_space', 7 ), 'Non-member sees nobody.' );

		$this->viewer_scope = array( 1, 2, 3, 4, 5 );
		$this->assertSame( 5, LeaderboardEngine::get_total( 'all', 'bn_space', 7 ), 'Member was served the non-member total.' );
	}

	/**
	 * The same cache path for the rows.
	 */
	public function test_a_member_after_a_non_member_gets_the_members_rows(): void {
		$this->viewer_scope = array();
		$this->assertSame( array(), LeaderboardEngine::get_leaderboard( 'all', 10, 'bn_space', 7 ) );

		$this->viewer_scope = array( 1, 2, 3, 4, 5 );
		$this->assertCount( 5, LeaderboardEngine::get_leaderboard( 'all', 10, 'bn_space', 7 ), 'Member was served the non-member rows.' );
	}

	/**
	 * The same cache path for a rank.
	 */
	public function test_a_member_after_a_non_member_gets_a_rank(): void {
		$this->viewer_scope = array();
		$this->assertSame( 0, LeaderboardEngine::get_user_rank( 3, 'all', 'bn_space', 7 )['rank'] );

		$this->viewer_scope = array( 1, 2, 3, 4, 5 );
		$this->assertGreaterThan( 0, LeaderboardEngine::get_user_rank( 3, 'all', 'bn_space', 7 )['rank'], 'Member was served the non-member rank.' );
	}

	/**
	 * The cache still works: the same viewer asking twice is one query, not two.
	 */
	public function test_the_same_viewer_still_hits_the_cache(): void {
		$this->viewer_scope = array( 1, 2, 3, 4, 5 );
		LeaderboardEngine::get_total( 'all', 'bn_space', 7 );
		$queries = $GLOBALS['wpdb']->queries;

		LeaderboardEngine::get_total( 'all', 'bn_space', 7 );
		$this->assertSame( $queries, $GLOBALS['wpdb']->queries );
	}
}

/**
 * A board of five members, each with 10 points: counts return 5, rows return the scoped ids.
 */
class ViewerCacheFakeWpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $users = 'wp_users';

	/** @var string */
	public $usermeta = 'wp_usermeta';

	/** @var int */
	public $queries = 0;

	/**
	 * @return string
	 */
	public function get_blog_prefix() {
		return 'wp_';
	}

	/**
	 * @param string $sql  SQL.
	 * @param mixed  ...$args Binds.
	 * @return string
	 */
	public function prepare( $sql, ...$args ) {
		return (string) $sql;
	}

	/**
	 * @param string $sql SQL.
	 * @return string
	 */
	public function get_var( $sql ) {
		++$this->queries;
		return false !== strpos( $sql, 'MIN(' ) ? null : ( false !== strpos( $sql, 'COUNT(' ) ? '5' : '10' );
	}

	/**
	 * @param string $sql SQL.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( $sql ) {
		++$this->queries;
		$rows = array();
		foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
			$rows[] = false !== strpos( $sql, 'SELECT ID, display_name' )
				? array(
					'ID'           => $id,
					'display_name' => 'M' . $id,
				)
				: array(
					'user_id'      => $id,
					'total_points' => 10,
				);
		}
		return $rows;
	}
}
