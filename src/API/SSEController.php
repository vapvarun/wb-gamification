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

defined( 'ABSPATH' ) || exit;

final class SSEController {

	/**
	 * Option that controls the realtime transport. Single switch:
	 *
	 *   'heartbeat'  Default. WP Heartbeat polling at 5s (existing).
	 *   'sse'        Server-Sent Events stream (this controller).
	 *   'auto'       Client tries SSE first, falls back to heartbeat on
	 *                connection error. Set after stages 2-4 ship.
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
	}

	/**
	 * Auth gate. Logged-in users only; sender's events are scoped to
	 * their own user_id by SSE storage writes elsewhere.
	 */
	public function check_permission(): bool {
		return is_user_logged_in();
	}

	/**
	 * Returns the active transport mode, defaulting to heartbeat so
	 * existing installs are unaffected by upgrade.
	 */
	public static function get_transport(): string {
		$value = (string) get_option( self::TRANSPORT_OPTION, self::TRANSPORT_HEARTBEAT );
		if ( ! in_array( $value, array( self::TRANSPORT_HEARTBEAT, self::TRANSPORT_SSE, self::TRANSPORT_AUTO ), true ) ) {
			return self::TRANSPORT_HEARTBEAT;
		}
		return $value;
	}

	/**
	 * Returns true if SSE should be served. In auto mode the client
	 * decides; we accept connections either way. In heartbeat mode we
	 * refuse with 503 so JS adapter falls back permanently.
	 */
	public static function is_enabled(): bool {
		$mode = self::get_transport();
		return self::TRANSPORT_SSE === $mode || self::TRANSPORT_AUTO === $mode;
	}

	/**
	 * Stream handler. Stage 1 = no-op: returns 503 with the configured
	 * transport so the JS adapter knows to fall back. Stages 2-3 land
	 * the real loop body.
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

		// Stage 2+ implementation lands here. For now, also return 503
		// — we don't have the storage table yet, so even if enabled
		// there's nothing to stream. This keeps the contract honest:
		// the JS adapter's fallback path is exercised either way.
		return new \WP_REST_Response(
			array(
				'code'    => 'sse_not_implemented',
				'message' => 'SSE streaming loop ships in stage 2 of the rollout. See plan/REAL-TIME-TRANSPORT.md.',
			),
			503,
			array( 'Retry-After' => '60' )
		);
	}
}
