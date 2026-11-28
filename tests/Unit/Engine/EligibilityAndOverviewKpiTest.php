<?php
/**
 * Four QA cards, one file: leaderboard snapshot standing eligibility, the doctor oracle's host
 * rule, Overview KPI cache invalidation, and the uncapped hand-award total.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\AnalyticsDashboard;
use WBGam\CLI\DoctorCommand;
use WBGam\Engine\LeaderboardEngine;

/**
 * Records every prepared query and answers from canned values.
 */
class EligibilityAndOverviewKpiFakeWpdb {

	public string $prefix   = 'wp_';
	public string $users    = 'wp_users';
	public string $usermeta = 'wp_usermeta';

	/**
	 * Every prepared SQL string, interpolated.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Row get_row() returns (null = the member is not in the eligible snapshot).
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $row = null;

	public function get_blog_prefix( $blog_id = null ) {
		unset( $blog_id );
		return $this->prefix;
	}

	public function esc_like( $s ) {
		return addcslashes( (string) $s, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$query = preg_replace( '/%[ds]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $query, 1 );
		}
		$this->queries[] = $query;
		return $query;
	}

	public function get_var( $query ) {
		if ( false !== strpos( $query, 'MAX(updated_at)' ) ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		if ( false !== strpos( $query, 'action_id IN' ) ) {
			return '37';
		}
		return '0';
	}

	public function get_row( $query, $output = OBJECT ) {
		unset( $query, $output );
		return $this->row;
	}

	public function get_results( $query, $output = OBJECT ) {
		unset( $output );
		return array();
	}
}

/**
 * The four cards.
 */
class EligibilityAndOverviewKpiTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var EligibilityAndOverviewKpiFakeWpdb
	 */
	private EligibilityAndOverviewKpiFakeWpdb $db;

	/**
	 * Object cache + transients, as plain arrays.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array( 'wb_gamification:point_types_default' => 'points' );
		$this->db    = new EligibilityAndOverviewKpiFakeWpdb();
		$GLOBALS['wpdb'] = $this->db;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) {
				return 'timezone_string' === $key ? 'UTC' : $default_value;
			}
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'current_time' )->alias( static fn() => time() );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_cache_get' )->alias( fn( $k, $g = '' ) => $this->store[ $g . ':' . $k ] ?? false );
		Functions\when( 'wp_cache_set' )->alias(
			function ( $k, $v, $g = '' ) {
				$this->store[ $g . ':' . $k ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $k, $g = '' ) {
				unset( $this->store[ $g . ':' . $k ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->store[ 'transient:' . $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->store[ 'transient:' . $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $k ) {
				unset( $this->store[ 'transient:' . $k ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Card 10552261714: the private rank strip applies the board's eligibility to BOTH snapshot reads.
	 */
	public function test_snapshot_standing_applies_the_board_eligibility_to_both_queries(): void {
		$this->db->row = array( 'total_points' => '50', 'rank' => '3' );
		Filters\expectApplied( 'wb_gam_leaderboard_max_snapshot_age' )->andReturn( 600 );
		Filters\expectApplied( 'wb_gam_leaderboard_exclusion_sql' )->andReturnUsing(
			static fn( $extra, $alias ) => array( ' AND NOT EXISTS ( SELECT 1 FROM wp_suspended s WHERE s.user_id = ' . $alias . '.user_id )', array() )
		);

		$m = new \ReflectionMethod( LeaderboardEngine::class, 'snapshot_standing' );
		$m->invoke( null, 7, 'week', 'points' );

		$reads = array_values( array_filter( $this->db->queries, static fn( $q ) => false !== strpos( $q, 'wb_gam_leaderboard_cache' ) ) );
		$this->assertCount( 2, $reads, 'standing row + next-up threshold' );
		foreach ( $reads as $q ) {
			$this->assertStringContainsString( 'leaderboard_opt_out = 1', $q, $q );
			$this->assertStringContainsString( 'wp_suspended s', $q, 'host rule must apply: ' . $q );
		}
	}

	/**
	 * An excluded member has no eligible snapshot row, so the strip falls through to the live path.
	 */
	public function test_snapshot_standing_is_null_for_an_excluded_member(): void {
		$this->db->row = null;
		Filters\expectApplied( 'wb_gam_leaderboard_max_snapshot_age' )->andReturn( 600 );

		$m = new \ReflectionMethod( LeaderboardEngine::class, 'snapshot_standing' );
		$this->assertNull( $m->invoke( null, 7, 'week', 'points' ) );
	}

	/**
	 * Card 10552261472: the doctor's independent count honours the host filter.
	 */
	public function test_doctor_oracle_honours_the_host_exclusion_filter(): void {
		Filters\expectApplied( 'wb_gam_leaderboard_exclusion_sql' )->andReturnUsing(
			static fn( $extra, $alias ) => array( ' AND NOT EXISTS ( SELECT 1 FROM wp_suspended s WHERE s.user_id = ' . $alias . '.user_id AND s.level >= %d )', array( 2 ) )
		);

		$m = new \ReflectionMethod( DoctorCommand::class, 'count_eligible_members' );
		$m->invoke( null, 'all' );

		$sql = end( $this->db->queries );
		$this->assertStringContainsString( 'wp_suspended s WHERE s.user_id = p.user_id AND s.level >= 2', $sql );
	}

	/**
	 * A malformed host fragment is ignored by the oracle exactly as the engine ignores it.
	 */
	public function test_doctor_oracle_drops_a_malformed_host_fragment(): void {
		Filters\expectApplied( 'wb_gam_leaderboard_exclusion_sql' )->andReturn( array( 'OR 1=1', array() ) );

		$m = new \ReflectionMethod( DoctorCommand::class, 'count_eligible_members' );
		$m->invoke( null, 'all' );

		$this->assertStringNotContainsString( 'OR 1=1', (string) end( $this->db->queries ) );
	}

	/**
	 * Card 10551809039: flush_stats() drops every cached window, memory and transient.
	 */
	public function test_flush_stats_drops_every_cached_window(): void {
		foreach ( array( 7, 30, 90 ) as $d ) {
			$this->store[ "wb_gamification:wb_gam_analytics_{$d}" ] = array( 'points_total' => 1 );
			$this->store[ "transient:wb_gam_analytics_{$d}" ]       = array( 'points_total' => 1 );
		}

		AnalyticsDashboard::flush_stats();

		foreach ( array( 7, 30, 90 ) as $d ) {
			$this->assertArrayNotHasKey( "wb_gamification:wb_gam_analytics_{$d}", $this->store );
			$this->assertArrayNotHasKey( "transient:wb_gam_analytics_{$d}", $this->store );
		}
	}

	/**
	 * A member's own award is the hot path: it must not pay for the cache deletes. Only a user who
	 * can read the Overview (a hand award, a deduction, a revoke) flushes it.
	 */
	public function test_only_an_admin_made_change_flushes_the_overview(): void {
		$this->store['wb_gamification:wb_gam_analytics_30'] = array( 'points_total' => 1 );

		Functions\when( 'current_user_can' )->justReturn( false );
		AnalyticsDashboard::flush_for_admin();
		$this->assertArrayHasKey( 'wb_gamification:wb_gam_analytics_30', $this->store, 'a member award must not flush' );

		Functions\when( 'current_user_can' )->justReturn( true );
		AnalyticsDashboard::flush_for_admin();
		$this->assertArrayNotHasKey( 'wb_gamification:wb_gam_analytics_30', $this->store );
	}

	/**
	 * The flush is wired to the one action every ledger change fires, outside wp-admin, and a
	 * revoke reaches that action.
	 */
	public function test_flush_is_wired_to_the_single_invalidation_point(): void {
		$root = dirname( __DIR__, 3 );
		$boot = (string) file_get_contents( $root . '/wb-gamification.php' );
		$eng  = (string) file_get_contents( $root . '/src/Engine/LeaderboardEngine.php' );

		$this->assertMatchesRegularExpression( "/add_action\(\s*'wb_gam_leaderboard_cache_invalidated',\s*array\(\s*AnalyticsDashboard::class,\s*'flush_for_admin'/", $boot );
		$this->assertMatchesRegularExpression( "/add_action\(\s*'wb_gam_points_revoked',\s*array\(\s*__CLASS__,\s*'invalidate_cache'/", $eng );
	}

	/**
	 * Card 10551809525: the hand-award total is its own aggregate over every hand-award id, so it
	 * does not depend on the top-ten list (which here holds no hand award at all).
	 */
	public function test_hand_award_total_is_an_independent_bound_aggregate(): void {
		$stats = AnalyticsDashboard::get_stats( 30 );

		$this->assertSame( 37, $stats['manual_points'] );
		$this->assertSame( array(), $stats['top_actions'] );

		$sql = '';
		foreach ( $this->db->queries as $q ) {
			if ( false !== strpos( $q, 'action_id IN' ) ) {
				$sql = $q;
			}
		}
		$this->assertStringContainsString( "'manual_award', 'manual_admin', 'manual_admin_deduct', 'manual'", $sql );
		$this->assertStringContainsString( 'is_spend = 0', $sql );
		$this->assertStringNotContainsString( 'LIMIT', $sql );
	}
}
