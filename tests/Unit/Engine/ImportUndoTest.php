<?php
/**
 * Undo takes back exactly what an import added.
 *
 * The property that matters: a member's total is corrected by the delta each imported event recorded
 * in its OWN metadata, not by recomputing from the points ledger. LogPruner deletes points rows older
 * than the retention horizon, so an import of years of history has no points rows left for most of it
 * while the totals still count all of it. A recompute-from-ledger undo would leave the totals inflated
 * (or, done the other way round, shrink balances the way the legacy SUM-based total did).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ImportUndo;

#[CoversClass( ImportUndo::class )]
class ImportUndoTest extends TestCase {

	/**
	 * A source file's code with its comments removed, so an assertion about what the code DOES is not
	 * fooled by prose that explains what it must not do.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	private function code( string $file ): string {
		$out = '';
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	/**
	 * An imported event row, as read from wb_gam_events.
	 *
	 * @param string               $id       Event id.
	 * @param int                  $user     Member.
	 * @param array<string, mixed> $metadata Event metadata.
	 * @param string               $type     Currency.
	 * @return array{id: string, user_id: int, point_type: string, metadata: string}
	 */
	private function event( string $id, int $user, array $metadata, string $type = 'points' ): array {
		return array(
			'id'         => $id,
			'user_id'    => $user,
			'point_type' => $type,
			'metadata'   => (string) json_encode( $metadata ),
		);
	}

	public function test_it_sums_each_members_contribution_per_currency_including_deductions(): void {
		$tally = ImportUndo::tally(
			array(
				$this->event( 'a', 7, array( 'points' => 10 ) ),
				$this->event( 'b', 7, array( 'points' => -3 ) ),
				$this->event( 'c', 7, array( 'points' => 5 ), 'coins' ),
				$this->event( 'd', 9, array( 'points' => 20 ) ),
			)
		);

		$this->assertSame( 0, $tally['unrecoverable'] );
		$this->assertSame(
			array(
				'user_id'    => 7,
				'point_type' => 'points',
				'total'      => 7,
				'earned'     => 7,
			),
			$tally['by_member']['7|points']
		);
		$this->assertSame( 5, $tally['by_member']['7|coins']['total'], 'Currencies are kept apart.' );
		$this->assertSame( 20, $tally['by_member']['9|points']['total'] );
		$this->assertCount( 3, $tally['by_member'] );
	}

	/**
	 * A spend never moved `earned` when it was written (PointsEngine::bump_user_total), so undo must
	 * not subtract it from `earned` either, or `earned` ends up too LOW.
	 */
	public function test_a_spend_reverses_total_but_not_earned(): void {
		$tally = ImportUndo::tally(
			array(
				$this->event( 'a', 7, array( 'points' => 100 ) ),
				$this->event( 'b', 7, array( 'points' => -30, '_spend' => true ) ),
			)
		);

		$this->assertSame( 70, $tally['by_member']['7|points']['total'] );
		$this->assertSame( 100, $tally['by_member']['7|points']['earned'] );
	}

	/**
	 * An event with no recorded delta cannot be reversed. It is counted and reported, never guessed at,
	 * and it does not turn a member's other events into a wrong number.
	 */
	public function test_events_without_a_recorded_delta_are_counted_not_guessed(): void {
		$tally = ImportUndo::tally(
			array(
				$this->event( 'a', 7, array( 'points' => 10 ) ),
				$this->event( 'b', 7, array( 'source' => 'x' ) ),
				$this->event( 'c', 7, array( 'points' => 'not a number' ) ),
				array(
					'id'         => 'd',
					'user_id'    => 7,
					'point_type' => 'points',
					'metadata'   => '{not json',
				),
			)
		);

		$this->assertSame( 3, $tally['unrecoverable'] );
		$this->assertSame( 10, $tally['by_member']['7|points']['total'] );
	}

	public function test_an_empty_currency_falls_back_to_points(): void {
		$tally = ImportUndo::tally( array( $this->event( 'a', 7, array( 'points' => 4 ), '' ) ) );
		$this->assertArrayHasKey( '7|points', $tally['by_member'] );
	}

	/**
	 * The total is corrected from the events, never recomputed from the ledger. Reading the points
	 * table here would make undo depend on rows the retention prune may already have deleted.
	 */
	public function test_undo_does_not_recompute_totals_from_the_points_ledger(): void {
		$src = $this->code( dirname( __DIR__, 3 ) . '/src/Engine/ImportUndo.php' );

		$this->assertStringContainsString( 'SET total = total - %d, earned = earned - %d', $src );
		$this->assertStringNotContainsString( 'SUM(points)', $src, 'A recompute from the ledger reintroduces the pruned-rows bug.' );
		$this->assertStringNotContainsString( 'SUM(p.points)', $src );
	}

	/**
	 * A member's cached earned-badge list must be cleared when undo deletes their awards. Otherwise a
	 * persistent object cache still says they hold the badge and a re-import awards nothing.
	 */
	public function test_deleting_badge_awards_clears_each_members_cached_badge_list(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ImportUndo.php' );

		$this->assertStringContainsString( 'wb_gam_earned_badges_{$user_id}', $src );
		$this->assertStringContainsString( 'BadgeEngine::flush_rarity_cache()', $src );
	}

	/**
	 * A rolled-back page (Transaction::run returns null) must be a failure. Read as "no events left"
	 * it would skip on to the next phase with the events still in place.
	 */
	public function test_a_rolled_back_page_is_an_error_not_the_end(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ImportUndo.php' );
		$this->assertStringContainsString( 'null === $removed', $src );
	}
}
