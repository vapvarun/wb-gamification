<?php
/**
 * REST API: Email Settings Controller
 *
 * GET  /wb-gamification/v1/settings/emails   Read per-event email toggles.
 * POST /wb-gamification/v1/settings/emails   Save per-event email toggles.
 *
 * Backs the Settings → Emails section. Each known event gets its own
 * `wb_gam_email_<slug>` option (default false — opt-in per event so
 * existing sites don't suddenly start emailing every member).
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for per-event transactional email toggles.
 */
final class EmailSettingsController extends WP_REST_Controller {

	/**
	 * Whitelisted event slugs. Every other field in the POST body is dropped.
	 */
	private const EVENTS = array( 'level_up', 'badge_earned', 'challenge_completed' );

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wb-gamification/v1';

	/**
	 * REST route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings/emails';

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_save' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
			)
		);
	}

	/**
	 * Permission gate.
	 */
	public function admin_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Read current toggle state.
	 */
	public function handle_get( WP_REST_Request $request ): WP_REST_Response {
		$out = array();
		foreach ( self::EVENTS as $slug ) {
			$out[ $slug ] = (bool) get_option( 'wb_gam_email_' . $slug, false );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Save toggle state. Only whitelisted event slugs are accepted.
	 *
	 * Semantics: PATCH-like — only the slugs the request body explicitly
	 * carries are written. Pre-1.4.1 every call wrote ALL 4 options
	 * (whatever wasn't in the body became OFF), so a client that wanted
	 * to flip a single toggle had to send all 4 values or risk silently
	 * disabling unrelated ones. See audit/DATA-FLOW-ADMIN-REST-2026-05-27.md §G7.
	 *
	 * To verify a key is "actually in the body" (not just defaulting to
	 * null from get_param()), the controller inspects the request's
	 * decoded JSON params + body params + query params with `has_param()`-
	 * style logic. WP REST doesn't expose `has_param`, so we read the
	 * raw param map.
	 */
	public function handle_save( WP_REST_Request $request ): WP_REST_Response {
		$body_params  = (array) $request->get_json_params();
		$body_params += (array) $request->get_body_params();
		$query_params = (array) $request->get_query_params();
		$incoming_keys = array_keys( $body_params + $query_params );

		$updated = array();
		foreach ( self::EVENTS as $slug ) {
			// Only write the slug if the request explicitly carries it.
			// Missing keys are NOT auto-toggled OFF (PATCH semantics).
			if ( ! in_array( $slug, $incoming_keys, true ) ) {
				$updated[ $slug ] = (bool) get_option( 'wb_gam_email_' . $slug, false );
				continue;
			}
			$incoming        = $request->get_param( $slug );
			$value           = ! empty( $incoming ) && '0' !== (string) $incoming;
			update_option( 'wb_gam_email_' . $slug, $value ? '1' : '0' );
			$updated[ $slug ] = $value;
		}
		return rest_ensure_response( array( 'ok' => true, 'settings' => $updated ) );
	}
}
