<?php
/**
 * Regression guards for the materialised leaderboard.
 *
 * The snapshot table existed, the cron rebuilt it every 5 minutes, and on a busy
 * site NOTHING WAS EVER ALLOWED TO READ IT. Two independent defects, either of
 * which alone was enough:
 *
 *  1. SELF-INVALIDATION. `wb_gam_leaderboard_invalidated_at` was written on every
 *     points award, and read_from_snapshot() refused any snapshot older than it.
 *     The first award after each rebuild disabled the snapshot; on a 100k site
 *     awards land many times per second, so the snapshot was readable for
 *     milliseconds per five-minute cycle. ~100% of reads fell through to a
 *     full-table SUM over wb_gam_points -- on GET /leaderboard, whose
 *     permission_callback is __return_true.
 *
 *  2. CLOCK MISMATCH. write_snapshot() stamped rows with MySQL's NOW() (server
 *     time, ~always UTC) but computed its straggler-purge cutoff with
 *     current_time('mysql') (WordPress SITE-LOCAL time). On any site ahead of UTC
 *     the cutoff was hours ahead of the rows just written, so the closing DELETE
 *     matched every one of them and emptied the table at the end of every rebuild.
 *     Invisible on a UTC dev box, which is exactly why it survived.
 *
 * These tests assert against the source, because both defects are about which
 * clock and which gate the code uses -- not about a value it returns.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \WBGam\Engine\LeaderboardEngine
 */
class LeaderboardSnapshotTest extends TestCase {

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/LeaderboardEngine.php' );
	}

	/**
	 * The snapshot must not be gated on a per-award invalidation stamp.
	 *
	 * @test
	 */
	public function snapshot_is_not_disabled_by_every_award(): void {
		$src = $this->source();

		$this->assertStringNotContainsString(
			"update_option( 'wb_gam_leaderboard_invalidated_at'",
			$src,
			'Writing an invalidation stamp on every award disables the snapshot AND makes one '
			. 'wp_options row a write-serialisation point on the hottest path in the plugin.'
		);

		$this->assertStringNotContainsString(
			"get_option( 'wb_gam_leaderboard_invalidated_at'",
			$src,
			'read_from_snapshot() must not gate on a per-award invalidation stamp. A materialised '
			. 'leaderboard is eventually consistent by design, bounded by the rebuild interval.'
		);
	}

	/**
	 * Both sides of the straggler purge must come from the same clock.
	 *
	 * @test
	 */
	public function straggler_purge_uses_the_database_clock_not_site_local_time(): void {
		$src = $this->source();

		// The rows are stamped by MySQL NOW(); the cutoff must come from the DB too.
		$this->assertMatchesRegularExpression(
			"/\\\$started\s*=\s*\(string\)\s*\\\$wpdb->get_var\(\s*'SELECT NOW\(\)'\s*\);/",
			$src,
			'The straggler cutoff must be read from the database clock. Using '
			. "current_time('mysql') (site-local) against rows stamped NOW() (server/UTC) makes the "
			. 'closing DELETE wipe the whole snapshot on any site ahead of UTC.'
		);

		$this->assertStringNotContainsString(
			"$started = current_time( 'mysql' );",
			$src,
			"current_time('mysql') is site-local and must never be compared against NOW()-stamped rows."
		);
	}

	/**
	 * The all-time board must read the materialised totals, not aggregate the ledger.
	 *
	 * @test
	 */
	public function all_time_board_reads_the_materialised_totals(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			'wb_gam_user_totals',
			$src,
			'The all-time leaderboard must read wb_gam_user_totals (KEY idx_type_total), not '
			. 'SUM(points) GROUP BY user_id over the whole ledger. The totals table is maintained '
			. 'transactionally on every award and is indexed exactly for "top N by total".'
		);
	}
}
