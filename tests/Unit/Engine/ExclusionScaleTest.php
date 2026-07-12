<?php
/**
 * The exclusion clause must not grow with the number of excluded MEMBERS.
 *
 * The leaderboard let an owner exclude a ROLE. `excluded_user_ids()` then expanded that role
 * into every user id it contained -- `get_users( [ 'role__in' => $roles ] )`, with no limit --
 * and `LeaderboardEngine` imploded the result into:
 *
 *     AND p.user_id NOT IN ( %d, %d, %d, ... )
 *
 * On a 100,000-member site where the owner excludes "subscriber" -- and subscriber IS most
 * members -- that is a prepared statement carrying a hundred thousand placeholders. It exceeds
 * MySQL's max_allowed_packet and the query dies. Both the snapshot read and the live fallback
 * carried the clause, so there was no path left that worked: the leaderboard simply stopped
 * existing the moment an owner used a setting we shipped.
 *
 * The fix is not a bigger packet. It is to stop turning a ROLE into a LIST. A role is a
 * predicate, and predicates belong in SQL:
 *
 *     AND NOT EXISTS ( SELECT 1 FROM wp_usermeta um
 *                       WHERE um.user_id = p.user_id
 *                         AND um.meta_key = 'wp_capabilities'
 *                         AND um.meta_value LIKE '%"subscriber"%' )
 *
 * This test pins the invariant that makes the difference: **the number of placeholders is a
 * function of how many things the ADMIN typed, never of how many members the site has.**
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WBGam\Engine\PointsEngine;

/**
 * @coversDefaultClass \WBGam\Engine\PointsEngine
 */
class ExclusionScaleTest extends TestCase {

	private const USERMETA = 'wp_usermeta';
	private const CAP_KEY  = 'wp_capabilities';

	/**
	 * Count `%d`/`%s` placeholders in a SQL fragment.
	 *
	 * @param string $sql Fragment.
	 * @return int
	 */
	private function placeholders( string $sql ): int {
		return preg_match_all( '/%[ds]/', $sql );
	}

	/**
	 * Excluding a ROLE must add a constant number of placeholders — no matter whether that role
	 * holds ten members or ten million.
	 *
	 * THIS IS THE BUG. Before the fix, excluding one role on a 100k-member site produced 100,000
	 * placeholders. After it, a role costs exactly two (the meta_key, and one LIKE).
	 *
	 * @return void
	 */
	public function test_excluding_a_role_costs_the_same_at_any_site_size(): void {
		[ $sql, $values ] = PointsEngine::build_exclusion_sql(
			array(),                  // no explicit user exclusions
			array( 'subscriber' ),    // one excluded role — which may hold 100,000 members
			'p',
			self::CAP_KEY,
			self::USERMETA
		);

		$this->assertSame(
			2,
			$this->placeholders( $sql ),
			'A role must cost a CONSTANT number of placeholders (meta_key + one LIKE). If this '
			. 'number tracks the member count, the leaderboard dies on any large site.'
		);
		$this->assertCount( 2, $values, 'Bound values must match the placeholders exactly.' );
		$this->assertStringContainsString( 'NOT EXISTS', $sql, 'A role is a predicate, not a list.' );
		$this->assertStringNotContainsString( 'NOT IN', $sql, 'A role must never be expanded into an id list.' );
	}

	/**
	 * Three excluded roles cost three LIKEs, not three hundred thousand ids.
	 *
	 * @return void
	 */
	public function test_placeholders_scale_with_ROLES_not_with_MEMBERS(): void {
		$one = PointsEngine::build_exclusion_sql( array(), array( 'subscriber' ), 'p', self::CAP_KEY, self::USERMETA );
		$two = PointsEngine::build_exclusion_sql( array(), array( 'subscriber', 'customer' ), 'p', self::CAP_KEY, self::USERMETA );
		$six = PointsEngine::build_exclusion_sql( array(), array( 'a', 'b', 'c', 'd', 'e', 'f' ), 'p', self::CAP_KEY, self::USERMETA );

		$this->assertSame( 2, $this->placeholders( $one[0] ) );
		$this->assertSame( 3, $this->placeholders( $two[0] ) );
		$this->assertSame( 7, $this->placeholders( $six[0] ) );

		// One per role, plus the shared meta_key. Bounded by the admin's checkbox list.
		$this->assertLessThan(
			20,
			$this->placeholders( $six[0] ),
			'Even six excluded roles must stay in single digits. A site has a handful of roles '
			. 'and a hundred thousand members; only the first number may appear here.'
		);
	}

	/**
	 * Explicitly-named users are a different case, and a small `IN()` is the RIGHT answer for
	 * them: that list is typed by an admin, one id at a time. It is bounded by human patience.
	 *
	 * @return void
	 */
	public function test_explicit_user_ids_stay_a_small_in_list(): void {
		[ $sql, $values ] = PointsEngine::build_exclusion_sql(
			array( 7, 12, 99 ),
			array(),
			'p',
			self::CAP_KEY,
			self::USERMETA
		);

		$this->assertStringContainsString( 'NOT IN', $sql, 'A short admin-typed list is fine as an IN().' );
		$this->assertSame( 3, $this->placeholders( $sql ) );
		$this->assertSame( array( 7, 12, 99 ), $values );
	}

	/**
	 * Both kinds together, and the bound values must line up with the placeholders IN ORDER --
	 * get that wrong and $wpdb->prepare() silently binds the meta_key into a user id.
	 *
	 * @return void
	 */
	public function test_values_align_with_placeholders_in_order(): void {
		[ $sql, $values ] = PointsEngine::build_exclusion_sql(
			array( 5 ),
			array( 'subscriber' ),
			'p',
			self::CAP_KEY,
			self::USERMETA
		);

		$this->assertSame(
			$this->placeholders( $sql ),
			count( $values ),
			'Every placeholder must have exactly one value, or prepare() will bind the wrong thing.'
		);

		// Order matters: the IN() ids come first, then meta_key, then the LIKEs.
		$this->assertSame( 5, $values[0] );
		$this->assertSame( self::CAP_KEY, $values[1] );
		$this->assertStringContainsString( 'subscriber', (string) $values[2] );
	}

	/**
	 * No exclusions configured: no clause, no values, no cost.
	 *
	 * @return void
	 */
	public function test_no_exclusions_produces_no_clause(): void {
		[ $sql, $values ] = PointsEngine::build_exclusion_sql( array(), array(), 'p', self::CAP_KEY, self::USERMETA );

		$this->assertSame( '', trim( $sql ) );
		$this->assertSame( array(), $values );
	}
}
