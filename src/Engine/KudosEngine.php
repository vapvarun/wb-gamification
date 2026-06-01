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
 *   - Fires `wb_gam_kudos_given` after successful DB insert.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

use WP_Error;

/**
 * Peer-to-peer kudos recognition engine with daily send limits and point awards.
 *
 * @package WB_Gamification
 */
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
	public static function send( int $giver_id, int $receiver_id, string $message = '' ): bool|WP_Error {
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
				'wb_gam_kudos_cooldown',
				sprintf(
					/* translators: %d: daily kudos limit */
					__( 'You have reached your daily kudos limit (%d).', 'wb-gamification' ),
					$daily_limit
				)
			);
		}

		// Per-receiver cooldown — prevents a giver from spam-kudosing the same
		// receiver. Default 60 minutes; site owner can override via the
		// `wb_gam_kudos_per_receiver_cooldown_seconds` filter (return 0 to disable).
		$receiver_cooldown = (int) apply_filters(
			'wb_gam_kudos_per_receiver_cooldown_seconds',
			HOUR_IN_SECONDS,
			$giver_id,
			$receiver_id
		);
		if ( $receiver_cooldown > 0 && self::has_recent_kudos_to_receiver( $giver_id, $receiver_id, $receiver_cooldown ) ) {
			return new WP_Error(
				'wb_gam_kudos_cooldown',
				__( 'You recently gave kudos to this member. Try again later.', 'wb-gamification' )
			);
		}

		// Race-condition guard — atomic distributed lock via the object cache.
		// Two parallel POST /kudos with the same giver+receiver both pass the
		// has_recent_kudos_to_receiver check before either writes a row, so
		// both INSERTs succeed and bypass the cooldown. wp_cache_add() is
		// atomic across Redis/Memcached and returns false if the key exists.
		// Hold the lock for the cooldown window so concurrent attempts within
		// it are rejected at the lock layer, not the DB layer.
		$lock_key = sprintf( 'kudos_lock_%d_%d', $giver_id, $receiver_id );
		$lock_ttl = max( 60, $receiver_cooldown ); // Floor 60s for safety.
		if ( ! wp_cache_add( $lock_key, time(), 'wb_gamification', $lock_ttl ) ) {
			return new WP_Error(
				'wb_gam_kudos_cooldown',
				__( 'You recently gave kudos to this member. Try again later.', 'wb-gamification' )
			);
		}

		/**
		 * Filter whether kudos should be allowed.
		 *
		 * Return a WP_Error to reject the kudos with a custom message.
		 * Return true to allow.
		 *
		 * @since 1.0.0
		 * @param true|WP_Error $result      Default true (allow).
		 * @param int           $giver_id    User sending kudos.
		 * @param int           $receiver_id User receiving kudos.
		 * @param string        $message     Optional kudos message.
		 */
		$gate = apply_filters( 'wb_gam_before_kudos', true, $giver_id, $receiver_id, $message );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_kudos',
			array(
				'giver_id'    => $giver_id,
				'receiver_id' => $receiver_id,
				'message'     => mb_substr( $message, 0, 255 ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
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
					array(
						'action_id' => 'receive_kudos',
						'user_id'   => $receiver_id,
						'object_id' => $kudos_id,
						'metadata'  => array(
							'points'   => $receiver_points,
							'giver_id' => $giver_id,
							'message'  => $message,
						),
					)
				)
			);
		}

		if ( $giver_points > 0 ) {
			Engine::process(
				new Event(
					array(
						'action_id' => 'give_kudos',
						'user_id'   => $giver_id,
						'object_id' => $kudos_id,
						'metadata'  => array(
							'points'      => $giver_points,
							'receiver_id' => $receiver_id,
						),
					)
				)
			);
		}

		/**
		 * Fires after a kudos is successfully recorded.
		 *
		 * Pre-1.0.0 this hook also fired a 3-arg variant immediately after
		 * the 4-arg one. That broke listeners registered with `accepted_args=4`
		 * (PHP TypeError: missing $kudos_id) on every kudos send. The 3-arg
		 * fire was redundant — the kudos_id is always available — so it was
		 * removed in 1.0.0. All listeners now receive 4 args reliably.
		 *
		 * @param int    $giver_id    User who gave the kudos.
		 * @param int    $receiver_id User who received the kudos.
		 * @param string $message     Optional kudos message.
		 * @param int    $kudos_id    DB row ID of the new kudos record.
		 */
		do_action( 'wb_gam_kudos_given', $giver_id, $receiver_id, $message, $kudos_id );

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
	 * Has this giver kudos'd this receiver within the cooldown window?
	 *
	 * Used by the per-receiver cooldown gate in send(). Anti-spam — keeps
	 * a giver from rapid-firing kudos to the same person. Window is in
	 * seconds, filterable via `wb_gam_kudos_per_receiver_cooldown_seconds`.
	 *
	 * @param int $giver_id           User who is sending.
	 * @param int $receiver_id        User they want to send to.
	 * @param int $cooldown_seconds   Time window to check.
	 */
	public static function has_recent_kudos_to_receiver( int $giver_id, int $receiver_id, int $cooldown_seconds ): bool {
		if ( $cooldown_seconds <= 0 ) {
			return false;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos
				  WHERE giver_id = %d
				    AND receiver_id = %d
				    AND created_at >= %s",
				$giver_id,
				$receiver_id,
				gmdate( 'Y-m-d H:i:s', time() - $cooldown_seconds )
			)
		);
		return $count > 0;
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
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'            => (int) $row['id'],
					'giver_id'      => (int) $row['giver_id'],
					'giver_name'    => $row['giver_name'],
					'receiver_id'   => (int) $row['receiver_id'],
					'receiver_name' => $row['receiver_name'],
					'message'       => $row['message'] ?: null,
					'created_at'    => $row['created_at'],
				);
			},
			$rows
		);
	}
}
