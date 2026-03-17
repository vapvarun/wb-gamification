<?php
/**
 * REST API: Challenges Controller
 *
 * GET  /wb-gamification/v1/challenges           Active challenges + current user's progress
 * GET  /wb-gamification/v1/challenges/{id}      Single challenge + current user's progress
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
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ChallengesController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'challenges';

	public function register_routes(): void {
		// GET /challenges
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
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /challenges/{id}
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
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	// ── Callbacks ──────────────────────────────────────────────────────────────

	/**
	 * GET /challenges — all active challenges with progress.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->resolve_user_id( (int) $request->get_param( 'user_id' ) );
		$items   = ChallengeEngine::get_active_challenges( $user_id );

		return rest_ensure_response( $items );
	}

	/**
	 * GET /challenges/{id} — single challenge.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
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

	// ── Helpers ────────────────────────────────────────────────────────────────

	private function resolve_user_id( int $requested ): int {
		if ( $requested > 0 ) {
			return $requested;
		}
		return is_user_logged_in() ? get_current_user_id() : 0;
	}

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
