<?php
/**
 * A member's data leaves through ONE door, and the door cannot be left ajar by forgetting a table.
 *
 * Two things were broken, and they were the same thing:
 *
 *   1. Nothing listened for `deleted_user`. An owner deleting a member left every points row, streak,
 *      badge, cohort membership and queued notification behind, for ever.
 *   2. The GDPR eraser HAND-LISTED the tables it deleted from. That list was written once and drifted:
 *      notifications_queue, cohort_members, user_intelligence, redemptions, community-challenge
 *      contributions and api_keys were all added later and never added to it. A member who exercised
 *      their right to erasure was not erased.
 *
 * The list was the bug. You cannot fix a stale list by adding the six missing tables, because the
 * seventh gets added next month by someone who has never heard of the list.
 *
 * So MemberData asks the SCHEMA. These tests pin that: the purge is derived from the columns that
 * exist, the owned/referenced distinction is explicit, and a table added tomorrow is covered on the
 * day it is created.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \WBGam\Engine\MemberData
 */
class MemberPurgeTest extends TestCase {

	/**
	 * The source of the purge, for the assertions that are about what the code says.
	 *
	 * @return string
	 */
	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/MemberData.php' );
	}

	/**
	 * P1 — the purge is DERIVED FROM THE SCHEMA, not from a list someone has to remember to update.
	 *
	 * This is the whole fix. If a future change replaces `SHOW COLUMNS` with a hardcoded array of
	 * table names, the class is back to the bug it was written to end, and this test says so.
	 *
	 * @return void
	 */
	public function test_the_purge_asks_the_schema_which_tables_hold_member_data(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			'SHOW COLUMNS FROM',
			$src,
			'The set of tables to purge must be READ FROM THE SCHEMA. A hardcoded list is exactly what '
			. 'left six tables full of erased members\' data.'
		);

		$this->assertStringContainsString( "SHOW TABLES LIKE", $src );
	}

	/**
	 * P2 — rows the member OWNS are deleted; rows that merely REFERENCE them are anonymised.
	 *
	 * A submission reviewed by a member belongs to the member who SUBMITTED it. Deleting it because
	 * the reviewer left would destroy a third party's data — a worse bug than the one being fixed.
	 *
	 * @return void
	 */
	public function test_owned_rows_are_deleted_and_referenced_rows_are_anonymised(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			"private const OWNED_BY = array( 'user_id', 'giver_id', 'receiver_id' );",
			$src,
			'Kudos are keyed on giver_id/receiver_id, not user_id. A purge that only knows about '
			. 'user_id leaves every kudos the member ever sent or received.'
		);

		$this->assertStringContainsString(
			"private const REFERENCES = array( 'reviewer_id' );",
			$src,
			'reviewer_id points at somebody ELSE\'s submission. It is anonymised, never deleted.'
		);

		// The distinction has to be acted on, not just declared.
		$this->assertMatchesRegularExpression( '/foreach \( \$cols\[.owned.\] as \$column \) \{\s*\$count \+= \(int\) \$wpdb->delete/', $src );
		$this->assertMatchesRegularExpression( '/foreach \( \$cols\[.refs.\] as \$column \) \{\s*\$wpdb->update/', $src );
	}

	/**
	 * P3 — BOTH doors use it. This is the assertion that would have caught the original bug.
	 *
	 * The GDPR eraser and `deleted_user` must reach the same code. Two paths is how one of them ends
	 * up missing six tables.
	 *
	 * @return void
	 */
	public function test_both_doors_lead_to_the_same_purge(): void {
		$privacy = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/Privacy.php' );
		$src     = $this->source();

		$this->assertStringContainsString(
			'MemberData::purge( $user_id )',
			$privacy,
			'The GDPR eraser must not keep its own list of tables. It had one, and it was wrong.'
		);

		$this->assertStringContainsString( "add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );", $src );
		$this->assertStringContainsString( "add_action( 'wpmu_delete_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );", $src );
	}

	/**
	 * P4 — cleaning up EXISTING orphans is a command the owner runs, not something an upgrade does.
	 *
	 * Silently deleting a site owner's data because they installed a patch is not a thing a plugin
	 * gets to do, however dead that data looks to us.
	 *
	 * @return void
	 */
	public function test_orphan_cleanup_is_never_an_upgrade_migration(): void {
		$src      = $this->source();
		$upgrader = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/DbUpgrader.php' );

		$this->assertStringContainsString( 'public static function purge_orphans(): array', $src );

		$this->assertStringNotContainsString(
			'purge_orphans',
			$upgrader,
			'Orphan cleanup must NOT run from DbUpgrader. An upgrade that deletes rows the owner never '
			. 'agreed to lose is a bigger problem than the orphans.'
		);
	}
}
