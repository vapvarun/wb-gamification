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
	 * These are not schema-discoverable (WordPress owns the table), so they are the one list here --
	 * and they are covered by the same test, which greps the source for `wb_gam_*` meta keys and fails
	 * if one is missing.
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
	);

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

		foreach ( self::USER_META_KEYS as $meta_key ) {
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
	 * @param int $user_id Member.
	 * @return array<string, array<int, array<string,mixed>>> Table (unprefixed) => rows.
	 */
	public static function export_rows( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$out = array();

		foreach ( self::member_tables() as $table => $cols ) {
			$short = str_replace( $wpdb->prefix, '', $table );

			foreach ( $cols['owned'] as $column ) {
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$column}` = %d", $user_id ),
					ARRAY_A
				);

				if ( $rows ) {
					$out[ $short ] = array_merge( $out[ $short ] ?? array(), $rows );
				}
			}
		}

		return $out;
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
