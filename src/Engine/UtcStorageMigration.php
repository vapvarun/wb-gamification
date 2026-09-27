<?php
/**
 * One-time conversion of stored timestamps to UTC (1.6.5, card 10344291999).
 *
 * Until 1.6.5 most moment columns held the SITE's wall clock (current_time( 'mysql' )), and a few
 * relied on the column DEFAULT, which is the DATABASE server's clock. From 1.6.5 every writer
 * stores UTC and every reader bounds in UTC (see Clock), so rows written before the upgrade are
 * converted once:
 *
 *   - site-clock columns: shifted by the site zone, per row, exact across DST
 *     (Clock::sql_local_to_utc). Skipped entirely on a site that is UTC.
 *   - database-default columns: shifted by the database clock's current offset. Skipped when the
 *     database runs UTC.
 *
 * Rows written after the upgrade are already UTC and are never touched: each table's highest
 * primary key at cutover is recorded, and only rows at or below it are converted. The work runs in
 * the background (Action Scheduler), newest rows first so today/week/month windows are right in the
 * first minutes, one transaction per chunk with the progress cursor committed alongside the UPDATE,
 * so a crash can never convert a chunk twice. `wp wb-gamification doctor --fix` drains it on the spot.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Converts pre-1.6.5 site-clock and database-clock timestamps to UTC.
 */
final class UtcStorageMigration {

	/** Set to '1' once every table is converted. */
	public const DONE_OPTION = 'wb_gam_feature_utc_storage_v1';

	/** Progress while the conversion runs (autoload off). */
	private const STATE_OPTION = 'wb_gam_utc_storage_state';

	/** Action Scheduler hook for one batch of chunks. */
	public const HOOK = 'wb_gam_utc_migrate_page';

	/** Seconds of work per batch, well inside a request / Action Scheduler time limit. */
	private const BATCH_SECONDS = 20;

	/**
	 * Register the batch handler and start the conversion if it has not finished.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
		self::ensure();
	}

	/**
	 * Start the conversion once, or make sure a stalled one has a job queued.
	 *
	 * @return void
	 */
	public static function ensure(): void {
		if ( '1' === get_option( self::DONE_OPTION ) ) {
			return;
		}
		if ( get_option( self::STATE_OPTION ) ) {
			self::queue();
			return;
		}
		Lock::run( 'utc_storage_cutover', array( __CLASS__, 'cutover' ) );
	}

	/**
	 * Whether every stored timestamp is UTC (used by doctor and tests; readers never branch on it).
	 *
	 * @phpstan-impure Reads an option the conversion updates.
	 * @return bool
	 */
	public static function is_done(): bool {
		return '1' === get_option( self::DONE_OPTION );
	}

	/**
	 * Remaining work per table, for `wp wb-gamification doctor`.
	 *
	 * @phpstan-impure Reads an option the conversion updates.
	 * @return array<string, int> Table => rows still to convert.
	 */
	public static function progress(): array {
		$state = get_option( self::STATE_OPTION );
		$left  = array();
		foreach ( is_array( $state ) ? $state['jobs'] : array() as $job ) {
			$left[ $job['table'] ] = max( 0, (int) $job['cursor'] - (int) $job['floor'] );
		}
		return $left;
	}

	/**
	 * Record the boundary, convert the small tables now, queue the rest.
	 *
	 * @return void
	 */
	public static function cutover(): void {
		if ( '1' === get_option( self::DONE_OPTION ) || get_option( self::STATE_OPTION ) ) {
			return;
		}

		global $wpdb;
		$now        = time();
		$site_local = ! Clock::is_utc_site( $now - 20 * YEAR_IN_SECONDS, $now );
		// @clock-ok: measuring the database clock's offset from UTC is the point here.
		$db_offset = (int) round( (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' ) / 900 ) * 900; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $site_local && 0 === $db_offset ) {
			update_option( self::DONE_OPTION, '1' );
			return;
		}

		// Small, database-clock tables: one statement each.
		if ( 0 !== $db_offset ) {
			foreach ( array( 'wb_gam_badge_defs', 'wb_gam_point_types', 'wb_gam_rules', 'wb_gam_webhooks', 'wb_gam_point_type_conversions', 'wb_gam_redemption_items' ) as $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table list; offset is an int.
				$wpdb->query( "UPDATE {$wpdb->prefix}{$table} SET created_at = DATE_SUB(created_at, INTERVAL {$db_offset} SECOND)" );
			}
		}

		// Site-clock usermeta: at most one row per member per key.
		if ( $site_local && is_main_site() ) {
			$expr = Clock::sql_local_to_utc( 'meta_value', $now - 20 * YEAR_IN_SECONDS, $now );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clock builds the expression from a guarded column name and integers.
			$wpdb->query( "UPDATE {$wpdb->usermeta} SET meta_value = DATE_FORMAT({$expr}, '%Y-%m-%d %H:%i:%s') WHERE meta_key IN ('wb_gam_decayed_at','wb_gam_last_retention_nudge') AND meta_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'" );
		}

		$jobs = array();
		foreach ( self::columns( $site_local, $db_offset ) as $table => $columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table list.
			$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}{$table}" );
			if ( $max > 0 ) {
				$jobs[] = array(
					'table'   => $table,
					'cursor'  => $max,
					'floor'   => 0,
					'columns' => $columns,
				);
			}
		}

		if ( ! $jobs ) {
			update_option( self::DONE_OPTION, '1' );
			return;
		}

		update_option(
			self::STATE_OPTION,
			array(
				'jobs'        => $jobs,
				'db_offset'   => $db_offset,
				'site_local'  => $site_local,
				'cutover'     => $now,
				// Set-once columns filled on an old row after the upgrade are already UTC; only values
				// written before the cutover (site clock) are converted.
				'cutover_str' => wp_date( 'Y-m-d H:i:s', $now ),
			),
			false
		);
		self::queue();
	}

	/**
	 * Convert chunks round-robin, newest first, for up to BATCH_SECONDS; queue the next batch.
	 *
	 * @return void
	 */
	public static function run_batch(): void {
		Lock::run( 'utc_storage_batch', array( __CLASS__, 'convert_batch' ) );
	}

	/**
	 * The batch body (under the lock).
	 *
	 * @return void
	 */
	public static function convert_batch(): void {
		global $wpdb;
		$started = time();
		$size    = max( 100, (int) apply_filters( 'wb_gam_utc_migration_page_size', 5000 ) );

		while ( time() - $started < self::BATCH_SECONDS ) {
			$state = get_option( self::STATE_OPTION );
			if ( ! is_array( $state ) || empty( $state['jobs'] ) ) {
				self::finish();
				return;
			}
			$moved = false;
			foreach ( $state['jobs'] as $i => $job ) {
				if ( $job['cursor'] <= $job['floor'] ) {
					continue;
				}
				$lower = max( $job['floor'], $job['cursor'] - $size );
				$set   = self::set_clause( $job['columns'], $state );

				$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed tables/columns; the SET clause is built from Clock and integers.
				$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}{$job['table']} t SET {$set} WHERE t.id > %d AND t.id <= %d" . self::skip_clause( $job['table'] ), $lower, $job['cursor'] ) );
				if ( false === $ok ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					Log::error(
						'UTC storage migration: chunk failed; retrying later.',
						array(
							'table' => $job['table'],
							'error' => $wpdb->last_error,
						)
					);
					self::queue( 60 );
					return;
				}
				$state['jobs'][ $i ]['cursor'] = $lower;
				update_option( self::STATE_OPTION, $state, false );
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$moved = true;
			}
			if ( ! $moved ) {
				self::finish();
				return;
			}
		}
		self::queue();
	}

	/**
	 * Columns to convert per table, and how.
	 *
	 * @param bool $site_local Whether the site zone is not UTC.
	 * @param int  $db_offset  Database clock offset from UTC, seconds.
	 * @return array<string, array<string, string>> table => [ column => 'site'|'site_once'|'db' ].
	 */
	private static function columns( bool $site_local, int $db_offset ): array {
		$site = $site_local ? array(
			'wb_gam_points'               => array( 'created_at' => 'site' ),
			'wb_gam_kudos'                => array(
				'created_at' => 'site',
				'revoked_at' => 'site_once',
			),
			'wb_gam_user_badges'          => array(
				'earned_at' => 'site',
				'shared_at' => 'site_once',
			),
			'wb_gam_challenge_log'        => array( 'completed_at' => 'site_once' ),
			'wb_gam_community_challenges' => array( 'completed_at' => 'site_once' ),
			'wb_gam_submissions'          => array( 'reviewed_at' => 'site_once' ),
		) : array();
		if ( 0 !== $db_offset ) {
			$site['wb_gam_submissions']['created_at'] = 'db';
		}
		return $site;
	}

	/**
	 * Rows a table must skip. Points imported before 1.6.4 were written in UTC already: their
	 * import event carries a source_key and the same timestamp as the ledger row.
	 *
	 * @param string $table Table (without prefix).
	 * @return string SQL fragment starting with " AND", or ''.
	 */
	private static function skip_clause( string $table ): string {
		global $wpdb;
		if ( 'wb_gam_points' !== $table ) {
			return '';
		}
		return " AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}wb_gam_events e WHERE e.id = t.event_id AND e.source_key IS NOT NULL AND e.created_at = t.created_at)";
	}

	/**
	 * The SET clause for one table.
	 *
	 * @param array<string, string> $columns Column => mode.
	 * @param array                 $state   Migration state.
	 * @return string
	 */
	private static function set_clause( array $columns, array $state ): string {
		global $wpdb;
		$from = (int) $state['cutover'] - 20 * YEAR_IN_SECONDS;
		$to   = (int) $state['cutover'];
		$sets = array();
		foreach ( $columns as $column => $mode ) {
			if ( 'db' === $mode ) {
				$sets[] = "{$column} = DATE_SUB({$column}, INTERVAL " . (int) $state['db_offset'] . ' SECOND)';
				continue;
			}
			$expr = Clock::sql_local_to_utc( $column, $from, $to );
			if ( 'site_once' === $mode ) {
				$sets[] = $wpdb->prepare( "{$column} = IF({$column} IS NOT NULL AND {$column} <= %s, {$expr}, {$column})", $state['cutover_str'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- guarded column + Clock expression.
				continue;
			}
			$sets[] = "{$column} = {$expr}";
		}
		return implode( ', ', $sets );
	}

	/**
	 * Mark the conversion complete and drop caches that hold old windows.
	 *
	 * @return void
	 */
	private static function finish(): void {
		global $wpdb;
		update_option( self::DONE_OPTION, '1' );
		delete_option( self::STATE_OPTION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wb\\_gam\\_analytics\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wb\\_gam\\_analytics\\_%'" );
		wp_cache_flush();
	}

	/**
	 * Queue the next batch once (deduplicated).
	 *
	 * @param int $delay Seconds to wait first.
	 * @return void
	 */
	private static function queue( int $delay = 0 ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK ) ) {
			return;
		}
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array(), 'wb-gamification' );
			return;
		}
		as_enqueue_async_action( self::HOOK, array(), 'wb-gamification' );
	}
}
