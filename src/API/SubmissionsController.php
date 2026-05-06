<?php
/**
 * REST API: Submissions Controller
 *
 *   POST /submissions               Member submits an achievement.
 *   GET  /submissions               Admin lists the queue.
 *   POST /submissions/{id}/approve  Admin approves → fires earning event.
 *   POST /submissions/{id}/reject   Admin rejects with reason.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WBGam\Services\SubmissionService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the achievement submission queue.
 */
final class SubmissionsController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'submissions';

	private SubmissionService $service;

	public function __construct( ?SubmissionService $service = null ) {
		$this->service = $service ?? new SubmissionService();
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_submit' ),
					'permission_callback' => array( $this, 'logged_in_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_approve' ),
				'permission_callback' => array( $this, 'admin_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_reject' ),
				'permission_callback' => array( $this, 'admin_check' ),
			)
		);
	}

	public function logged_in_check(): bool {
		return is_user_logged_in();
	}

	public function admin_check(): bool {
		return current_user_can( 'manage_options' );
	}

	public function handle_submit( WP_REST_Request $request ) {
		$result = $this->service->submit(
			get_current_user_id(),
			(string) $request->get_param( 'action_id' ),
			(string) $request->get_param( 'evidence' ),
			(string) $request->get_param( 'evidence_url' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => (int) $result ) );
	}

	public function handle_list( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) ( $request->get_param( 'status' ) ?? '' );
		$limit  = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$rows   = ( new \WBGam\Repository\SubmissionRepository() )->list( $status, $limit, 0 );
		return rest_ensure_response( array( 'rows' => $rows ) );
	}

	public function handle_approve( WP_REST_Request $request ) {
		$result = $this->service->approve(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request->get_param( 'notes' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function handle_reject( WP_REST_Request $request ) {
		$result = $this->service->reject(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request->get_param( 'notes' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
