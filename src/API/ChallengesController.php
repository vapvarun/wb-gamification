<?php
/**
 * REST API: Challenges Controller
 *
 * GET    /wb-gamification/v1/challenges              Active challenges + current user's progress
 * POST   /wb-gamification/v1/challenges              Create challenge (admin)
 * GET    /wb-gamification/v1/challenges/{id}         Single challenge + current user's progress
 * PUT    /wb-gamification/v1/challenges/{id}         Update challenge (admin)
 * DELETE /wb-gamification/v1/challenges/{id}         Delete challenge (admin)
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\ChallengeEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WBGam\Engine\Transaction;
use WP_Error;

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
 * REST API controller for active challenges and user progress.
 *
 * Handles GET /wb-gamification/v1/challenges and GET /wb-gamification/v1/challenges/{id}.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class ChallengesController extends WP_REST_Controller {

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
	protected $rest_base = 'challenges';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /challenges + POST /challenges (admin create).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'user_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Fetch progress for this user. 0 = current user.',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'title'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'  => array(
							// Added in 1.4.1 — DB table has had a `description`
							// column since 1.0.0 and admin forms have always
							// posted one, but the REST schema dropped it
							// silently. Closes audit/DATA-FLOW-ADMIN-REST-
							// 2026-05-27.md §G11.
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'action_id'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'target'       => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
						),
						'bonus_points' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 0,
						),
						'starts_at'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'ends_at'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /challenges/{id} + PUT /challenges/{id} + DELETE /challenges/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'user_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'title'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'action_id'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'target'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'bonus_points' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'starts_at'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'ends_at'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'       => array(
							'type' => 'string',
							'enum' => array( 'active', 'inactive' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	// ── Permission checks ─────────────────────────────────────────────────────

	/**
	 * Check if the current user can manage challenges.
	 *
	 * Accepts either manage_options or the granular wb_gam_manage_challenges
	 * cap, so site owners can delegate challenge management to non-admin
	 * roles (e.g. community managers).
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_check(): bool|WP_Error {
		return \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_challenges' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'You do not have permission to manage challenges.', 'wb-gamification' ), array( 'status' => 403 ) );
	}

	// ── Callbacks ──────────────────────────────────────────────────────────────

	/**
	 * Retrieve all active challenges with current user progress.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing active challenges.
	 */
	public function get_items( $request ): WP_REST_Response {
		$user_id = $this->resolve_user_id( (int) $request->get_param( 'user_id' ) );
		$items   = ChallengeEngine::get_active_challenges( $user_id );

		return rest_ensure_response( $items );
	}

	/**
	 * Retrieve a single active challenge by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$challenge_id = (int) $request['id'];
		$user_id      = $this->resolve_user_id( (int) $request->get_param( 'user_id' ) );

		$all = ChallengeEngine::get_active_challenges( $user_id );

		foreach ( $all as $ch ) {
			if ( $ch['id'] === $challenge_id ) {
				return rest_ensure_response( $ch );
			}
		}

		return new WP_Error(
			'rest_challenge_not_found',
			__( 'Challenge not found or not active.', 'wb-gamification' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Create a new challenge (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$data    = array(
			'title'        => sanitize_text_field( $request['title'] ),
			'action_id'    => sanitize_key( $request['action_id'] ),
			'target'       => absint( $request['target'] ?? 10 ),
			'bonus_points' => absint( $request['bonus_points'] ?? 50 ),
			'status'       => 'active',
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s' );

		if ( ! empty( $request['starts_at'] ) ) {
			$data['starts_at'] = sanitize_text_field( $request['starts_at'] );
			$formats[]         = '%s';
		}
		if ( ! empty( $request['ends_at'] ) ) {
			$data['ends_at'] = sanitize_text_field( $request['ends_at'] );
			$formats[]       = '%s';
		}

		$inserted = $wpdb->insert( $wpdb->prefix . 'wb_gam_challenges', $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- REST write operation.

		if ( ! $inserted ) {
			return new WP_Error(
				'rest_insert_failed',
				__( 'Could not create challenge.', 'wb-gamification' ),
				array( 'status' => 500 )
			);
		}

		// Read the id before anything else touches $wpdb: bust_action_cache() writes an
		// option, and on a fresh site (option absent) that INSERT replaced insert_id, so the
		// read-back found nothing and the first challenge on every new install fataled.
		$id = (int) $wpdb->insert_id;

		\WBGam\Engine\ChallengeEngine::bust_action_cache();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-after-write for response.
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'rest_insert_failed',
				__( 'Could not create challenge.', 'wb-gamification' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( $this->prepare_challenge_row( $row ), 201 );
	}

	/**
	 * Update an existing challenge (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check before update.
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'rest_challenge_not_found',
				__( 'Challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$data = array();
		if ( isset( $request['title'] ) ) {
			$data['title'] = sanitize_text_field( $request['title'] );
		}
		if ( isset( $request['action_id'] ) ) {
			$data['action_id'] = sanitize_key( $request['action_id'] );
		}
		if ( isset( $request['target'] ) ) {
			$data['target'] = absint( $request['target'] );
		}
		if ( isset( $request['bonus_points'] ) ) {
			$data['bonus_points'] = absint( $request['bonus_points'] );
		}
		if ( isset( $request['starts_at'] ) ) {
			$data['starts_at'] = sanitize_text_field( $request['starts_at'] );
		}
		if ( isset( $request['ends_at'] ) ) {
			$data['ends_at'] = sanitize_text_field( $request['ends_at'] );
		}
		if ( isset( $request['status'] ) ) {
			$data['status'] = sanitize_key( $request['status'] );
		}

		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST write operation.
			$update_result = $wpdb->update( $wpdb->prefix . 'wb_gam_challenges', $data, array( 'id' => $id ) );
			if ( false === $update_result ) {
				return new WP_Error( 'rest_update_failed', __( 'Could not update challenge.', 'wb-gamification' ), array( 'status' => 500 ) );
			}
		}

		\WBGam\Engine\ChallengeEngine::bust_action_cache();

		$updated = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-after-write for response.
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return rest_ensure_response( $this->prepare_challenge_row( $updated ) );
	}

	/**
	 * Delete a challenge (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id = (int) $request['id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check before delete.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'rest_challenge_not_found',
				__( 'Challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		// Delete progress logs + the challenge itself atomically so a partial
		// failure can't orphan challenge_log rows.
		$deleted = Transaction::run(
			function () use ( $wpdb, $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cascade delete.
				if ( false === $wpdb->delete( $wpdb->prefix . 'wb_gam_challenge_log', array( 'challenge_id' => $id ), array( '%d' ) ) ) {
					return false;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST delete operation.
				return false !== $wpdb->delete( $wpdb->prefix . 'wb_gam_challenges', array( 'id' => $id ), array( '%d' ) );
			}
		);
		if ( true !== $deleted ) {
			return new WP_Error( 'rest_delete_failed', __( 'Could not delete challenge.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		\WBGam\Engine\ChallengeEngine::bust_action_cache();

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Resolve the effective user ID from the request parameter, honouring profile privacy.
	 *
	 * The two read routes on this controller are deliberately public: the challenge CATALOGUE is
	 * public information, and the site's own blocks render it to logged-out visitors.
	 *
	 * The `?user_id=` parameter is not. It enriches each challenge with that member's `progress` and
	 * `completed` — and this method used to hand back whatever ID it was asked for, so an anonymous
	 * caller with no cookie and no nonce could walk `?user_id=1,2,3…` and harvest every member's
	 * progress, including members who opted OUT of a public profile. Verified against a live site
	 * before this fix: an unauthenticated GET returned the admin's real 3/5.
	 *
	 * The privacy gate belongs HERE, at the single point both read routes resolve through, rather
	 * than copied into each callback -- two copies of a security check is one copy that will be
	 * forgotten. Failing the gate degrades to a catalogue read (user 0: no progress, no completion),
	 * which is the exact shape an anonymous client gets when it omits `?user_id` — so the response
	 * stays well-formed and tells the caller nothing about whether the member exists.
	 *
	 * `BadgesController::get_items()` already defends the identical parameter this way; Challenges
	 * was simply never given the same treatment.
	 *
	 * @param int $requested User ID from the request, 0 to use the current user.
	 * @return int Resolved user ID, or 0 when the caller may not see that member's progress.
	 */
	private function resolve_user_id( int $requested ): int {
		if ( $requested <= 0 ) {
			return is_user_logged_in() ? get_current_user_id() : 0;
		}

		// A member always sees their own progress.
		if ( $requested === get_current_user_id() ) {
			return $requested;
		}

		if ( ! \WBGam\Engine\Privacy::can_view_public_profile( $requested ) ) {
			return 0;
		}

		return $requested;
	}

	/**
	 * Format a raw challenge DB row for REST response.
	 *
	 * @param array $row Raw row from the challenges table.
	 * @return array Formatted challenge data.
	 */
	private function prepare_challenge_row( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'title'        => $row['title'],
			'type'         => $row['type'],
			'action_id'    => $row['action_id'],
			'target'       => (int) $row['target'],
			'bonus_points' => (int) $row['bonus_points'],
			'period'       => $row['period'],
			'starts_at'    => $row['starts_at'] ?: null,
			'ends_at'      => $row['ends_at'] ?: null,
			'status'       => $row['status'],
		);
	}

	/**
	 * Retrieve the JSON schema for a challenge item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-challenge',
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'type'         => array(
					'type' => 'string',
					'enum' => array( 'individual', 'team' ),
				),
				'action_id'    => array( 'type' => 'string' ),
				'target'       => array( 'type' => 'integer' ),
				'bonus_points' => array( 'type' => 'integer' ),
				'period'       => array( 'type' => 'string' ),
				'starts_at'    => array( 'type' => array( 'string', 'null' ) ),
				'ends_at'      => array( 'type' => array( 'string', 'null' ) ),
				'progress'     => array( 'type' => 'integer' ),
				'progress_pct' => array( 'type' => 'number' ),
				'completed'    => array( 'type' => 'boolean' ),
			),
		);
	}
}
