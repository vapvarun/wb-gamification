<?php
/**
 * WB Gamification - reset all member progress.
 *
 * Wipes accumulated member data (points ledger, event log, earned badges,
 * streaks, kudos, league membership, challenge logs, contributions,
 * redemptions, submissions, leaderboard cache) and the per-user progress meta,
 * so a community can start fresh. CONFIGURATION and DEFINITIONS are preserved:
 * badge defs, levels, rules, challenge defs, point types/conversions, reward
 * items, member privacy prefs, webhooks, API keys, and all settings survive.
 *
 * Destructive and irreversible - the REST layer requires an explicit confirm.
 *
 * @package WB_Gamification
 * @since   1.5.3
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Bulk maintenance truncation of plugin-owned tables; the option API can't
// express TRUNCATE and there is no per-row caching to bust beyond the
// leaderboard cache (handled explicitly).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Truncates member-progress tables while preserving configuration.
 *
 * @package WB_Gamification
 */
final class ProgressReset {

	/**
	 * Member-progress / derived tables (suffixes) that are emptied on reset.
	 * Everything NOT listed here (defs, config, prefs) is preserved.
	 *
	 * @var string[]
	 */
	private const PROGRESS_TABLES = array(
		'wb_gam_events',
		'wb_gam_points',
		'wb_gam_user_totals',
		'wb_gam_user_badges',
		'wb_gam_streaks',
		'wb_gam_kudos',
		'wb_gam_leaderboard_cache',
		'wb_gam_challenge_log',
		'wb_gam_cohort_members',
		'wb_gam_community_challenge_contributions',
		'wb_gam_redemptions',
		'wb_gam_submissions',
	);

	/**
	 * Per-user progress meta keys cleared on reset (caches + streak state).
	 *
	 * This is DELIBERATELY a subset of `MemberData::USER_META_KEYS`, and it must stay one. A reset is
	 * not an erasure: the owner is zeroing everybody's score, not deleting people. So a member's
	 * privacy choice (`wb_gam_profile_public`), their exclusion from earning (`wb_gam_sandboxed`) and
	 * the admin's dismissed notices survive it -- taking those away would silently republish a member
	 * who had opted out, which is a far worse outcome than a stale streak.
	 *
	 * Do not "fix" the difference by unioning this with USER_META_KEYS. The difference is the feature.
	 *
	 * @var string[]
	 */
	private const PROGRESS_META = array(
		'wb_gam_login_streak',
		'wb_gam_login_streak_max',
		'wb_gam_login_last_award',
		'wb_gam_level_id',
		'wb_gam_level_name',
		'wb_gam_league_tier',
		'wb_gam_seen_first_earn_toast',
		// Both are downstream of the ledger this reset truncates: the note describes an award that no
		// longer exists, and the decay marker dates a balance that is now zero. Leaving them behind
		// left the next decay run reasoning from a timestamp for points nobody has.
		'_wb_gam_last_award_note',
		'wb_gam_decayed_at',
	);

	/**
	 * Per-user progress meta key PREFIXES cleared on reset.
	 *
	 * The three notification cursors used to be spelled out here as literals -- `..._footer`,
	 * `..._heartbeat`, `..._rest` -- which meant this list quietly stopped being complete the moment
	 * anyone added a fourth channel. The channel is part of the key; match on the prefix instead.
	 *
	 * @var string[]
	 */
	private const PROGRESS_META_PREFIXES = array(
		'wb_gam_notif_cursor_', // NotificationBridge::CURSOR_META_PREFIX.
		// Personal records: best and running totals for day, week and month (PersonalRecordEngine).
		// Only best_week was listed, so after a reset the old best day and month, and the running
		// totals, survived and the next award announced a "new personal record" against them.
		'wb_gam_pr_',
	);

	/**
	 * Empty all member-progress tables + clear progress meta. Returns the count
	 * of tables cleared and meta rows deleted.
	 *
	 * @return array{tables:int,meta_rows:int}
	 */
	public static function reset(): array {
		global $wpdb;

		$tables_cleared = 0;
		foreach ( self::PROGRESS_TABLES as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// Only act on tables that actually exist on this install.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				$wpdb->query( "TRUNCATE TABLE `{$table}`" );
				++$tables_cleared;
			}
		}

		// Reset any community-challenge aggregate progress counters while
		// keeping the challenge definitions intact.
		$cc_table = $wpdb->prefix . 'wb_gam_community_challenges';
		if ( $cc_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cc_table ) ) ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$cc_table}`" );
			if ( in_array( 'current_progress', (array) $columns, true ) ) {
				$wpdb->query( "UPDATE `{$cc_table}` SET current_progress = 0" );
			}
		}

		// Members whose cached meta must be dropped after the raw deletes below (a persistent object
		// cache would otherwise keep serving the old bests). Read only when the cache cannot drop the
		// whole user_meta group at once.
		$placeholders = implode( ',', array_fill( 0, count( self::PROGRESS_META ), '%s' ) );
		$meta_users   = array();
		if ( ! wp_cache_supports( 'flush_group' ) ) {
			$like       = array_map( static fn( string $prefix ): string => $wpdb->esc_like( $prefix ) . '%', self::PROGRESS_META_PREFIXES );
			$like_sql   = str_repeat( ' OR meta_key LIKE %s', count( $like ) );
			$meta_users = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders){$like_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from fixed-length constant lists.
					array_merge( self::PROGRESS_META, $like )
				)
			);
		}

		// Delete progress meta across all users in one statement.
		$meta_rows = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a fixed-length constant list.
				self::PROGRESS_META
			)
		);

		// And the prefix families, which have no literal to match on.
		foreach ( self::PROGRESS_META_PREFIXES as $prefix ) {
			$meta_rows += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb, value bound below.
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}

		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'user_meta' );
		} else {
			foreach ( $meta_users as $meta_user ) {
				wp_cache_delete( (int) $meta_user, 'user_meta' );
			}
		}

		// Drop the leaderboard snapshot caches so reads reflect the empty state.
		if ( class_exists( '\WBGam\Engine\LeaderboardEngine' ) ) {
			LeaderboardEngine::invalidate_cache();
		}

		/**
		 * Fires after all member progress has been reset. Adapters can clear
		 * their own derived state (e.g. transients) here.
		 *
		 * @since 1.5.3
		 */
		do_action( 'wb_gam_progress_reset' );

		return array(
			'tables'    => $tables_cleared,
			'meta_rows' => $meta_rows,
		);
	}
}
