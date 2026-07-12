<?php
/**
 * Server-Sent Events controller.
 *
 * Stage 1 scaffold (2026-05-28): registered but feature-flagged OFF.
 * Returns HTTP 503 with a Retry-After header until the wb_gam_realtime_transport
 * option is flipped to 'sse'. See plan/REAL-TIME-TRANSPORT.md for the full
 * rollout plan and the environment hazards this controller is designed
 * around (PHP-FPM session lock, output buffering, max_execution_time,
 * nginx + Cloudflare buffering, REST framework JSON wrapping,
 * floating connections after browser close).
 *
 * Future stages flip the flag to 'auto', at which point client probes
 * SSE first and falls back to heartbeat on failure. Heartbeat polling
 * via assets/js/heartbeat.js remains the always-available default.
 *
 * REGISTRATION NOTE: this controller intentionally does NOT extend
 * WP_REST_Controller. The REST framework auto-serialises return values
 * to JSON; SSE streams raw byte sequences that the callback writes
 * itself and then `exit`s. We register the route with the bare
 * `register_rest_route()` API and a hand-written callback.
 *
 * @package WB_Gamification
 * @since   1.5.1
 */

namespace WBGam\API;

use WBGam\Engine\NotificationBridge;

defined( 'ABSPATH' ) || exit;

final class SSEController {

	/**
	 * Option that controls the realtime transport. Single switch:
	 *
	 *   'heartbeat'  Default. WP Heartbeat polling (15s steady, 5s burst
	 *                for 30s after an action, near-suspend on hidden tabs).
	 *   'sse'        Server-Sent Events stream (this controller). Opt-in
	 *                only, gated behind the `wb_gam_sse_allowed` filter
	 *                (default false) because the long-poll pins a PHP-FPM
	 *                worker per connection.
	 *   'auto'       Client tries SSE first, falls back to heartbeat on
	 *                connection error (only effective where SSE is allowed).
	 */
	public const TRANSPORT_OPTION = 'wb_gam_realtime_transport';

	public const TRANSPORT_HEARTBEAT = 'heartbeat';
	public const TRANSPORT_SSE       = 'sse';
	public const TRANSPORT_AUTO      = 'auto';

	/**
	 * REST namespace shared with the rest of the v1 surface.
	 */
	private const NAMESPACE = 'wb-gamification/v1';

	/**
	 * Stream path. EventSource('/wp-json/wb-gamification/v1/events/stream')
	 * from JS hits this.
	 */
	private const ROUTE = '/events/stream';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'last_event_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'description'       => 'Last event id the client has seen. Stream resumes from id > this.',
					),
				),
			)
		);

		// EventSource can't send custom headers, so WordPress's REST
		// cookie-nonce check (rest_cookie_check_errors → 'rest_cookie_
		// invalid_nonce') would reject every SSE connection even from
		// authenticated users. Read-only GET on a per-user-scoped stream
		// is safe to allow without the nonce — the auth cookie itself
		// proves identity, and our check_permission() still gates on
		// is_user_logged_in() AND scopes the stream to the current user.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'maybe_bypass_nonce_for_stream' ), 99 );
	}

	/**
	 * Allow nonce-less cookie auth for /events/stream only. Every other
	 * REST endpoint still requires the X-WP-Nonce header for cookie auth.
	 *
	 * @param \WP_Error|mixed $error Current authentication error (may be null).
	 * @return \WP_Error|mixed Pass-through unless we want to clear the
	 *                        nonce error for the stream route.
	 */
	public static function maybe_bypass_nonce_for_stream( $error ) {
		if ( ! ( $error instanceof \WP_Error ) ) {
			return $error;
		}
		if ( 'rest_cookie_invalid_nonce' !== $error->get_error_code() ) {
			return $error;
		}

		// Only bypass for the stream route. REQUEST_URI is the safest
		// signal — `rest_route` query param OR pretty permalink form
		// both end in the same trailing path.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $uri, '/wb-gamification/v1/events/stream' ) ) {
			return $error;
		}

		// Clear the error. is_user_logged_in() will now reflect the
		// raw auth cookie, and check_permission() rejects guests.
		return null;
	}

	/**
	 * Auth gate. Logged-in users only; sender's events are scoped to
	 * their own user_id by SSE storage writes elsewhere.
	 *
	 * EventSource can't send the X-WP-Nonce header WordPress's REST
	 * cookie-auth pipeline requires. `is_user_logged_in()` returns
	 * false during the REST request even when the auth cookie is set
	 * because rest_cookie_check_errors clears the current user when
	 * the nonce is missing. We re-validate the cookie directly here
	 * and force-set the current user for the duration of the request
	 * if it's valid. This is safe: the cookie itself is the
	 * authentication factor; the nonce protects against CSRF on
	 * write operations, and this endpoint is read-only.
	 */
	public function check_permission(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		// Direct cookie validation. wp_validate_auth_cookie returns the
		// user_id when the cookie is valid + unexpired.
		if ( ! isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return false;
		}
		$user_id = wp_validate_auth_cookie( sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) ), 'logged_in' );
		if ( ! $user_id ) {
			return false;
		}
		wp_set_current_user( (int) $user_id );
		return is_user_logged_in();
	}

	/**
	 * Returns the active transport mode.
	 *
	 * Default flipped from 'heartbeat' to 'auto' on 2026-05-28 after the
	 * two-tab Playwright journey verified:
	 *   1. SSE controller authentication works (rest_authentication_errors
	 *      filter + direct wp_validate_auth_cookie() in check_permission)
	 *   2. EventSource streams text/event-stream with correct headers
	 *   3. wb_gam_notifications_queue table receives kudos rows
	 *   4. sse.js dispatches received events into wbGamRealtime broker
	 *   5. Heartbeat path stays active in parallel — toast.js Set-dedupe
	 *      collapses cross-transport duplicates so the user sees one toast
	 *   6. EventSource auto-reconnects across the 28-second server-side
	 *      soft deadline without losing events
	 *
	 * 'auto' = client tries SSE first; falls back to heartbeat on
	 * EventSource connection error. Hosts that can't sustain SSE
	 * (cPanel without PHP-FPM tuning, aggressive proxy buffering) get
	 * the heartbeat path transparently. Site owners can pin to
	 * 'heartbeat' explicitly via `wp option update wb_gam_realtime_transport
	 * heartbeat`.
	 */
	public static function get_transport(): string {
		// Default HEARTBEAT (not AUTO). SSE here is a PHP long-poll that
		// pins a worker per connection (see sse_allowed()); defaulting to
		// SSE/auto does not scale for a live community on a standard pool.
		$value = (string) get_option( self::TRANSPORT_OPTION, self::TRANSPORT_HEARTBEAT );
		if ( ! in_array( $value, array( self::TRANSPORT_HEARTBEAT, self::TRANSPORT_SSE, self::TRANSPORT_AUTO ), true ) ) {
			return self::TRANSPORT_HEARTBEAT;
		}
		return $value;
	}

	/**
	 * Whether SSE long-polling is permitted on this host.
	 *
	 * SSE here is a PHP long-poll: each connection pins a PHP-FPM worker
	 * for the connection lifetime (the 28s soft deadline in stream()). A
	 * large community would need roughly one held worker per concurrent
	 * logged-in member, which no standard PHP-FPM pool can sustain. So SSE
	 * is an opt-in capability: it activates ONLY when the site owner runs
	 * a tier built for long-lived streaming (dedicated worker pool, no
	 * proxy buffering) and returns true from this filter. Everyone else
	 * gets WP Heartbeat, which returns immediately and is shared +
	 * throttled by core.
	 *
	 * @return bool
	 */
	public static function sse_allowed(): bool {
		/**
		 * Filters whether SSE long-poll streaming may run on this host.
		 *
		 * Default false: SSE pins a PHP-FPM worker per connection and does
		 * not scale on a shared/standard pool. Enable only on infrastructure
		 * provisioned for long-lived streaming connections.
		 *
		 * @since 1.5.2
		 * @param bool $allowed Whether SSE is permitted. Default false.
		 */
		return (bool) apply_filters( 'wb_gam_sse_allowed', false );
	}

	/**
	 * The transport the CLIENT should actually use.
	 *
	 * Downgrades sse/auto to heartbeat when SSE isn't permitted on this
	 * host, so the browser never opens an EventSource that would pin a
	 * worker. This is what gets localized to sse.js.
	 *
	 * @return string
	 */
	public static function effective_transport(): string {
		$mode = self::get_transport();
		if ( ( self::TRANSPORT_SSE === $mode || self::TRANSPORT_AUTO === $mode ) && ! self::sse_allowed() ) {
			return self::TRANSPORT_HEARTBEAT;
		}
		return $mode;
	}

	/**
	 * Returns true if SSE should be served. Gated by sse_allowed() so a
	 * stale client (or a hand-crafted request) can't pin a worker when
	 * SSE isn't permitted — we refuse with 503 and the JS adapter falls
	 * back to heartbeat permanently.
	 */
	public static function is_enabled(): bool {
		if ( ! self::sse_allowed() ) {
			return false;
		}
		$mode = self::get_transport();
		return self::TRANSPORT_SSE === $mode || self::TRANSPORT_AUTO === $mode;
	}

	/**
	 * Stream handler.
	 *
	 * Stages 2+3 of the rollout. Long-polls the wb_gam_notifications_queue
	 * table (shipped in v2.2) for events whose id > last_event_id from
	 * the request. Emits SSE-framed JSON for each, sleeps 2s, repeats
	 * until either the 28-second soft deadline or
	 * connection_aborted() fires.
	 *
	 * Environment hazards (per plan/REAL-TIME-TRANSPORT.md):
	 *   - PHP-FPM session lock        → session_write_close() first
	 *   - Output buffering            → drain + ob_implicit_flush(true)
	 *   - max_execution_time          → set_time_limit(0) + soft 28s deadline
	 *   - nginx + Cloudflare buffering → X-Accel-Buffering: no header
	 *   - REST framework JSON wrapping → write raw bytes + exit (no return)
	 *   - Floating connections        → connection_aborted() inside loop
	 *
	 * Returns nothing — exits the request inline. WordPress sees the
	 * response as completed.
	 */
	public function stream( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return new \WP_REST_Response(
				array(
					'code'      => 'sse_disabled',
					'message'   => 'Server-Sent Events transport is not enabled on this install.',
					'transport' => self::get_transport(),
				),
				503,
				array( 'Retry-After' => '30' )
			);
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			// permission_callback should have caught this, but defense in depth.
			return new \WP_REST_Response( array( 'code' => 'unauthenticated' ), 401 );
		}

		$last_id = (int) $request->get_param( 'last_event_id' );

		// Release the session lock so other requests from the same user
		// aren't blocked for the lifetime of this stream.
		if ( function_exists( 'session_write_close' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		// Headers — text/event-stream + cache + buffering opt-outs.
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Drain every existing output buffer + disable implicit buffering.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		ob_implicit_flush( true );

		// Lift the request time-limit so a 30s stream doesn't 504. Caller
		// won't expect a return value; we'll exit() at the end.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional: hosts that disable set_time_limit silently throw a warning.
		}

		// 4 KB padding comment so any proxy with a small buffer flushes
		// the response on the first byte instead of waiting to fill.
		echo ': ' . str_repeat( ' ', 4096 ) . "\n\n";
		@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some configs throw when no buffer is active.
		flush();

		$started       = time();
		$soft_deadline = $started + 28;
		$poll_interval = 2;
		$idle_max      = 25; // exit if we go this long with no events (lets client reconnect for fresh limits)
		$last_event_at = $started;

		while ( time() < $soft_deadline ) {
			if ( connection_aborted() ) {
				break;
			}

			// THE queue reader — the same one the footer seed, the heartbeat tick
			// and the REST poll use. SSE differs only in where its cursor lives
			// (this request's `last_event_id`, not user_meta), so it passes one in
			// rather than owning a second copy of the query. Returns the NEWEST
			// unseen events, capped at the toast burst; advancing $last_id to the
			// final row therefore parks us at the head of the backlog, so a member
			// who reconnects to 30,000 pending events is caught up in one poll
			// instead of being streamed 50 stale toasts every 2 seconds.
			$rows = NotificationBridge::fetch_unseen( $user_id, $last_id );

			foreach ( $rows as $row ) {
				$last_id       = (int) $row['id'];
				$last_event_at = time();

				// The client dedupes toasts by `_id`, and every reader stamps the
				// authoritative table row id — so the same event arriving over SSE
				// and over the footer/heartbeat path carries one key and renders once.
				$payload = $row['payload'];
				if ( ! empty( $payload ) ) {
					$payload['_id'] = $last_id;
					$data           = (string) wp_json_encode( $payload );
				} else {
					// Undecodable row: emit an empty object rather than nothing, so
					// the id still advances the client's cursor past it.
					$data = '{}';
				}

				// SSE wire format: `id`, `event`, `data`. Each terminated
				// by \n; record terminated by extra \n. This is a
				// text/event-stream response, not HTML, so esc_html()/esc_attr()
				// would corrupt the protocol AND the JSON payload — the correct
				// "escaping" here is preventing a stray CR/LF from injecting an
				// extra SSE field. $last_id is an int; $data is wp_json_encode
				// output (newlines already \u-escaped); event_type is the only
				// free-ish value, so strip CR/LF defensively.
				$event_name = str_replace( array( "\r", "\n" ), '', (string) $row['event_type'] );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE stream (not HTML); values are an int, a CRLF-stripped token, and JSON. See note above.
				printf(
					"id: %d\nevent: %s\ndata: %s\n\n",
					$last_id,
					$event_name,
					$data
				);
			}

			// Keep-alive comment — proxies that idle-close after 30s of
			// silence see ongoing bytes.
			if ( empty( $rows ) ) {
				echo ": keepalive\n\n";
			}

			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			flush();

			if ( ( time() - $last_event_at ) >= $idle_max ) {
				break;
			}

			sleep( $poll_interval );
		}

		// Emit a `close` event so the client knows the server-side soft
		// deadline ended — distinct from an error. EventSource will
		// auto-reconnect from this state.
		echo "event: close\ndata: {}\n\n";
		@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		flush();

		exit;
	}

	// The queue is read through NotificationBridge::fetch_unseen() — this
	// controller owns no query of its own. It previously kept a private
	// fetch_pending() with its own `ORDER BY id ASC LIMIT 50`, which is exactly
	// how the backlog-replay bug survived on this surface while the footer path
	// was being fixed: one behaviour, two implementations, and only one of them
	// got the fix.
}
