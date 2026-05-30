<?php
/**
 * WB Gamification — Realtime channel (WordPress Heartbeat API).
 *
 * Single source of realtime data for the plugin's frontend. Every block,
 * shortcode, or future UI that needs "live" data subscribes to this one
 * channel instead of running its own poll loop. This is the long-term
 * substrate the pre-1.4.0 toast.js setInterval polling, leaderboard
 * page-reload UX, and the new floating points bar all consume.
 *
 * Why Heartbeat instead of SSE / WebSockets:
 *   - native WP, no PHP-FPM long-poll worker required
 *   - already deployed to 100% of WP admin sessions; same backend on FE
 *   - browser-tab-aware (Heartbeat slows / pauses when tab is hidden)
 *   - survives Cloudflare, Varnish, and shared-hosting time limits that
 *     break SSE
 *   - configurable cadence (15s active, up to 60s idle) is plenty for
 *     points + badges + leaderboard movement, none of which need ms-grade
 *     latency
 *
 * Wire format (request):
 *   data['wb_gam'] = {
 *     boards: [
 *       { period, scope_type, scope_id, limit, point_type, sig }, …
 *     ],
 *     since:  <timestamp the client last received>,
 *   }
 *
 * Wire format (response):
 *   response['wb_gam'] = {
 *     user:        { user_id, points: { <type>: total }, level: {…}, badges_count, current_streak, next_level: {…} },
 *     toasts:      [ {type, message, …}, … ],   // same shape as /members/me/toasts
 *     leaderboards: { <sig>: [rows], … },        // only the boards the client asked for
 *     ts:          <server timestamp>,
 *   }
 *
 * @package WB_Gamification
 * @since   1.4.0
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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Pushes user / toast / leaderboard deltas to the frontend via WP Heartbeat.
 *
 * @package WB_Gamification
 */
final class HeartbeatChannel {

	/**
	 * Cap on number of leaderboards a single client can subscribe to per tick.
	 *
	 * Defends against a malicious / buggy client asking for hundreds of
	 * boards. 8 is generous — even a heavy hub page with multiple block
	 * instances doesn't need more.
	 */
	private const MAX_BOARDS_PER_TICK = 8;

	/**
	 * Cap on leaderboard rows returned per board in a single tick.
	 */
	private const MAX_BOARD_ROWS = 50;

	/**
	 * Boot.
	 */
	public static function init(): void {
		// `heartbeat_received` captures board descriptors the client sent;
		// `heartbeat_send` always fires regardless of whether the client
		// sent any data and is the correct hook for a server → client
		// realtime push channel. Both `nopriv_*` variants are wired so
		// public leaderboards stay live for logged-out visitors.
		add_filter( 'heartbeat_received', array( __CLASS__, 'on_heartbeat_received' ), 10, 3 );
		add_filter( 'heartbeat_nopriv_received', array( __CLASS__, 'on_heartbeat_received' ), 10, 3 );
		add_filter( 'heartbeat_send', array( __CLASS__, 'on_heartbeat_send' ), 10, 2 );
		add_filter( 'heartbeat_nopriv_send', array( __CLASS__, 'on_heartbeat_send' ), 10, 2 );
	}

	/**
	 * Cache for client-submitted board descriptors within a single
	 * heartbeat request. Filled by on_heartbeat_received, consumed by
	 * on_heartbeat_send.
	 *
	 * @var array<int, array>
	 */
	private static $pending_boards = array();

	/**
	 * Build the heartbeat response payload.
	 *
	 * Runs on every heartbeat tick from a logged-in or anonymous client.
	 * Anonymous clients still get a response (empty user + leaderboards
	 * only) so a public leaderboard widget stays live for guests.
	 *
	 * @param array  $response Pending response array (other listeners may have added keys).
	 * @param array  $data     Client-submitted data (we read `wb_gam`).
	 * @param string $screen   Active screen, unused on the frontend.
	 * @return array
	 */
	public static function on_heartbeat_received( $response, $data, $screen = '' ) {
		if ( ! is_array( $response ) ) {
			$response = array();
		}
		if ( is_array( $data ) && isset( $data['wb_gam']['boards'] ) && is_array( $data['wb_gam']['boards'] ) ) {
			self::$pending_boards = $data['wb_gam']['boards'];
		}
		return $response;
	}

	/**
	 * Append the WB Gam realtime payload to every heartbeat response.
	 *
	 * Unlike `heartbeat_received`, this filter fires on every tick — even
	 * when the client sent no data — so the broker reliably pushes user
	 * stats + toasts to the page without depending on the client always
	 * having something to ask for.
	 *
	 * @param array  $response Pending Heartbeat response.
	 * @param string $screen   Active screen id.
	 * @return array
	 */
	public static function on_heartbeat_send( $response, $screen = '' ) {
		if ( ! is_array( $response ) ) {
			$response = array();
		}

		$user_id = get_current_user_id();
		// Read-then-reset BEFORE any other work. The static was a real
		// cross-request leak risk: if a callback registered against the
		// heartbeat filter chain threw between the copy and the reset,
		// the next request served by the same PHP-FPM worker would
		// inherit the previous client's board descriptors. Reset first;
		// the local copy keeps our own snapshot. Closes
		// audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G9.
		$boards               = self::$pending_boards;
		self::$pending_boards = array();

		$out = array(
			'ts'           => time(),
			'user'         => self::build_user_snapshot( $user_id ),
			'toasts'       => self::flush_toasts( $user_id ),
			'leaderboards' => self::build_leaderboard_snapshots( $boards ),
		);

		/**
		 * Filter the heartbeat payload sent to the client.
		 *
		 * @since 1.4.0
		 *
		 * @param array $out     Outbound payload.
		 * @param int   $user_id Current user (0 for guests).
		 * @param array $boards  Client-submitted board descriptors.
		 */
		$out = (array) apply_filters( 'wb_gam_heartbeat_payload', $out, $user_id, $boards );

		$response['wb_gam'] = $out;
		return $response;
	}

	/**
	 * Compose the current user's stats snapshot.
	 *
	 * Returns the fields the floating points bar + any live profile chip
	 * subscribes to. Empty payload for guests so the JS broker can decide
	 * whether to render the bar at all.
	 *
	 * @param int $user_id Member id.
	 * @return array
	 */
	private static function build_user_snapshot( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		// Primary currency total (the floating bar's headline number).
		$pt_service     = new \WBGam\Services\PointTypeService();
		$primary_slug   = (string) $pt_service->default_slug();
		$primary_record = $pt_service->get( $primary_slug );
		$primary_label  = (string) ( $primary_record['label'] ?? __( 'points', 'wb-gamification' ) );
		$primary_total  = (int) PointsEngine::get_total( $user_id, $primary_slug );

		// All-currency totals — keyed by slug so a multi-currency theme
		// (XP + Coins) can pick the slug it cares about without a second
		// REST round-trip.
		$by_type = array();
		foreach ( (array) $pt_service->list() as $pt ) {
			$slug = (string) ( $pt['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$by_type[ $slug ] = array(
				'label' => (string) ( $pt['label'] ?? $slug ),
				'total' => (int) PointsEngine::get_total( $user_id, $slug ),
			);
		}

		$level      = LevelEngine::get_level_for_user( $user_id );
		$next_level = LevelEngine::get_next_level( $user_id );
		$progress   = LevelEngine::get_progress_percent( $user_id );

		$badge_count = (int) BadgeEngine::count_user_badges( $user_id );

		$streak         = StreakEngine::get_streak( $user_id );
		$current_streak = (int) ( $streak['current_streak'] ?? 0 );
		$longest_streak = (int) ( $streak['longest_streak'] ?? 0 );

		return array(
			'user_id'          => $user_id,
			'primary_label'    => $primary_label,
			'primary_total'    => $primary_total,
			'totals'           => $by_type,
			'level'            => $level
				? array(
					'id'         => (int) $level['id'],
					'name'       => (string) $level['name'],
					'min_points' => (int) $level['min_points'],
				)
				: null,
			'next_level'       => $next_level
				? array(
					'id'         => (int) $next_level['id'],
					'name'       => (string) $next_level['name'],
					'min_points' => (int) $next_level['min_points'],
				)
				: null,
			'progress_percent' => (int) $progress,
			'badges_count'     => $badge_count,
			'current_streak'   => $current_streak,
			'longest_streak'   => $longest_streak,
		);
	}

	/**
	 * Read pending toast notifications for the current user via heartbeat.
	 *
	 * Delegates to NotificationBridge::read_pending() with a dedicated
	 * 'heartbeat' cursor. Non-destructive: the footer renderer and REST
	 * poller maintain their own cursors and stay in sync with the same
	 * source queue, eliminating the historical race where whichever reader
	 * fired first drained the queue and the others saw nothing.
	 *
	 * @param int $user_id Member id.
	 * @return array
	 */
	private static function flush_toasts( int $user_id ): array {
		return NotificationBridge::read_pending( $user_id, 'heartbeat' );
	}

	/**
	 * Refresh leaderboard rows for every board the client is currently viewing.
	 *
	 * The client sends a list of board signatures (period + scope) it has
	 * mounted in the DOM. We return fresh rows for each. The signature
	 * round-trips back to the client so it can match the rows to the right
	 * block instance even when two boards on the same page share scope.
	 *
	 * @param array $boards Client-submitted board descriptors.
	 * @return array<string, array> Keyed by signature.
	 */
	private static function build_leaderboard_snapshots( array $boards ): array {
		if ( empty( $boards ) || ! is_array( $boards ) ) {
			return array();
		}

		$boards = array_slice( $boards, 0, self::MAX_BOARDS_PER_TICK );
		$out    = array();

		foreach ( $boards as $board ) {
			if ( ! is_array( $board ) ) {
				continue;
			}
			$sig        = (string) ( $board['sig'] ?? '' );
			$period     = sanitize_key( (string) ( $board['period'] ?? 'all' ) );
			$scope_type = sanitize_key( (string) ( $board['scope_type'] ?? '' ) );
			$scope_id   = (int) ( $board['scope_id'] ?? 0 );
			$limit      = max( 1, min( self::MAX_BOARD_ROWS, (int) ( $board['limit'] ?? 10 ) ) );
			$point_type = sanitize_key( (string) ( $board['point_type'] ?? '' ) );

			if ( '' === $sig ) {
				// Fall back to a deterministic signature so the client can
				// still match by period+scope+limit if it didn't send one.
				$sig = md5( wp_json_encode( array( $period, $scope_type, $scope_id, $limit, $point_type ) ) );
			}

			$rows = LeaderboardEngine::get_leaderboard(
				$period,
				$limit,
				$scope_type,
				$scope_id,
				$point_type
			);

			// Trim the row payload to what the frontend renders. Avoid
			// shipping internal flags or unbounded metadata.
			//
			// badge_count was added in 1.4.0 (Basecamp #9914601059) — the
			// leaderboard block emits a server-rendered .__badges span and
			// the view.js patcher reads this key on every tick to keep the
			// count in sync without rebuilding the row (and dropping the
			// SVG icon in the process).
			$out[ $sig ] = array_map(
				static function ( $row ) {
					$uid = (int) $row['user_id'];
					return array(
						'user_id'      => $uid,
						'display_name' => (string) $row['display_name'],
						'points'       => (int) $row['points'],
						'rank'         => (int) $row['rank'],
						'badge_count'  => $uid > 0 ? (int) BadgeEngine::count_user_badges( $uid ) : 0,
					);
				},
				(array) $rows
			);
		}

		return $out;
	}
}
