<?php
/**
 * WB Gamification Kudos Engine
 *
 * Peer-to-peer recognition. Any member can give kudos to another member.
 *
 * Rules:
 *   - Cannot give kudos to yourself.
 *   - Daily send limit per giver (default: 5/day).
 *   - Points awarded: receiver gets `wb_gam_kudos_receiver_points` (default 5).
 *                     giver   gets `wb_gam_kudos_giver_points`    (default 2).
 *   - Both awards flow through Engine::process() — full pipeline:
 *     event log, badge evaluation, level-up, streaks, webhooks.
 *   - Fires `wb_gamification_kudos_given` after successful DB insert.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

use WP_Error;

final class KudosEngine {

	private const OPT_DAILY_LIMIT     = 'wb_gam_kudos_daily_limit';
	private const OPT_RECEIVER_POINTS = 'wb_gam_kudos_receiver_points';
	private const OPT_GIVER_POINTS    = 'wb_gam_kudos_giver_points';

	private const DEFAULT_DAILY_LIMIT     = 5;
	private const DEFAULT_RECEIVER_POINTS = 5;
	private const DEFAULT_GIVER_POINTS    = 2;

	// ── Public API ──────────────────────────────────────────────────────────────

	/**
	 * Give kudos from one member to another.
	 *
	 * @param int    $giver_id    User giving the kudos.
	 * @param int    $receiver_id User receiving the kudos.
	 * @param string $message     Optional short message (max 255 chars).
	 * @return true|WP_Error      True on success; WP_Error describing the rejection.
	 */
	public static function send( int $giver_id, int $receiver_id, string $message = '' ): true|WP_Error {
		if ( $giver_id === $receiver_id ) {
			return new WP_Error(
				'wb_gam_kudos_self',
				__( 'You cannot give kudos to yourself.', 'wb-gamification' )
			);
		}

		if ( $giver_id <= 0 || $receiver_id <= 0 ) {
			return new WP_Error(
				'wb_gam_kudos_invalid_user',
				__( 'Invalid user.', 'wb-gamification' )
			);
		}

		$daily_limit = (int) get_option( self::OPT_DAILY_LIMIT, self::DEFAULT_DAILY_LIMIT );
		if ( self::get_daily_sent_count( $giver_id ) >= $daily_limit ) {
			return new WP_Error(
				'wb_gam_kudos_limit',
				sprintf(
					/* translators: %d: daily kudos limit */
					__( 'You have reached your daily kudos limit (%d).', 'wb-gamification' ),
					$daily_limit
				)
			);
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_kudos',
			[
				'giver_id'    => $giver_id,
				'receiver_id' => $receiver_id,
				'message'     => mb_substr( $message, 0, 255 ),
				'created_at'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'wb_gam_kudos_db_error',
				__( 'Could not record kudos. Please try again.', 'wb-gamification' )
			);
		}

		$kudos_id = (int) $wpdb->insert_id;

		// Award points to receiver and giver via full Engine pipeline.
		$receiver_points = (int) get_option( self::OPT_RECEIVER_POINTS, self::DEFAULT_RECEIVER_POINTS );
		$giver_points    = (int) get_option( self::OPT_GIVER_POINTS, self::DEFAULT_GIVER_POINTS );

		if ( $receiver_points > 0 ) {
			Engine::process(
				new Event(
					[
						'action_id' => 'receive_kudos',
						'user_id'   => $receiver_id,
						'object_id' => $kudos_id,
						'metadata'  => [
							'points'   => $receiver_points,
							'giver_id' => $giver_id,
							'message'  => $message,
						],
					]
				)
			);
		}

		if ( $giver_points > 0 ) {
			Engine::process(
				new Event(
					[
						'action_id' => 'give_kudos',
						'user_id'   => $giver_id,
						'object_id' => $kudos_id,
						'metadata'  => [
							'points'      => $giver_points,
							'receiver_id' => $receiver_id,
						],
					]
				)
			);
		}

		/**
		 * Fires after a kudos is successfully recorded.
		 *
		 * @param int    $giver_id    User who gave the kudos.
		 * @param int    $receiver_id User who received the kudos.
		 * @param string $message     Optional kudos message.
		 * @param int    $kudos_id    DB row ID of the new kudos record.
		 */
		do_action( 'wb_gamification_kudos_given', $giver_id, $receiver_id, $message, $kudos_id );

		return true;
	}

	/**
	 * Count kudos sent by a user today (UTC day).
	 *
	 * @param int $giver_id User to check.
	 * @return int
	 */
	public static function get_daily_sent_count( int $giver_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos
				  WHERE giver_id = %d
				    AND created_at >= %s",
				$giver_id,
				gmdate( 'Y-m-d' ) . ' 00:00:00'
			)
		);
	}

	/**
	 * Total kudos received by a user, all-time.
	 *
	 * @param int $user_id User to query.
	 * @return int
	 */
	public static function get_received_count( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos WHERE receiver_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Fetch a recent kudos feed with display names.
	 *
	 * @param int $limit Maximum rows (1–50).
	 * @return array<int, array{id: int, giver_id: int, giver_name: string, receiver_id: int, receiver_name: string, message: string|null, created_at: string}>
	 */
	public static function get_recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.id, k.giver_id, g.display_name AS giver_name,
				        k.receiver_id, r.display_name AS receiver_name,
				        k.message, k.created_at
				   FROM {$wpdb->prefix}wb_gam_kudos k
				   JOIN {$wpdb->users} g ON g.ID = k.giver_id
				   JOIN {$wpdb->users} r ON r.ID = k.receiver_id
				  ORDER BY k.created_at DESC
				  LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		return array_map(
			static function ( array $row ): array {
				return [
					'id'            => (int) $row['id'],
					'giver_id'      => (int) $row['giver_id'],
					'giver_name'    => $row['giver_name'],
					'receiver_id'   => (int) $row['receiver_id'],
					'receiver_name' => $row['receiver_name'],
					'message'       => $row['message'] ?: null,
					'created_at'    => $row['created_at'],
				];
			},
			$rows
		);
	}
}
