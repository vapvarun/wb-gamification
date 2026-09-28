<?php
/**
 * Leaderboard keyset paging: the cursor, the rank state, and the composed query.
 *
 * No database. The cursor is the contract between a page and the next one, so it is tested as a
 * contract: what it accepts, what it refuses, and that ranks stay absolute across a page boundary
 * (ties share a rank, and the next distinct score skips).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardEngine;

#[CoversClass( LeaderboardEngine::class )]
class LeaderboardPagingTest extends TestCase {

	private const BOARD = 'abcd1234';

	/**
	 * A cursor token, built the way the engine builds one (URL-safe base64 of the JSON).
	 *
	 * @param array<string, mixed> $override Fields to override.
	 * @return string
	 */
	private function token( array $override = array() ): string {
		$data = array_merge(
			array(
				'v' => 1,
				'b' => self::BOARD,
				'p' => 90,
				'u' => 7,
				'n' => 20,
				'r' => 18,
			),
			$override
		);
		return rtrim( strtr( base64_encode( json_encode( $data ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @return void
	 */
	public function test_a_valid_cursor_decodes_to_its_position(): void {
		$this->assertSame(
			array(
				'p' => 90,
				'u' => 7,
				'n' => 20,
				'r' => 18,
			),
			LeaderboardEngine::decode_cursor( $this->token(), self::BOARD )
		);
	}

	/**
	 * @return void
	 */
	public function test_a_cursor_from_another_board_is_refused_not_guessed_at(): void {
		$this->assertNull( LeaderboardEngine::decode_cursor( $this->token( array( 'b' => 'ffffffff' ) ), self::BOARD ) );
	}

	/**
	 * @param string $token A cursor that must be refused.
	 * @return void
	 */
	#[DataProvider( 'bad_cursors' )]
	public function test_malformed_cursors_are_refused( string $token ): void {
		$this->assertNull( LeaderboardEngine::decode_cursor( $token, self::BOARD ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function bad_cursors(): array {
		$enc = static fn( array $d ): string => rtrim( strtr( base64_encode( json_encode( $d ) ), '+/', '-_' ), '=' );
		$ok  = array(
			'v' => 1,
			'b' => self::BOARD,
			'p' => 90,
			'u' => 7,
			'n' => 20,
			'r' => 18,
		);
		return array(
			'empty'                  => array( '' ),
			'not base64'             => array( '!!!not-a-cursor!!!' ),
			'base64 of non-json'     => array( rtrim( strtr( base64_encode( 'hello' ), '+/', '-_' ), '=' ) ),
			'json but not an object' => array( $enc( array( 1, 2, 3 ) ) ),
			'other version'          => array( $enc( array_merge( $ok, array( 'v' => 2 ) ) ) ),
			'missing field'          => array( $enc( array_diff_key( $ok, array( 'u' => 1 ) ) ) ),
			'negative served count'  => array( $enc( array_merge( $ok, array( 'n' => -1 ) ) ) ),
			'zero user'              => array( $enc( array_merge( $ok, array( 'u' => 0 ) ) ) ),
			'rank beyond served'     => array( $enc( array_merge( $ok, array( 'r' => 21 ) ) ) ),
			'string instead of int'  => array( $enc( array_merge( $ok, array( 'p' => '90' ) ) ) ),
			'absurdly long'          => array( str_repeat( 'A', 400 ) ),
		);
	}

	/**
	 * Run the engine's private rank_state() over raw rows.
	 *
	 * @param array<int, array<string, mixed>>           $rows Raw rows.
	 * @param array{p: int, u: int, n: int, r: int}|null $seed Seed.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private function rank_state( array $rows, ?array $seed = null ): array {
		$m = new \ReflectionMethod( LeaderboardEngine::class, 'rank_state' );
		return $m->invoke( null, $rows, $seed );
	}

	/**
	 * Rows for a board: points per member, highest first (user_id breaks ties downward, as the SQL does).
	 *
	 * @param int[] $points Points, descending.
	 * @return array<int, array<string, mixed>>
	 */
	private function board( array $points ): array {
		$rows = array();
		foreach ( $points as $i => $p ) {
			$rows[] = array(
				'user_id'      => 1000 - $i,
				'total_points' => $p,
			);
		}
		return $rows;
	}

	/**
	 * THE PROPERTY: page one plus page two equals one long page, at EVERY split point, ties included.
	 *
	 * The cursor carries (served, last points, last rank). Split a board anywhere, including in the
	 * middle of a run of tied members, and the second half seeded from the first half's state must
	 * end where the whole board ends.
	 *
	 * @return void
	 */
	public function test_ranks_continue_across_every_split_including_inside_a_tie(): void {
		// 100, 90, 90, 90, 80, 80, 70, 60, 60, 50: three ties, one straddling most splits.
		$rows  = $this->board( array( 100, 90, 90, 90, 80, 80, 70, 60, 60, 50 ) );
		$whole = $this->rank_state( $rows );

		for ( $split = 1; $split < count( $rows ); $split++ ) {
			$first        = array_slice( $rows, 0, $split );
			$second       = array_slice( $rows, $split );
			[ $n, $p, $r ] = $this->rank_state( $first );
			$seed         = array(
				'p' => $p,
				'u' => (int) end( $first )['user_id'],
				'n' => $n,
				'r' => $r,
			);
			$this->assertSame(
				$whole,
				$this->rank_state( $second, $seed ),
				"Page break after row {$split} changed the final rank state."
			);
		}
	}

	/**
	 * A tie that straddles a page boundary keeps ONE rank: the first row of page two, level with the
	 * last row of page one, is not renumbered.
	 *
	 * @return void
	 */
	public function test_a_tie_across_the_boundary_shares_one_rank(): void {
		$rows = $this->board( array( 100, 90, 90, 90, 80 ) );

		// Page one ends on the first of the three tied members (rank 2, 2 served).
		[ $n, $p, $r ] = $this->rank_state( array_slice( $rows, 0, 2 ) );
		$this->assertSame( array( 2, 90, 2 ), array( $n, $p, $r ) );

		// Page two starts with the other two tied members: they stay rank 2; the next score skips to 5.
		$seed = array(
			'p' => $p,
			'u' => 999,
			'n' => $n,
			'r' => $r,
		);
		$this->assertSame( array( 4, 90, 2 ), $this->rank_state( array_slice( $rows, 2, 2 ), $seed ) );
		$this->assertSame( array( 5, 80, 5 ), $this->rank_state( array_slice( $rows, 2 ), $seed ) );
	}

	/**
	 * The keyset query has no OFFSET, three extra placeholders for the cursor, and keeps the
	 * same-direction ordering that lets it ride a backward index scan.
	 *
	 * @return void
	 */
	public function test_the_keyset_totals_query_has_no_offset_and_binds_the_cursor(): void {
		$plain  = LeaderboardEngine::build_totals_query( 'wp_wb_gam_user_totals', '', '' );
		$keyset = LeaderboardEngine::build_totals_query( 'wp_wb_gam_user_totals', '', '', true );

		foreach ( array( $plain, $keyset ) as $sql ) {
			$this->assertStringNotContainsStringIgnoringCase( 'OFFSET', $sql );
			$this->assertStringContainsString( 'ORDER BY ut.earned DESC, ut.user_id DESC', $sql );
			$this->assertStringNotContainsString( 'muser_id', $sql );
		}

		$this->assertSame( 2, substr_count( $plain, '%' ), 'point_type and LIMIT only.' );
		$this->assertSame( 5, substr_count( $keyset, '%' ), 'point_type, three cursor binds, LIMIT.' );
		$this->assertStringContainsString( 'ut.earned < %d OR ( ut.earned = %d AND ut.user_id < %d )', $keyset );
	}

	/**
	 * The snapshot writer and the pager read one depth constant, so they cannot disagree about how
	 * far a day, week or month board goes.
	 *
	 * @return void
	 */
	public function test_the_snapshot_writer_uses_the_shared_depth(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/LeaderboardEngine.php' );
		$this->assertSame( 500, LeaderboardEngine::SNAPSHOT_DEPTH );
		$this->assertStringNotContainsString( 'LIMIT 500', $src, 'The writer hard-codes the depth again.' );
		$this->assertStringContainsString( 'self::SNAPSHOT_DEPTH', $src );
	}
}
