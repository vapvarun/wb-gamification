<?php
/**
 * The composed leaderboard query, not the fragment.
 *
 * THIS TEST EXISTS BECAUSE A GREEN SUITE SHIPPED A BLANK LEADERBOARD.
 *
 * `ExclusionScaleTest` asserts the exclusion fragment's placeholder count -- the invariant that
 * matters at 100k members -- and it passed. But it looked at the fragment ALONE. Nothing ever
 * looked at the query the fragment was composed into.
 *
 * The all-time branch reads the materialised totals table, which was not aliased, so it rewrote the
 * ledger's clauses with:
 *
 *     str_replace( 'p.user_id', 'user_id', $clause )
 *
 * That was harmless while the clause was a plain `NOT IN()`. Then the clause became an anti-join and
 * contained:
 *
 *     ... FROM wp_wb_gam_member_prefs mp WHERE mp.user_id = p.user_id ...
 *                                              ^^^^^^^^^
 * `p.user_id` matches INSIDE `mp.user_id`. The rewrite produced `muser_id`, MySQL answered
 * "Unknown column 'muser_id' in 'where clause'", the query returned nothing, and:
 *
 *   - every group- and cohort-scoped leaderboard was blank, on every site, always; and
 *   - the all-time board went blank whenever the snapshot was missing -- a fresh install, and
 *     permanently on any host with WP-Cron disabled.
 *
 * Found by QA over live HTTP, not by the suite. The lesson is in the assertion below: a fragment is
 * not a query, and an alias is not a string you can search-and-replace.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\PointsEngine;

/**
 * @coversDefaultClass \WBGam\Engine\LeaderboardEngine
 */
class LeaderboardQueryCompositionTest extends TestCase {

	/**
	 * The real shape of the opt-out anti-join, for the `ut` alias the totals query uses.
	 *
	 * Hardcoded to the shape `LeaderboardEngine::exclusion_sql()` emits, because that method needs
	 * $wpdb and this test deliberately has no database. If that shape ever changes, the execution
	 * check in `wp wb-gamification doctor` is what catches it -- see the class docblock there.
	 *
	 * @param string $alias Table alias.
	 * @return string
	 */
	private function opt_out_fragment( string $alias ): string {
		return ' AND NOT EXISTS ( SELECT 1 FROM wp_wb_gam_member_prefs mp'
			. ' WHERE mp.user_id = ' . $alias . '.user_id AND mp.leaderboard_opt_out = 1 )';
	}

	/**
	 * L1 — THE REGRESSION. The composed query must never contain a mangled identifier.
	 *
	 * Restore the `str_replace` and this test goes red. That is the whole point of it.
	 *
	 * @covers ::build_totals_query
	 * @return void
	 */
	public function test_the_composed_query_never_mangles_an_alias(): void {
		$sql = LeaderboardEngine::build_totals_query(
			'wp_wb_gam_user_totals',
			'wp_users',
			$this->opt_out_fragment( 'ut' ),
			''
		);

		$this->assertStringNotContainsString(
			'muser_id',
			$sql,
			'`mp.user_id` was rewritten to `muser_id`. MySQL rejects the query and the leaderboard '
			. 'renders blank on every site.'
		);

		// The anti-join must arrive INTACT: both sides of the join predicate, spelled correctly.
		$this->assertStringContainsString( 'mp.user_id = ut.user_id', $sql );
	}

	/**
	 * L2 — the fragment is composed against the alias the query actually uses.
	 *
	 * The totals table is `ut`. A clause built for the ledger's `p` has no business in this query,
	 * and there is no rewriting step that could put it there safely.
	 *
	 * @covers ::build_totals_query
	 * @return void
	 */
	public function test_the_totals_query_filters_on_its_own_alias(): void {
		$sql = LeaderboardEngine::build_totals_query(
			'wp_wb_gam_user_totals',
			'wp_users',
			$this->opt_out_fragment( 'ut' ),
			'AND ut.user_id IN (%d,%d)'
		);

		$this->assertStringContainsString( 'FROM wp_wb_gam_user_totals ut', $sql );
		$this->assertStringContainsString( 'WHERE ut.point_type = %s', $sql );
		$this->assertStringContainsString( 'AND ut.user_id IN (%d,%d)', $sql );

		// `p` is the LEDGER's alias. It is not defined anywhere in this query, so any reference to
		// it is a bug -- whether it got there by a rewrite or by a copy-paste.
		$this->assertStringNotContainsString( ' p.user_id', $sql, 'The totals query has no `p` alias.' );
		$this->assertStringNotContainsString( 'FROM wp_wb_gam_points', $sql, 'The all-time board must not read the ledger.' );
	}

	/**
	 * L3 — the OWNER-exclusion fragment composes too, with its placeholders intact.
	 *
	 * This is the fragment ExclusionScaleTest already guards for placeholder count. Here it is
	 * checked where it is actually used: inside the query, against the right alias, unmangled.
	 *
	 * @covers ::build_totals_query
	 * @return void
	 */
	public function test_the_owner_exclusion_fragment_survives_composition(): void {
		[ $excl, $values ] = PointsEngine::build_exclusion_sql(
			array( 7, 9 ),
			array( 'subscriber' ),
			'ut',
			'wp_capabilities',
			'wp_usermeta'
		);

		$sql = LeaderboardEngine::build_totals_query(
			'wp_wb_gam_user_totals',
			'wp_users',
			$this->opt_out_fragment( 'ut' ) . $excl,
			''
		);

		$this->assertStringContainsString( 'ut.user_id NOT IN (%d,%d)', $sql );
		$this->assertStringContainsString( 'um.user_id = ut.user_id', $sql );
		$this->assertStringNotContainsString( 'muser_id', $sql );

		// The bind count must still match the placeholders the composed query carries: 2 ids, the
		// capability key, and 1 role LIKE. A composition step that dropped or duplicated a
		// placeholder would bind the wrong value into the wrong column, silently.
		$this->assertSame( 4, count( $values ) );
		$this->assertSame( 4, substr_count( $excl, '%d' ) + substr_count( $excl, '%s' ) );
	}
}
