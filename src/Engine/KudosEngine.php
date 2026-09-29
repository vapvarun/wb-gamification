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
 * Peer-to-peer kudos recognition engine: kudos always send; a daily limit and a per-receiver cooldown decide only whether they earn points.
 *
 * @package WB_Gamification
 */
final class KudosEngine {

	private const OPT_DAILY_LIMIT     = 'wb_gam_kudos_daily_limit';
	private const OPT_RECEIVER_POINTS = 'wb_gam_kudos_receiver_points';
	private const OPT_GIVER_POINTS    = 'wb_gam_kudos_giver_points';

	private const DEFAULT_DAILY_LIMIT     = 5;
	private const DEFAULT_DAILY_CEILING   = 50;
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
		// Off means off for every caller: BuddyNext and other partners call this engine directly,
		// not through the REST routes the Modules switch guards.
		if ( ! ModuleToggles::enabled( 'kudos' ) ) {
			return ModuleToggles::disabled_error();
		}

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

		// Appreciation is never refused; only points are limited. The daily limit and the
		// per-receiver cooldown decide whether THIS kudos earns points, silently - a member
		// who thanks a sixth person today is not told "no" after writing the message. The one
		// hard stop is a spam ceiling no ordinary member reaches, and the forms hide
		// themselves before it (see can_send()), so a member never sees that refusal either.
		if ( ! self::can_send( $giver_id ) ) {
			return self::ceiling_error();
		}

		// Serialize concurrent sends for this GIVER (all receivers), not just the
		// (giver, receiver) pair.
		//
		// This was a wp_cache_add() lock, justified in its own comment as "atomic across
		// Redis/Memcached". True -- and irrelevant without a persistent object cache, which the
		// default WordPress install does not have. There, wp_cache_add() is a process-local array
		// giving zero exclusion between workers, and the race it was written to close reproduced
		// every time on the most common configuration there is.
		//
		// The lock is GIVER-scoped (kudos_giver_{id}), not per-pair: the per-pair lock let N
		// parallel sends to N DIFFERENT receivers each take a different lock and all pass the
		// per-giver daily check, awarding points N times -- a points-inflation bypass. A
		// giver-scoped lock serializes all of a giver's in-flight sends so the points decision
		// inside is authoritative.
		//
		// Timeout 0: if another request is mid-send for this giver right now, that IS the race, so
		// reject rather than queue behind it. The forms disable the button while sending, so a
		// member does not reach this.
		return Lock::run(
			sprintf( 'kudos_giver_%d', $giver_id ),
			function () use ( $giver_id, $receiver_id, $message ) {
				// Decide inside the lock: only one of this giver's sends is in here at a time.
				if ( ! self::can_send( $giver_id ) ) {
					return self::ceiling_error();
				}

				return self::record_kudos( $giver_id, $receiver_id, $message, self::earns_points( $giver_id, $receiver_id ) );
			},
			new WP_Error(
				'wb_gam_kudos_busy',
				__( 'You are sending kudos too quickly. Please try again in a moment.', 'wb-gamification' )
			)
		);
	}

	/**
	 * Whether this member can send a kudos right now: the Kudos module is on and the member
	 * is below the daily spam ceiling.
	 *
	 * Forms call this before drawing themselves and render nothing when it is false, so
	 * the ceiling is never a notice a member reads after writing a message.
	 *
	 * @since 1.6.5
	 *
	 * @param int $giver_id Member who would send.
	 * @return bool
	 */
	public static function can_send( int $giver_id ): bool {
		return $giver_id > 0 && ModuleToggles::enabled( 'kudos' ) && self::get_daily_sent_count( $giver_id ) < self::daily_ceiling( $giver_id );
	}

	/**
	 * Whether a kudos from $giver_id to $receiver_id, sent now, would earn points.
	 *
	 * Points stop after the daily points limit (Settings, default 5) and for a repeat to the
	 * same receiver inside the cooldown window, so kudos cannot be traded to farm points.
	 *
	 * @since 1.6.5
	 *
	 * @param int $giver_id    Sender.
	 * @param int $receiver_id Recipient.
	 * @return bool
	 */
	public static function earns_points( int $giver_id, int $receiver_id ): bool {
		if ( self::get_daily_sent_count( $giver_id ) >= self::daily_points_limit() ) {
			return false;
		}

		/**
		 * Seconds after a kudos to the same receiver during which a repeat earns no points.
		 *
		 * Since 1.6.5 this no longer blocks the kudos; it only withholds the points. Return 0
		 * to let every repeat earn points.
		 *
		 * @since 1.0.0
		 * @param int $seconds     Default one hour.
		 * @param int $giver_id    Sender.
		 * @param int $receiver_id Recipient.
		 */
		$cooldown = (int) apply_filters( 'wb_gam_kudos_per_receiver_cooldown_seconds', HOUR_IN_SECONDS, $giver_id, $receiver_id );

		return ! ( $cooldown > 0 && self::has_recent_kudos_to_receiver( $giver_id, $receiver_id, $cooldown ) );
	}

	/**
	 * Kudos a member can send today that still earn points.
	 *
	 * @since 1.6.5
	 *
	 * @param int $giver_id Sender.
	 * @return int
	 */
	public static function points_kudos_remaining( int $giver_id ): int {
		return max( 0, self::daily_points_limit() - self::get_daily_sent_count( $giver_id ) );
	}

	/**
	 * Kudos per day that earn points (the "daily limit" setting).
	 *
	 * @return int
	 */
	private static function daily_points_limit(): int {
		return (int) get_option( self::OPT_DAILY_LIMIT, self::DEFAULT_DAILY_LIMIT );
	}

	/**
	 * Hard daily ceiling on kudos sent, points or not: a spam brake only.
	 *
	 * @param int $giver_id Sender.
	 * @return int
	 */
	private static function daily_ceiling( int $giver_id ): int {
		/**
		 * Most kudos a member can send in a day, including ones that earn no points.
		 *
		 * A spam brake, set well above what an ordinary member sends. Forms hide
		 * themselves once it is reached.
		 *
		 * @since 1.6.5
		 * @param int $ceiling  Default 50.
		 * @param int $giver_id Sender.
		 */
		return max( 1, (int) apply_filters( 'wb_gam_kudos_daily_ceiling', self::DEFAULT_DAILY_CEILING, $giver_id ) );
	}

	/**
	 * The error for a send past the daily ceiling (API callers only; forms hide first).
	 *
	 * @return WP_Error
	 */
	private static function ceiling_error(): WP_Error {
		return new WP_Error(
			'wb_gam_kudos_daily_ceiling',
			__( 'You have sent the most kudos allowed for today.', 'wb-gamification' )
		);
	}

	/**
	 * Write the kudos row and fan out its side effects.
	 *
	 * Split out of send() so the per-pair lock has a single, obvious scope. The caller
	 * MUST hold that lock: this method does no cooldown checking of its own.
	 *
	 * @param int    $giver_id    User sending kudos.
	 * @param int    $receiver_id User receiving kudos.
	 * @param string $message     Optional kudos message.
	 * @param bool   $earn_points Whether this kudos awards points (see earns_points()).
	 * @return true|WP_Error True on success, WP_Error if a gate rejected it or the write failed.
	 */
	private static function record_kudos( int $giver_id, int $receiver_id, string $message, bool $earn_points = true ) {
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
				'created_at'  => current_time( 'mysql', true ),
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

		// Award points to receiver and giver via full Engine pipeline -- unless this kudos is
		// past the daily points limit or a repeat inside the cooldown. The kudos itself, and
		// its notification, still go through.
		$receiver_points = $earn_points ? (int) get_option( self::OPT_RECEIVER_POINTS, self::DEFAULT_RECEIVER_POINTS ) : 0;
		$giver_points    = $earn_points ? (int) get_option( self::OPT_GIVER_POINTS, self::DEFAULT_GIVER_POINTS ) : 0;

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
	 * Count kudos sent by a user today (the site's calendar day).
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
				// created_at is UTC; "today" starts at the site's midnight, as a UTC instant.
				Clock::site_day_start( 'today' )
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
				// created_at is UTC, so the cooldown bound is a UTC instant too.
				Clock::site_cutoff( "-{$cooldown_seconds} seconds" )
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
	/**
	 * Recent kudos the current viewer may see: entries naming a member whose profile the
	 * viewer cannot see (Privacy::can_view_public_profile) are left out.
	 *
	 * @since 1.6.5
	 *
	 * @param int $limit Max entries (1-50).
	 * @return array
	 */
	public static function get_recent_visible( int $limit = 20 ): array {
		$limit = max( 1, min( 50, $limit ) );
		// Over-fetch so hidden entries do not leave the feed short.
		$rows = array_filter(
			self::get_recent( min( 50, $limit * 2 ) ),
			static fn( array $k ): bool => Privacy::can_view_public_profile( (int) $k['giver_id'] )
				&& Privacy::can_view_public_profile( (int) $k['receiver_id'] )
		);
		return array_slice( array_values( $rows ), 0, $limit );
	}

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
				  WHERE k.revoked_at IS NULL
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

	/**
	 * Recent kudos RECEIVED by a specific member, newest first.
	 *
	 * Mirrors get_recent() but scoped to one receiver, so a profile can show the
	 * kudos a member has been given without pulling the global feed. Revoked rows
	 * are excluded.
	 *
	 * @param int $user_id Receiver user ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return array<int,array{id:int,giver_id:int,giver_name:string,receiver_id:int,receiver_name:string,message:?string,created_at:string}>
	 */
	public static function get_received( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$user_id = max( 0, $user_id );
		if ( 0 === $user_id ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.id, k.giver_id, g.display_name AS giver_name,
				        k.receiver_id, r.display_name AS receiver_name,
				        k.message, k.created_at
				   FROM {$wpdb->prefix}wb_gam_kudos k
				   JOIN {$wpdb->users} g ON g.ID = k.giver_id
				   JOIN {$wpdb->users} r ON r.ID = k.receiver_id
				  WHERE k.revoked_at IS NULL AND k.receiver_id = %d
				  ORDER BY k.created_at DESC
				  LIMIT %d",
				$user_id,
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

	// ── Admin moderation API ──────────────────────────────────────────────────────

	/**
	 * Revoke a kudos: reverse BOTH point awards and soft-mark the row.
	 *
	 * A compound reversal — the giver and receiver each received points at send
	 * time, so revoking debits BOTH by the EXACT amount they were awarded for
	 * this kudos (looked up from wb_gam_points by object_id, not the current
	 * option value, which may have changed). The row is kept (revoked_at set)
	 * for the audit trail. Each debit is audited via PointsEngine::debit
	 * (wb_gam_events), and wb_gam_kudos_revoked fires so the notification bridge
	 * / webhooks can react. Idempotent: a second revoke is rejected.
	 *
	 * @param int    $kudos_id Kudos row ID.
	 * @param string $reason   Free-text audit reason.
	 * @param int    $admin_id Acting admin user ID (0 = system/CLI).
	 * @return array{ kudos_id:int, giver_id:int, receiver_id:int, giver_debited:int, receiver_debited:int }|WP_Error
	 */
	public static function revoke( int $kudos_id, string $reason, int $admin_id = 0 ): array|WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_kudos';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, giver_id, receiver_id, revoked_at FROM {$table} WHERE id = %d", $kudos_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'wb_gam_kudos_not_found', __( 'Kudos not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $row['revoked_at'] ) ) {
			return new WP_Error( 'wb_gam_kudos_already_revoked', __( 'This kudos is already revoked.', 'wb-gamification' ), array( 'status' => 409 ) );
		}

		$giver_id    = (int) $row['giver_id'];
		$receiver_id = (int) $row['receiver_id'];

		// Exact amounts awarded FOR THIS KUDOS (object_id = kudos_id).
		$giver_pts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points WHERE object_id = %d AND action_id = 'give_kudos' AND user_id = %d",
				$kudos_id,
				$giver_id
			)
		);
		$recv_pts  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points WHERE object_id = %d AND action_id = 'receive_kudos' AND user_id = %d",
				$kudos_id,
				$receiver_id
			)
		);

		// Soft-revoke the row (kept for audit).
		$wpdb->update( $table, array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => $kudos_id ), array( '%s' ), array( '%d' ) );

		// Compensating debits — each audited to wb_gam_events with the reason.
		if ( $recv_pts > 0 ) {
			PointsEngine::debit( $receiver_id, $recv_pts, 'kudos_revoked', self::revoke_event( 'kudos_revoked', $receiver_id, $kudos_id, $reason, $admin_id, 'receiver', $recv_pts ) );
		}
		if ( $giver_pts > 0 ) {
			PointsEngine::debit( $giver_id, $giver_pts, 'kudos_revoked', self::revoke_event( 'kudos_revoked', $giver_id, $kudos_id, $reason, $admin_id, 'giver', $giver_pts ) );
		}

		/**
		 * Fires after an admin revokes a kudos.
		 *
		 * @since 1.6.2
		 * @param int    $kudos_id    Revoked kudos row ID.
		 * @param int    $giver_id    User who gave the kudos.
		 * @param int    $receiver_id User who received the kudos.
		 * @param string $reason      Audit reason.
		 * @param int    $admin_id    Acting admin user ID.
		 */
		do_action( 'wb_gam_kudos_revoked', $kudos_id, $giver_id, $receiver_id, $reason, $admin_id );

		return array(
			'kudos_id'         => $kudos_id,
			'giver_id'         => $giver_id,
			'receiver_id'      => $receiver_id,
			'giver_debited'    => $giver_pts,
			'receiver_debited' => $recv_pts,
		);
	}

	/**
	 * Build the audit Event for one side of a revoke debit.
	 *
	 * @param string $action_id Event action id.
	 * @param int    $user_id   User being debited.
	 * @param int    $kudos_id  Kudos row ID (object_id).
	 * @param string $reason    Audit reason.
	 * @param int    $admin_id  Acting admin.
	 * @param string $role      'giver' | 'receiver'.
	 * @param int    $amount    Points reversed.
	 * @return Event
	 */
	private static function revoke_event( string $action_id, int $user_id, int $kudos_id, string $reason, int $admin_id, string $role, int $amount ): Event {
		return new Event(
			array(
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => $kudos_id,
				'metadata'  => array(
					'reason'      => sanitize_text_field( $reason ),
					'admin_id'    => $admin_id,
					'role'        => $role,
					'points_cost' => -$amount,
				),
			)
		);
	}

	/**
	 * Build the WHERE clause for the moderation roster from the owner's filters.
	 *
	 * A moderator's job on this screen is to FIND something -- a member being harassed with kudos, a
	 * pair trading them back and forth, a bad afternoon. With a status tab and nothing else, that job
	 * is "read every page", which stops being possible somewhere around the second screenful. Our own
	 * large-site rule says a list without a filter is unusable at 2,000 rows; this one had no way to
	 * ask about a giver, a receiver, or a date.
	 *
	 * Values are bound; only the column names are interpolated, and they come from this method.
	 *
	 * @param array $filters status|giver_id|receiver_id|date_from|date_to.
	 * @return array{0:string,1:array<int,mixed>} [ WHERE clause, ordered bind values ].
	 */
	private static function admin_where( array $filters ): array {
		$status = (string) ( $filters['status'] ?? 'all' );
		$parts  = array();
		$values = array();

		if ( 'active' === $status ) {
			$parts[] = 'k.revoked_at IS NULL';
		} elseif ( 'revoked' === $status ) {
			$parts[] = 'k.revoked_at IS NOT NULL';
		}

		$giver = (int) ( $filters['giver_id'] ?? 0 );
		if ( $giver > 0 ) {
			$parts[]  = 'k.giver_id = %d';
			$values[] = $giver;
		}

		$receiver = (int) ( $filters['receiver_id'] ?? 0 );
		if ( $receiver > 0 ) {
			$parts[]  = 'k.receiver_id = %d';
			$values[] = $receiver;
		}

		// The moderator picks site-calendar days; created_at is UTC, so the bounds are converted.
		$from = (string) ( $filters['date_from'] ?? '' );
		if ( '' !== $from ) {
			$parts[]  = 'k.created_at >= %s';
			$values[] = get_gmt_from_date( $from . ' 00:00:00' );
		}

		$to = (string) ( $filters['date_to'] ?? '' );
		if ( '' !== $to ) {
			$parts[]  = 'k.created_at <= %s';
			$values[] = get_gmt_from_date( $to . ' 23:59:59' );
		}

		return array( $parts ? 'WHERE ' . implode( ' AND ', $parts ) : '', $values );
	}

	/**
	 * Giver->receiver pairs that appear suspiciously often, ACROSS THE WHOLE TABLE.
	 *
	 * This used to be computed from the rows on the CURRENT PAGE, which means a pair trading kudos
	 * back and forth was flagged only if their exchanges happened to land on the same 20-row screen.
	 * A ring spread over a week -- the only kind there is -- was invisible. The point of the feature is
	 * to find abuse the moderator has NOT already spotted, so it has to ask the table, not the page.
	 *
	 * Asking the table needs an index to be affordable, and there wasn't one: nothing led on
	 * `revoked_at`, so this GROUP BY built a temp table and scanned every kudos row on the site, every
	 * time a moderator opened the page. `idx_abuse_pairs (revoked_at, giver_id, receiver_id)` (1.6.4)
	 * turns it into a covering index read -- EXPLAIN went from `Using temporary` to `Using index`.
	 * Nothing prunes this table and nothing should (kudos are member-authored content), so it only
	 * ever grows, and an unindexed scan of it was a bill that arrived years late.
	 *
	 * Deliberately NOT cached. It is an admin-only page, read once per load, and now index-covered --
	 * a cache here would buy nothing and cost an invalidation path to get wrong. Over-caching is a
	 * finding too.
	 *
	 * @param int $threshold Pair count at or above which a pair is flagged.
	 * @return array<string,int> "giverId-receiverId" => count.
	 */
	public static function abuse_pairs( int $threshold = 2 ): array {
		global $wpdb;

		$threshold = max( 2, $threshold );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT giver_id, receiver_id, COUNT(*) AS n
				   FROM {$wpdb->prefix}wb_gam_kudos
				  WHERE revoked_at IS NULL
				  GROUP BY giver_id, receiver_id
				 HAVING n >= %d",
				$threshold
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['giver_id'] . '-' . $row['receiver_id'] ] = (int) $row['n'];
		}

		return $map;
	}

	/**
	 * Total kudos rows matching an optional status filter (for pagination).
	 *
	 * @param string $status 'all' | 'active' | 'revoked'.
	 * @return int
	 */
	public static function admin_count( string $status = 'all', array $filters = array() ): int {
		global $wpdb;

		$filters['status']  = $status;
		[ $where, $values ] = self::admin_where( $filters );

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos k {$where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * Fetch a page of kudos for the moderation roster (with display names).
	 *
	 * @param int    $per_page Rows per page (1–200).
	 * @param int    $offset   Row offset.
	 * @param string $status   'all' | 'active' | 'revoked'.
	 * @return array<int, array{ id:int, giver_id:int, giver_name:string, receiver_id:int, receiver_name:string, message:string|null, created_at:string, revoked:bool }>
	 */
	public static function admin_list( int $per_page = 20, int $offset = 0, string $status = 'all', array $filters = array() ): array {
		global $wpdb;
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, $offset );

		$filters['status']  = $status;
		[ $where, $values ] = self::admin_where( $filters );

		$values[] = $per_page;
		$values[] = $offset;

		// $where is built from a whitelist below; LIMIT/OFFSET are prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.id, k.giver_id, g.display_name AS giver_name,
				        k.receiver_id, r.display_name AS receiver_name,
				        k.message, k.created_at, k.revoked_at
				   FROM {$wpdb->prefix}wb_gam_kudos k
				   LEFT JOIN {$wpdb->users} g ON g.ID = k.giver_id
				   LEFT JOIN {$wpdb->users} r ON r.ID = k.receiver_id
				   {$where}
				  ORDER BY k.created_at DESC
				  LIMIT %d OFFSET %d",
				$values
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
					'giver_name'    => $row['giver_name'] ?: '',
					'receiver_id'   => (int) $row['receiver_id'],
					'receiver_name' => $row['receiver_name'] ?: '',
					'message'       => $row['message'] ?: null,
					'created_at'    => $row['created_at'],
					'revoked'       => ! empty( $row['revoked_at'] ),
				);
			},
			$rows
		);
	}
}
