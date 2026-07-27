<?php
/**
 * REST API: Events Controller
 *
 * POST /wb-gamification/v1/events
 *
 * Ingest an event from any source — headless frontend, mobile app, WP-CLI,
 * third-party automation (Zapier, Make, n8n).
 *
 * The endpoint validates the action_id against the Registry, builds a
 * WBGam\Engine\Event, and calls Engine::process() — identical to the internal
 * flow. Authentication via WordPress Application Passwords or REST nonce.
 *
 * Permission levels:
 *   - Authenticated users can fire events for themselves (user_id omitted or
 *     matches their own ID).
 *   - Admins (manage_options) can fire events for any user_id.
 *
 * Rate-limiting: relies on host-level / Cloudflare limits for now.
 * Phase 4 will add a per-user token-bucket via options/transients.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\Engine;
use WBGam\Engine\Event;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for ingesting gamification events.
 *
 * Handles POST /wb-gamification/v1/events.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class EventsController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wb-gamification/v1';

	/**
	 * REST API route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'events';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// POST /events.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'action_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Registered gamification action ID.',
						),
						'user_id'   => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'User to credit. 0 = current user.',
						),
						'object_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Related object (post ID, comment ID, etc.).',
						),
						'metadata'  => array(
							'type'              => 'object',
							'default'           => array(),
							'sanitize_callback' => array( $this, 'sanitize_metadata' ),
							'description'       => 'Arbitrary key/value context data.',
						),
					),
				),
			)
		);

		$this->register_import_route();
	}

	/**
	 * Register the bulk import route (called from register_routes()).
	 *
	 * @return void
	 */
	public function register_import_route(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_items' ),
					'permission_callback' => array( $this, 'import_permissions_check' ),
					'args'                => array(
						'events' => array(
							'required'    => true,
							'type'        => 'array',
							'description' => 'Up to 500 historical events to import.',
						),
					),
				),
			)
		);
	}

	/**
	 * Retrieve the JSON schema for an event response item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-event',
			'type'       => 'object',
			'properties' => array(
				'processed' => array(
					'type'        => 'boolean',
					'description' => 'Whether the event was successfully processed.',
				),
				'event_id'  => array(
					'type'        => 'string',
					'description' => 'UUID of the created event.',
				),
				'action_id' => array(
					'type'        => 'string',
					'description' => 'The gamification action ID that was triggered.',
				),
				'user_id'   => array(
					'type'        => 'integer',
					'description' => 'User ID the event was credited to.',
				),
			),
		);
	}

	// ── Callback ────────────────────────────────────────────────────────────────

	/**
	 * Process an incoming gamification event.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		// occurred_at / source_key / points are import-only, and this route was SILENTLY DROPPING them:
		// send occurred_at=2019-01-02 and you got a 201 and an event stamped today, with nothing to tell
		// you the date had been thrown away. That silence is the bug, and it is fixed by saying no.
		//
		// It is NOT fixed by accepting them. This route is open to any logged-in member (see
		// create_item_permissions_check -- it checks is_user_logged_in() and nothing else), so honouring
		// a caller-supplied occurred_at would hand every member a backdating primitive: forge a streak
		// they never ran, drop events into last week's leaderboard window, backdate around a daily cap.
		// Honouring points would be worse -- self-award any score.
		//
		// Backdating is a real requirement, and it already has a home: POST /events/import, which is
		// gated on wb_gam_manage_members and takes all three. An owner migrating a community can use it;
		// a member cannot.
		foreach ( array( 'occurred_at', 'source_key', 'points', 'point_type' ) as $wb_gam_import_only ) {
			if ( null !== $request->get_param( $wb_gam_import_only ) ) {
				return new WP_Error(
					'wb_gam_import_only_param',
					sprintf(
						/* translators: %s: the rejected parameter name. */
						__( '"%s" cannot be set on a live event. Use POST /events/import to backdate or replay history — it requires the capability to manage members.', 'wb-gamification' ),
						$wb_gam_import_only
					),
					array( 'status' => 400 )
				);
			}
		}

		$action_id = (string) $request['action_id'];
		$user_id   = (int) $request['user_id'];
		$object_id = (int) $request['object_id'];
		$metadata  = (array) $request['metadata'];

		// Resolve user_id.
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return new WP_Error(
				'rest_no_user',
				__( 'Could not determine target user.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		// Validate action is registered.
		$action_def = Registry::get_action( $action_id );
		if ( ! $action_def ) {
			return new WP_Error(
				'rest_unknown_action',
				sprintf(
					/* translators: %s = action_id */
					__( 'Unknown action: %s', 'wb-gamification' ),
					$action_id
				),
				array( 'status' => 400 )
			);
		}

		// Non-admins can only fire events for themselves.
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $user_id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You may only fire events for yourself.', 'wb-gamification' ),
				array( 'status' => 403 )
			);
		}

		$event = new Event(
			array(
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => $object_id ?: null,
				'metadata'  => $metadata,
			)
		);

		// Capture the skip reason for THIS call only.
		//
		// passes_rate_limits() broadcasts the reason on `wb_gam_award_skipped` and
		// then returns a bare false, so Engine::process() hands us no way to tell
		// "on cooldown" apart from "no such rule" apart from "already awarded" — a
		// caller that explicitly fired an event just gets `processed: false` and is
		// left guessing. Listening for the duration of our own call keeps the reason
		// scoped to this request (no global state, no cross-request bleed) and adds
		// no listener to normal page loads.
		//
		// This is API-only diagnostics. The same copy is deliberately NOT rendered
		// to members or admins as a toast/notice: a caller here ASKED, whereas a
		// member browsing the site did not (see NotificationBridge::init).
		$skip    = null;
		$capture = static function ( $skipped_user_id, $skipped_action_id, $reason, $context = array() ) use ( &$skip ) {
			// First skip wins — it is the one that stopped the award.
			if ( null === $skip ) {
				$skip = array(
					'reason'  => (string) $reason,
					'context' => (array) $context,
				);
			}
		};

		add_action( 'wb_gam_award_skipped', $capture, 1, 4 );
		$success = Engine::process( $event );
		remove_action( 'wb_gam_award_skipped', $capture, 1 );

		$body = array(
			'processed' => $success,
			'event_id'  => $event->event_id,
			'action_id' => $action_id,
			'user_id'   => $user_id,
		);

		if ( ! $success && null !== $skip ) {
			$body['skipped'] = array(
				'reason'  => $skip['reason'],
				'message' => PointsEngine::skip_reason_message( $skip['reason'] ),
				'context' => $skip['context'],
			);
		}

		return new WP_REST_Response( $body, $success ? 201 : 200 );
	}

	// ── Permissions ─────────────────────────────────────────────────────────────

	/**
	 * Check if the current user can fire a gamification event.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to fire gamification events.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Bulk-import historical events (competitor migration / backfill).
	 *
	 * Each row may carry: action_id (required), user_id (required), object_id,
	 * points (explicit value for actions not in the Registry), point_type,
	 * occurred_at (ISO-8601 — preserved as the ledger timestamp), source_key
	 * (stable de-dup key), metadata. Rows are processed in import mode:
	 * side-effects are suppressed and derived badge state is rebuilt once at
	 * the end. Re-running the same batch is idempotent via source_key.
	 *
	 * @param WP_REST_Request $request Request with an `events` array.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_items( $request ): WP_REST_Response|WP_Error {
		$rows = $request['events'];
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error( 'rest_no_events', __( 'No events supplied.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		if ( count( $rows ) > 500 ) {
			return new WP_Error( 'rest_too_many', __( 'Import at most 500 events per request.', 'wb-gamification' ), array( 'status' => 400 ) );
		}

		// Sanitize untrusted REST input into normalized rows, then hand off to
		// the shared ingestion service (same path the competitor importers use).
		$normalized = array();
		foreach ( $rows as $row ) {
			$row          = (array) $row;
			$normalized[] = array(
				'action_id'   => isset( $row['action_id'] ) ? sanitize_key( (string) $row['action_id'] ) : '',
				'user_id'     => absint( $row['user_id'] ?? 0 ),
				'object_id'   => absint( $row['object_id'] ?? 0 ),
				'points'      => isset( $row['points'] ) ? (int) $row['points'] : null,
				'point_type'  => isset( $row['point_type'] ) ? sanitize_key( (string) $row['point_type'] ) : null,
				'occurred_at' => isset( $row['occurred_at'] ) ? sanitize_text_field( (string) $row['occurred_at'] ) : null,
				'source_key'  => isset( $row['source_key'] ) ? sanitize_text_field( (string) $row['source_key'] ) : null,
				'metadata'    => isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $this->sanitize_metadata( $row['metadata'] ) : array(),
			);
		}

		// This route's own docblock has always promised "rows are processed in import mode: side-effects
		// are suppressed", and it never was. Points were suppressed, because ImportService sets
		// metadata['_import'] and Engine::process() honours it -- but ingest() ends by calling
		// Engine::recompute_users(), and the badges DERIVED there were awarded with import mode off.
		// QA posted 150 backdated points crossing a milestone and a real member got a real email about a
		// badge for something that happened years ago.
		//
		// The other two import entry points (ImportController, ImportCommand) were wrapped; this one was
		// missed because a per-event flag LOOKED like it covered it. It could not: recompute_users()
		// awards badges outside any event.
		return new WP_REST_Response(
			\WBGam\Engine\ImportMode::run(
				static function () use ( $normalized ) {
					return \WBGam\Engine\ImportService::ingest( $normalized );
				}
			),
			200
		);
	}

	/**
	 * Only site managers may bulk-import events.
	 *
	 * @param WP_REST_Request $request Full request.
	 * @return true|WP_Error
	 */
	public function import_permissions_check( $request ): bool|WP_Error {
		if ( \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to import gamification events.', 'wb-gamification' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Sanitize the metadata object.
	 *
	 * Only allows scalar values, strips keys with potentially sensitive names,
	 * and truncates strings to 500 characters.
	 *
	 * @param mixed $meta Raw metadata value from the request.
	 * @return array Sanitized key/value metadata array.
	 */
	public function sanitize_metadata( $meta ): array {
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$blocked_keys = array( 'password', 'secret', 'token', 'key', 'auth', 'nonce', 'cookie' );
		$clean        = array();

		foreach ( $meta as $k => $v ) {
			$k = sanitize_key( (string) $k );

			// Drop blocked keys.
			foreach ( $blocked_keys as $blocked ) {
				if ( str_contains( $k, $blocked ) ) {
					continue 2;
				}
			}

			// Allow only scalar values, truncated to 500 chars.
			if ( is_scalar( $v ) ) {
				$clean[ $k ] = is_string( $v ) ? substr( sanitize_text_field( $v ), 0, 500 ) : $v;
			}
		}

		return $clean;
	}
}
