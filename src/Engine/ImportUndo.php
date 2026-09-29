<?php
/**
 * WB Gamification: take an import back out.
 *
 * The owner ran an import, looked at the result, and it is wrong (the wrong source, a bad mapping, a
 * test run on the live site). Undo removes what that import left, in bounded pages driven by the same
 * ImportRunner that ran it, and leaves everything else alone.
 *
 * Why this deletes rows although the event log is "immutable": the plugin already removes ledger rows
 * for retention (LogPruner), for a member's erasure (MemberData) and for a progress reset. Undo is the
 * fourth, and it is the owner's explicit, confirmed, named action on data they chose to import. The
 * alternative, compensating events, would make a re-import a permanent no-op (the source_key is unique)
 * so the owner could never fix the mapping and run again, and it would double the ledger's size.
 *
 * The totals are corrected by an EXACT inverse, never by recomputing them from the ledger. Every
 * imported event kept, in its metadata, the delta it added to a member's `total` and `earned`
 * (ImportService stores it, and LogPruner never prunes an event that carries a source_key), so undo
 * subtracts precisely that. Recomputing `total = SUM(points)` instead would re-introduce the legacy bug
 * where a member's balance shrank whenever LogPruner had removed old points rows: an import of years of
 * history has, by the next daily prune, no points rows left for anything older than the retention
 * horizon, while the totals still count all of it.
 *
 * What undo does NOT reverse, and says so: badges and points a member earned BECAUSE of the imported
 * history (a level-reached badge and its bonus points, a milestone badge) are ordinary awards with no
 * source_key. They stay, exactly as they would had the member earned them.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Engine;

use WBGam\Integrations\Importers\ImportSource;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the events, points, badges and levels an import created.
 *
 * @package WB_Gamification
 */
final class ImportUndo {

	/**
	 * How many members with a negative balance to remember by id (the count is exact up to this).
	 */
	private const NEGATIVE_MAX = 200;

	/**
	 * What an undo would remove, without removing anything.
	 *
	 * Counts are index range counts on the key prefixes; nothing here reads the source plugin, so an
	 * import can be undone after its source has been deactivated or deleted.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview( string $slug ) {
		$class = self::prefix_class( $slug );
		if ( $class instanceof WP_Error ) {
			return $class;
		}

		$state = ImportRunner::state( $slug );

		return array(
			'source'  => $slug,
			'dry_run' => true,
			'events'  => ImportLedger::count_events( $class::KEY_PREFIX ),
			'badges'  => ImportLedger::count_badges( $class::BADGE_PREFIX ),
			'levels'  => self::existing_levels( (array) $state['created_level_ids'] ),
		);
	}

	/**
	 * Remove one page of the current undo phase and move the state forward.
	 *
	 * Phases run levels, then events, then badges. Levels go first so that the level each member is
	 * resynced to while their events are removed is the FINAL level, not one that is about to be deleted.
	 *
	 * @param string               $class Importer class (only its prefixes are used).
	 * @param array<string, mixed> $state Run state (updated in place).
	 * @return void
	 */
	public static function step( string $class, array &$state ): void {
		global $wpdb;

		switch ( $state['phase'] ) {
			case 'undo_levels':
				$ids = self::existing_levels( (array) $state['created_level_ids'] );
				if ( $ids ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wb_gam_levels WHERE id IN ({$placeholders})", $ids ) );
					LevelEngine::invalidate_cache();
				}
				$state['undone']['levels']  = count( $ids );
				$state['created_level_ids'] = array();
				self::enter( $state, 'undo_events', ImportLedger::count_events( $class::KEY_PREFIX ) );
				return;

			case 'undo_events':
				$removed              = self::remove_events( $class::KEY_PREFIX, ImportRunner::page_size( 'undo' ), $state );
				$state['phase_done'] += $removed;
				if ( 0 === $removed ) {
					self::enter( $state, 'undo_badges', ImportLedger::count_badges( $class::BADGE_PREFIX ) );
				}
				return;

			case 'undo_badges':
				$limit = ImportRunner::page_size( 'undo' );
				$like  = ImportLedger::like( $class::BADGE_PREFIX );

				// Select the page first: the members whose awards go must have their cached earned-badge
				// list cleared too. Deleting by LIMIT alone would leave every one of them cached as still
				// holding the badge, and on a site with a persistent object cache a re-import would then
				// award nothing (BadgeEngine::award_badge() trusts that list).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$awards = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, user_id FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id LIKE %s ORDER BY id ASC LIMIT %d",
						$like,
						$limit
					),
					ARRAY_A
				);

				if ( $awards ) {
					$ids          = array_map( 'intval', wp_list_pluck( $awards, 'id' ) );
					$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$gone = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wb_gam_user_badges WHERE id IN ({$placeholders})", $ids ) );

					foreach ( array_unique( array_map( 'intval', wp_list_pluck( $awards, 'user_id' ) ) ) as $user_id ) {
						wp_cache_delete( "wb_gam_earned_badges_{$user_id}", 'wb_gamification' );
					}
					BadgeEngine::flush_rarity_cache();

					$state['undone']['badges'] += $gone;
					$state['phase_done']       += $gone;
				}

				if ( count( $awards ) < $limit ) {
					// The definitions go last, once nobody holds them.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id LIKE %s", ImportLedger::like( $class::BADGE_PREFIX ) ) );
					LeaderboardEngine::invalidate_cache();
					$state['phase']       = 'done';
					$state['status']      = 'undone';
					$state['finished_at'] = gmdate( 'Y-m-d H:i:s' );
				}
				return;
		}

		$state['status'] = 'failed';
		$state['error']  = sprintf( 'Unknown undo phase "%s".', (string) $state['phase'] );
	}

	/**
	 * What each imported event added to a member's balance, per member and currency.
	 *
	 * Pure (no database) so it can be tested. The delta comes from the event's own metadata, the copy
	 * ImportService wrote, and a row flagged `_spend` never moved `earned` (the same rule
	 * PointsEngine::bump_user_total() applied when the row was written).
	 *
	 * @param array<int, array{id: string, user_id: int|string, point_type: string, metadata: string}> $events Event rows.
	 * @return array{by_member: array<string, array{user_id: int, point_type: string, total: int, earned: int}>, unrecoverable: int}
	 */
	public static function tally( array $events ): array {
		$by_member     = array();
		$unrecoverable = 0;

		foreach ( $events as $event ) {
			$meta = json_decode( (string) $event['metadata'], true );
			if ( ! is_array( $meta ) || ! isset( $meta['points'] ) || ! is_numeric( $meta['points'] ) ) {
				// Nothing to subtract: the event never carried its delta. Counted, never guessed at.
				++$unrecoverable;
				continue;
			}

			$delta = (int) $meta['points'];
			$type  = '' !== (string) $event['point_type'] ? (string) $event['point_type'] : 'points';
			$key   = (int) $event['user_id'] . '|' . $type;

			if ( ! isset( $by_member[ $key ] ) ) {
				$by_member[ $key ] = array(
					'user_id'    => (int) $event['user_id'],
					'point_type' => $type,
					'total'      => 0,
					'earned'     => 0,
				);
			}
			$by_member[ $key ]['total']  += $delta;
			$by_member[ $key ]['earned'] += empty( $meta['_spend'] ) ? $delta : 0;
		}

		return array(
			'by_member'     => $by_member,
			'unrecoverable' => $unrecoverable,
		);
	}

	/**
	 * Remove one page of imported events, take their contribution out of the totals, and delete the
	 * points rows that still exist. All of it in ONE transaction: a page either fully applies or does
	 * not, so a crash can never leave a member's total corrected for events that are still there.
	 *
	 * The page is simply "the first N events still carrying this prefix": deleted rows drop out of the
	 * next read, so no cursor is needed and a resumed run picks up exactly where the delete stopped.
	 *
	 * @param string               $key_prefix Event key prefix.
	 * @param int                  $limit      Events per page.
	 * @param array<string, mixed> $state      Run state (undone counters updated in place).
	 * @return int Events removed (0 when none are left).
	 * @throws \RuntimeException When the page's transaction was rolled back.
	 */
	private static function remove_events( string $key_prefix, int $limit, array &$state ): int {
		global $wpdb;

		$removed = Transaction::run(
			static function () use ( $wpdb, $key_prefix, $limit, &$state ): int {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$events = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, user_id, point_type, metadata
						   FROM {$wpdb->prefix}wb_gam_events
						  WHERE source_key LIKE %s
						  ORDER BY source_key ASC
						  LIMIT %d",
						ImportLedger::like( $key_prefix ),
						$limit
					),
					ARRAY_A
				);
				if ( ! $events ) {
					return 0;
				}

				$tally = self::tally( $events );
				$users = array();
				foreach ( $tally['by_member'] as $member ) {
					// UPDATE, not upsert: a member whose totals were erased must not get a row created here.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->prefix}wb_gam_user_totals
							    SET total = total - %d, earned = earned - %d
							  WHERE user_id = %d AND point_type = %s",
							$member['total'],
							$member['earned'],
							$member['user_id'],
							$member['point_type']
						)
					);
					$users[ $member['user_id'] ] = true;
				}

				$ids          = wp_list_pluck( $events, 'id' );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$state['undone']['points_rows'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wb_gam_points WHERE event_id IN ({$placeholders})", $ids ) );
				$deleted                         = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wb_gam_events WHERE id IN ({$placeholders})", $ids ) );
				// phpcs:enable

				$state['undone']['events']        += $deleted;
				$state['undone']['unrecoverable'] += $tally['unrecoverable'];

				self::after_totals_changed( array_keys( $users ), $tally['by_member'], $state );

				return $deleted;
			}
		);

		// null is a rolled-back transaction. Reading it as "no events left" would skip on to the next
		// phase with events still in place, so it is a failure and the page runs again on resume.
		if ( null === $removed ) {
			throw new \RuntimeException( 'Removing a page of imported events was rolled back.' );
		}

		if ( $removed > 0 ) {
			LeaderboardEngine::invalidate_cache();
		}
		return (int) $removed;
	}

	/**
	 * Make everything derived from a member's totals agree with them again.
	 *
	 * Cached balances are dropped, the stored level is set to what the corrected points earn (quietly:
	 * the hooks that announce a level are not fired, this is not the member's news), and a member
	 * left with a negative balance (they spent points that were imported) is remembered so the owner
	 * is told rather than left to find it.
	 *
	 * @param int[]                                                                           $user_ids  Members whose totals changed.
	 * @param array<string, array{user_id: int, point_type: string, total: int, earned: int}> $by_member Their per-currency deltas.
	 * @param array<string, mixed>                                                            $state     Run state.
	 * @return void
	 */
	private static function after_totals_changed( array $user_ids, array $by_member, array &$state ): void {
		global $wpdb;

		foreach ( $by_member as $member ) {
			wp_cache_delete( PointsEngine::cache_key_total( $member['user_id'], $member['point_type'] ), 'wb_gamification' );
			wp_cache_delete( PointsEngine::cache_key_earned( $member['user_id'], $member['point_type'] ), 'wb_gamification' );
		}

		foreach ( $user_ids as $user_id ) {
			$level = LevelEngine::get_level_for_points( PointsEngine::get_earned( $user_id, null ) );
			if ( $level ) {
				update_user_meta( $user_id, 'wb_gam_level_id', $level['id'] );
				update_user_meta( $user_id, 'wb_gam_level_name', $level['name'] );
			} else {
				delete_user_meta( $user_id, 'wb_gam_level_id' );
				delete_user_meta( $user_id, 'wb_gam_level_name' );
			}
		}

		if ( ! $user_ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$negative = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->prefix}wb_gam_user_totals WHERE total < 0 AND user_id IN ({$placeholders})", $user_ids ) );
		foreach ( $negative as $user_id ) {
			if ( count( $state['undone']['negative'] ) < self::NEGATIVE_MAX && ! in_array( (int) $user_id, $state['undone']['negative'], true ) ) {
				$state['undone']['negative'][] = (int) $user_id;
			}
		}
	}

	/**
	 * Which of the levels an import created still exist.
	 *
	 * @param int[] $ids Level ids recorded by the import.
	 * @return int[]
	 */
	private static function existing_levels( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wb_gam_levels WHERE id IN ({$placeholders})", $ids ) ) );
	}

	/**
	 * Move a state to a phase with a fresh counter.
	 *
	 * @param array<string, mixed> $state Run state.
	 * @param string               $phase Phase.
	 * @param int                  $total Rows the phase will cover.
	 * @return void
	 */
	private static function enter( array &$state, string $phase, int $total ): void {
		$state['phase']       = $phase;
		$state['cursor']      = 0;
		$state['phase_total'] = $total;
		$state['phase_done']  = 0;
	}

	/**
	 * Resolve a source to its importer class for its prefixes only.
	 *
	 * Undo never needs the source plugin, so it does not check that it is installed; it does refuse a
	 * source without prefixes, because an empty prefix would match every event on the site.
	 *
	 * @param string $slug Source slug.
	 * @return class-string<ImportSource>|WP_Error
	 */
	public static function prefix_class( string $slug ) {
		$class = ImportRunner::source_class( $slug );
		if ( null === $class ) {
			return new WP_Error( 'wb_gam_unknown_source', __( 'Unknown import source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		if ( '' === $class::KEY_PREFIX || '' === $class::BADGE_PREFIX ) {
			return new WP_Error( 'wb_gam_source_misconfigured', __( 'This import source is missing its key prefix.', 'wb-gamification' ), array( 'status' => 500 ) );
		}
		return $class;
	}
}
