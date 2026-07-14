<?php
/**
 * Everything this plugin stores about one member, and the single way to remove it.
 *
 * THERE WAS NO SUCH PLACE, AND BOTH DOORS LEAKED.
 *
 * A member's rows can leave for two entirely different reasons:
 *
 *   1. They exercise their right to erasure (the GDPR eraser).
 *   2. An owner deletes them in wp-admin.
 *
 * Door 2 did not exist at all: there was no `deleted_user` hook anywhere in the plugin, so deleting a
 * member left every points row, streak, badge, cohort membership and queued notification behind, for
 * ever. On the dev site that is 11,378 orphaned streak rows against 152 real ones -- and it is what
 * makes the Analytics dashboard print "6822.5% streak health", because the numerator counts rows for
 * members who no longer exist.
 *
 * Door 1 existed but had drifted. `Privacy::erase_user_data()` HAND-LISTED the tables it deleted from,
 * and that list was written once. Every table added since -- notifications_queue (23k rows), cohort
 * members (11k), user_intelligence, redemptions, community-challenge contributions, api_keys -- was
 * simply not in it. A member who asked to be erased was not erased.
 *
 * A hand-maintained list of tables is the bug. It cannot be fixed by adding the missing seven, because
 * the eighth will be added next month by someone who does not know this list exists.
 *
 * So this class does not hold a list. It ASKS THE SCHEMA which tables carry a reference to a member,
 * and purges each one. A table added tomorrow is covered on the day it is created, and the test below
 * fails the build if a user-keyed table ever escapes it.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * The one purge path for a member's gamification data.
 *
 * @package WB_Gamification
 */
final class MemberData {

	/**
	 * Columns that mean "this row BELONGS to the member". These rows are deleted.
	 *
	 * @var string[]
	 */
	private const OWNED_BY = array( 'user_id', 'giver_id', 'receiver_id' );

	/**
	 * Columns that mean "the member is REFERENCED on somebody else's row".
	 *
	 * These are anonymised, never deleted. A submission reviewed by a member belongs to the member who
	 * SUBMITTED it -- deleting it because the reviewer left would destroy a third party's data, which
	 * is a worse bug than the one we are fixing.
	 *
	 * @var string[]
	 */
	private const REFERENCES = array( 'reviewer_id' );

	/**
	 * Usermeta keys this plugin writes.
	 *
	 * WordPress owns `wp_usermeta`, so unlike our own tables this half cannot be discovered from the
	 * schema -- there is no `SHOW COLUMNS` that tells you which meta keys are ours. It has to be a
	 * list, and a list drifts. Rule 11 in `bin/coding-rules-check.sh` exists to catch that drift.
	 *
	 * It did not. For most of this cycle Rule 11 reported "every meta key is covered" while FIVE keys
	 * below were covered by nothing -- not this list, not the export, not uninstall -- because the
	 * gate looked for a bare `'wb_gam_...'` string as the second argument of the call. Every one of
	 * the five is written as `self::SOME_CONST` (and one carries a leading underscore), so the gate
	 * simply could not see them and passed with confidence.
	 *
	 * That is the same failure this class was written to end, one layer down: the check was textual
	 * where it needed to be semantic. Rule 11 now resolves `self::CONST` back to its literal before
	 * comparing, so a key added that way can no longer hide from it.
	 *
	 * @var string[]
	 */
	private const USER_META_KEYS = array(
		'wb_gam_pr_best_week',
		'wb_gam_login_streak',
		'wb_gam_login_streak_max',
		'wb_gam_login_last_award',
		'wb_gam_seen_first_earn_toast',
		'wb_gam_dismissed_welcome',
		'wb_gam_dismissed_checklist',
		'wb_gam_setup_seen',
		'wb_gam_profile_public',
		'wb_gam_sandboxed',
		// These three were in the EXPORT and not in the erase, so a member who exercised their right
		// to erasure kept their level and their league tier. Found by the strengthened Rule 11 the
		// moment it started checking coverage instead of checking that a string appeared in a file.
		'wb_gam_level_id',
		'wb_gam_level_name',
		'wb_gam_league_tier',
		// The five the gate could not see. All are written against a MEMBER (verified at each write
		// site), so all are member data and all must go when the member does.
		'_wb_gam_last_award_note',      // PointsController, ManualAwardPage — staff's note about an award, stored on the member.
		'wb_gam_decayed_at',            // PointsExpiry::META_LAST.
		'wb_gam_last_retention_nudge',  // StatusRetentionEngine::NUDGE_META.
		'wb_gam_dismissed_wizard_notice', // SetupWizard::NOTICE_DISMISSED_META.
		// Nothing in this plugin WRITES this one -- ActivityPub only reads it, so the opt-in is set by
		// a filter or by hand. It is still our namespaced key on the member's account, and a member
		// who erases their data should not be left federating events to the fediverse afterwards.
		'wb_gam_federate_events',       // ActivityPub::USER_OPT_IN.
	);

	/**
	 * Usermeta key PREFIXES this plugin writes.
	 *
	 * Some keys are not a key at all until runtime: NotificationBridge stores a read-cursor per
	 * delivery channel as `CURSOR_META_PREFIX . $channel`, so the actual rows on a member's account
	 * are `wb_gam_notif_cursor_footer`, `..._heartbeat`, `..._rest`.
	 *
	 * A key that only exists once you concatenate it cannot be caught by a list of literals, and it
	 * was not: the erase and the export both missed the whole family. (`ProgressReset` hand-listed the
	 * three channels that existed when it was written -- which is the same list, kept in a second
	 * place, and therefore the same drift waiting to happen. It now derives from here.)
	 *
	 * Anything matching one of these prefixes is ours and goes when the member goes. Adding a fourth
	 * channel tomorrow needs no edit here, which is the entire point.
	 *
	 * @var string[]
	 */
	private const USER_META_PREFIXES = array(
		'wb_gam_notif_cursor_', // NotificationBridge::CURSOR_META_PREFIX.
	);

	/**
	 * Every usermeta key this member actually has, literals plus prefix families.
	 *
	 * Resolved against the database rather than assumed, so a channel nobody remembered still gets
	 * found.
	 *
	 * @param int $user_id Member.
	 * @return string[] Meta keys present on this member's account.
	 */
	public static function user_meta_keys( int $user_id ): array {
		global $wpdb;

		$keys = self::USER_META_KEYS;

		foreach ( self::USER_META_PREFIXES as $prefix ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- usermeta key discovery; there is no core API for "keys matching a prefix".
					"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
					$user_id,
					$wpdb->esc_like( $prefix ) . '%'
				)
			);

			$keys = array_merge( $keys, array_map( 'strval', (array) $found ) );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Register the hooks that close the second door.
	 *
	 * `deleted_user` fires AFTER WordPress has removed the user, which is what we want: by then the
	 * decision is final and the rows are unambiguously orphans.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'on_user_deleted' ), 10, 1 );
	}

	/**
	 * An owner deleted a member. Take their gamification data with them.
	 *
	 * @param int $user_id Deleted user.
	 * @return void
	 */
	public static function on_user_deleted( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		self::purge( $user_id );
	}

	/**
	 * Every wb_gam_* table that references a member, discovered from the SCHEMA.
	 *
	 * This is the whole point of the class. Nothing here is hardcoded, so nothing here can go stale.
	 *
	 * @return array<string, array{owned: string[], refs: string[]}> Table name => columns.
	 */
	public static function member_tables(): array {
		global $wpdb;

		$tables = (array) $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wb_gam_' ) . '%' )
		);

		$map = array();

		foreach ( $tables as $table ) {
			$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

			$owned = array_values( array_intersect( self::OWNED_BY, $columns ) );
			$refs  = array_values( array_intersect( self::REFERENCES, $columns ) );

			if ( $owned || $refs ) {
				$map[ $table ] = array(
					'owned' => $owned,
					'refs'  => $refs,
				);
			}
		}

		return $map;
	}

	/**
	 * Remove everything this plugin stores about one member.
	 *
	 * Atomic: an interruption halfway through would leave the member's ledger gone but their totals
	 * intact, which is exactly the kind of half-state that makes a balance impossible to explain.
	 *
	 * NOT paged, and that is deliberate -- the obvious objection is answered here so nobody has to
	 * re-litigate it. The EXPORTER, 90 lines away in Privacy.php, genuinely does need paging: it
	 * SELECTs a member's rows into PHP, and an engaged member with 50k ledger rows will exhaust the
	 * memory limit. It is tempting to conclude the eraser has the same problem, since it touches the
	 * same rows. It does not. A DELETE hands the work to MySQL and streams nothing into PHP.
	 *
	 * Measured, not assumed -- 50,001-row member on the dev box: 261 ms, ZERO growth in peak PHP
	 * memory, no residue. Paging this would trade the atomicity above (real, and the reason this
	 * transaction exists) for a memory problem that does not exist. If a member can ever hold rows in
	 * the millions, re-measure before changing anything.
	 *
	 * @param int $user_id Member to purge.
	 * @return array<string,int> Table (unprefixed) => rows removed.
	 */
	public static function purge( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$removed = array();

		$wpdb->query( 'START TRANSACTION' );

		foreach ( self::member_tables() as $table => $cols ) {
			$short = str_replace( $wpdb->prefix, '', $table );
			$count = 0;

			// Rows the member OWNS.
			foreach ( $cols['owned'] as $column ) {
				$count += (int) $wpdb->delete( $table, array( $column => $user_id ), array( '%d' ) );
			}

			// Rows that merely REFERENCE the member: keep the row, forget the person.
			foreach ( $cols['refs'] as $column ) {
				$wpdb->update( $table, array( $column => 0 ), array( $column => $user_id ), array( '%d' ), array( '%d' ) );
			}

			if ( $count > 0 ) {
				$removed[ $short ] = $count;
			}
		}

		// Literals AND the prefix families, resolved against this member's actual rows -- a cursor for
		// a channel added after this line was written is still theirs, and still goes.
		foreach ( self::user_meta_keys( $user_id ) as $meta_key ) {
			delete_user_meta( $user_id, $meta_key );
		}

		$wpdb->query( 'COMMIT' );

		// Removing a member's badges changes every badge's rarity, and their points change the boards.
		BadgeEngine::flush_rarity_cache();
		wp_cache_flush();

		/**
		 * Fires after a member's gamification data has been purged.
		 *
		 * @since 1.6.4
		 *
		 * @param int               $user_id Member that was purged.
		 * @param array<string,int> $removed Table => rows removed.
		 */
		do_action( 'wb_gam_member_purged', $user_id, $removed );

		return $removed;
	}

	/**
	 * Every row this plugin holds about one member, for the GDPR export.
	 *
	 * THE EXPORT LEAKED IN THE SAME DIRECTION THE ERASE DID, for the same reason.
	 *
	 * `Privacy::export_user_data()` hand-lists its tables too, and that list covers seven: points,
	 * events, streaks, badges, submissions, member_prefs, badge_defs. It omits the member's KUDOS,
	 * their cohort history, their redemptions, their challenge log, their queued notifications, their
	 * intelligence profile and their totals. A member asking "what do you hold on me?" was told about
	 * half of it.
	 *
	 * This returns EVERY member-keyed table, from the schema. The curated groups in Privacy.php stay --
	 * they are readable, and a data export a human cannot read is a poor answer to the question -- but
	 * anything they miss is caught here rather than silently dropped.
	 *
	 * @param int      $user_id Member.
	 * @param string[] $skip    Unprefixed table names the caller exports itself.
	 * @param int      $limit   Rows per table, per call. 0 = no limit (erasure/counting callers).
	 * @param int      $offset  Rows to skip per table. Paired with $limit.
	 * @return array<string, array<int, array<string,mixed>>> Table (unprefixed) => rows.
	 */
	public static function export_rows( int $user_id, array $skip = array(), int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$out = array();

		foreach ( self::member_tables() as $table => $cols ) {
			$short = str_replace( $wpdb->prefix, '', $table );

			// Tables the CALLER already exports itself, paged.
			//
			// This is the whole fix, and the bug it closes is one I wrote. Privacy::export_user_data()
			// pages wb_gam_points and wb_gam_events deliberately -- a member with 50k ledger rows will
			// exhaust the memory limit if you read them in one go, which is exactly why the exporter
			// was given a $page in the first place. Then it called this catch-all, which SELECTed every
			// member table including those two, unbounded... and threw the result away afterwards
			// because its own $covered list said they were already handled.
			//
			// So the paging was real and the memory blow-up was real at the same time: we loaded all
			// 50,000 rows into PHP on every single export page, purely to discard them. The skip has to
			// happen at the QUERY, not at the array.
			//
			// What is left after the skip is per-member small (kudos, redemptions, submissions, cohort
			// membership, the capped notification queue) -- hundreds of rows, not tens of thousands.
			if ( in_array( $short, $skip, true ) ) {
				continue;
			}

			// PAGED, because "what is left after the skip is small" was an assumption, not a
			// measurement -- and it was mine. wb_gam_notifications_queue is a per-member table that
			// grows with every notification a member is ever sent; on an active member it is thousands
			// of rows, and this read had no LIMIT on it at all. Skipping the ledger fixed the two
			// tables I had looked at and left every other table exactly as unbounded as before.
			//
			// A deterministic ORDER BY is not decoration here: OFFSET without one lets MySQL return
			// rows in a different order between pages, which silently drops some rows from the export
			// and duplicates others. An incomplete GDPR export that looks complete is the worst
			// possible outcome of this function.
			$order = self::order_column( $table, $cols );

			foreach ( $cols['owned'] as $column ) {
				if ( $limit > 0 ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$sql = $wpdb->prepare(
						"SELECT * FROM `{$table}` WHERE `{$column}` = %d ORDER BY `{$order}` ASC LIMIT %d OFFSET %d",
						$user_id,
						$limit,
						$offset
					);
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$sql = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$column}` = %d ORDER BY `{$order}` ASC", $user_id );
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = (array) $wpdb->get_results( $sql, ARRAY_A );

				if ( $rows ) {
					$out[ $short ] = array_merge( $out[ $short ] ?? array(), $rows );
				}
			}
		}

		return $out;
	}

	/**
	 * A column that gives this table a stable order for OFFSET paging.
	 *
	 * `id` where the table has one, otherwise the column that ties the row to the member -- which
	 * every table in this map has by definition, since that is how it got into the map.
	 *
	 * @param string                                 $table Fully-qualified table name.
	 * @param array{owned: string[], refs: string[]} $cols  Owned / referencing columns.
	 * @return string Column name, safe to interpolate (it came from SHOW COLUMNS, not from input).
	 */
	private static function order_column( string $table, array $cols ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( in_array( 'id', $columns, true ) ) {
			return 'id';
		}

		return (string) ( $cols['owned'][0] ?? 'user_id' );
	}

	/**
	 * Rows belonging to members who no longer exist.
	 *
	 * @return array<string,int> Table (unprefixed) => orphaned row count.
	 */
	public static function count_orphans(): array {
		global $wpdb;

		$counts = array();

		foreach ( self::member_tables() as $table => $cols ) {
			$short = str_replace( $wpdb->prefix, '', $table );

			foreach ( $cols['owned'] as $column ) {
				$n = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM `{$table}` t
					  LEFT JOIN {$wpdb->users} u ON u.ID = t.`{$column}`
					  WHERE t.`{$column}` > 0 AND u.ID IS NULL"
				);

				if ( $n > 0 ) {
					$counts[ $short ] = ( $counts[ $short ] ?? 0 ) + $n;
				}
			}
		}

		return $counts;
	}

	/**
	 * Delete rows belonging to members who no longer exist.
	 *
	 * DELIBERATELY NOT AN UPGRADE MIGRATION. Silently deleting a site owner's data because they
	 * installed a patch is not a thing a plugin gets to do -- they run this when they choose to, and
	 * `--dry-run` shows them exactly what would go first.
	 *
	 * @return array<string,int> Table (unprefixed) => rows removed.
	 */
	public static function purge_orphans(): array {
		global $wpdb;

		$removed = array();

		foreach ( self::member_tables() as $table => $cols ) {
			$short = str_replace( $wpdb->prefix, '', $table );

			foreach ( $cols['owned'] as $column ) {
				$n = (int) $wpdb->query(
					"DELETE t FROM `{$table}` t
					  LEFT JOIN {$wpdb->users} u ON u.ID = t.`{$column}`
					  WHERE t.`{$column}` > 0 AND u.ID IS NULL"
				);

				if ( $n > 0 ) {
					$removed[ $short ] = ( $removed[ $short ] ?? 0 ) + $n;
				}
			}
		}

		if ( $removed ) {
			BadgeEngine::flush_rarity_cache();
			wp_cache_flush();
		}

		return $removed;
	}
}
