<?php
/**
 * WB Gamification - BuddyPress activity backfiller.
 *
 * Repairs gamification activity rows whose card is missing or broken:
 *   - rows from before the content card (empty content, legacy markup)
 *   - rows saved before 1.6.8 through BuddyPress's kses, which dropped the card's wrapper and
 *     classes (BuddyPress strips every class, BuddyBoss strips the divs)
 * and collapses every headline to the generic form ActivityCard::action_line() writes.
 *
 * Batched: run() queues the first batch in the background; each batch repairs BATCH rows by id
 * and queues the next, so a large activity table never runs in one request. Idempotent: rows that
 * already carry the card and the right headline are left alone.
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound - plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound - same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared - this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Batched data repairer for gamification activity rows.
 *
 * @package WB_Gamification
 */
final class Backfiller {

	/**
	 * Background hook that repairs one batch.
	 */
	public const HOOK = 'wb_gam_bp_activity_backfill';

	/**
	 * Rows per batch.
	 */
	private const BATCH = 200;

	/**
	 * Register the batch handler.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * Queue the repair from the first row. Safe to call from an upgrade routine on
	 * plugins_loaded: the job is queued once the scheduler is ready and runs in the background.
	 */
	public static function run(): void {
		self::queue( 0 );
	}

	/**
	 * Queue the batch after a row id. Action Scheduler first, WP-Cron when it is missing.
	 *
	 * @param int $after_id Last activity id already handled.
	 */
	private static function queue( int $after_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler is not ready before action_scheduler_init; a call before it is lost.
			if ( ! did_action( 'action_scheduler_init' ) ) {
				add_action(
					'action_scheduler_init',
					static function () use ( $after_id ) {
						self::queue( $after_id );
					}
				);
				return;
			}
			$args = array( $after_id );
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK, $args, 'wb-gamification' ) ) {
				as_enqueue_async_action( self::HOOK, $args, 'wb-gamification' );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK, array( $after_id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $after_id ) );
		}
	}

	/**
	 * Repair one batch of rows after $after_id, then queue the next batch.
	 *
	 * @param int $after_id Last activity id already handled.
	 */
	public static function run_batch( $after_id = 0 ): void {
		if ( ! function_exists( 'buddypress' ) || ! function_exists( 'bp_activity_update_meta_cache' ) || empty( buddypress()->activity->table_name ) ) {
			return;
		}

		global $wpdb;
		$table = buddypress()->activity->table_name;

		// Served by the component index plus the primary key; one bounded page.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, type, item_id, action, content, date_recorded FROM {$table}
				  WHERE component = %s AND id > %d
				  ORDER BY id ASC
				  LIMIT %d",
				ActivityCard::COMPONENT,
				(int) $after_id,
				self::BATCH
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		$ids = array_map( static fn( $row ) => (int) $row->id, $rows );
		cache_users( array_map( static fn( $row ) => (int) $row->user_id, $rows ) );
		bp_activity_update_meta_cache( $ids );

		foreach ( $rows as $row ) {
			$change = self::repair( $row );
			if ( $change ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, $change, array( 'id' => (int) $row->id ) );
				wp_cache_delete( (int) $row->id, 'bp_activity' );
			}
		}

		if ( count( $rows ) === self::BATCH ) {
			self::queue( (int) end( $ids ) );
		}
	}

	/**
	 * Work out the new action and content for one row (empty when it is already right).
	 *
	 * @param object $row Activity row (id, user_id, type, item_id, action, content, date_recorded).
	 * @return array<string, string>
	 */
	private static function repair( object $row ): array {
		$types = array(
			'badge_earned'        => 'badge',
			'level_changed'       => 'level',
			'kudos_given'         => 'kudos',
			'challenge_completed' => 'challenge',
		);
		if ( ! isset( $types[ $row->type ] ) ) {
			return array();
		}

		$change  = array();
		$content = (string) $row->content;
		$action  = ActivityCard::action_line( ActivityCard::user_link( (int) $row->user_id ), $types[ $row->type ] );

		if ( 'badge_earned' === $row->type ) {
			$ids = function_exists( 'bp_activity_get_meta' ) ? bp_activity_get_meta( (int) $row->id, BadgeStream::META_IDS, true ) : array();
			if ( is_array( $ids ) && $ids ) {
				// A grouped post: its badges are on record, so the card is redrawn exactly.
				$group  = BadgeStream::group_markup( (int) $row->user_id, array_map( 'strval', $ids ) );
				$action = $group['action'];
				if ( ! ActivityCard::has_card( $content ) ) {
					$change['content'] = $group['content'];
				}
			} elseif ( ! ActivityCard::has_card( $content ) ) {
				$card = self::badge_card( $row );
				if ( '' !== $card ) {
					$change['content'] = $card;
				}
			}
		} elseif ( ! ActivityCard::has_card( $content ) ) {
			$card = self::card( $row );
			if ( '' !== $card ) {
				$change['content'] = $card;
			}
		}

		if ( $action !== (string) $row->action ) {
			$change['action'] = $action;
		}

		return $change;
	}

	/**
	 * Card for a pre-1.6.8 badge row. Those rows never stored the badge id, so the badge is the
	 * member's award closest in time to the post (empty when the member has no badge left).
	 *
	 * @param object $row Activity row.
	 */
	private static function badge_card( object $row ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$badge_id = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT badge_id FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d
				  ORDER BY ABS(TIMESTAMPDIFF(SECOND, earned_at, %s)) ASC
				  LIMIT 1",
				(int) $row->user_id,
				$row->date_recorded
			)
		);
		if ( '' === $badge_id ) {
			return '';
		}

		return BadgeStream::group_markup( (int) $row->user_id, array( $badge_id ) )['content'];
	}

	/**
	 * Card for a level, kudos or challenge row, rebuilt from the record its item_id points at
	 * (empty when that record has since been deleted).
	 *
	 * @param object $row Activity row.
	 */
	private static function card( object $row ): string {
		global $wpdb;

		switch ( $row->type ) {
			case 'level_changed':
				$level = ActivityCard::lookup_level( (int) $row->item_id );
				if ( ! $level ) {
					return '';
				}
				$fields = LevelStream::fields( $level );
				$type   = 'level';
				break;

			case 'kudos_given':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$kudos = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT receiver_id, message FROM {$wpdb->prefix}wb_gam_kudos WHERE id = %d",
						(int) $row->item_id
					),
					ARRAY_A
				);
				if ( ! $kudos ) {
					return '';
				}
				$fields = KudosStream::fields( (int) $kudos['receiver_id'], (string) ( $kudos['message'] ?? '' ) );
				$type   = 'kudos';
				break;

			case 'challenge_completed':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$challenge = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, title, bonus_points FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
						(int) $row->item_id
					),
					ARRAY_A
				);
				if ( ! $challenge ) {
					return '';
				}
				$fields = ChallengeStream::fields( $challenge );
				$type   = 'challenge';
				break;

			default:
				return '';
		}

		return ActivityCard::render( $type, $fields['image_url'], $fields['title'], $fields['description'] );
	}
}
