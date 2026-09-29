<?php
/**
 * REST API: Tools Controller.
 *
 * Site-management utilities for administrators: settings import / export
 * (config portability between sites). All routes live under this plugin's own
 * namespace (wb-gamification/v1/tools), so they never collide with WordPress
 * core or BuddyPress REST routes.
 *
 * @package WB_Gamification
 * @since   1.5.3
 */

namespace WBGam\API;

use WBGam\Engine\SettingsIO;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Admin tools REST controller.
 *
 * @package WB_Gamification
 */
class ToolsController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wb-gamification/v1';

	/**
	 * Register the tools routes.
	 */
	public function register_routes(): void {
		// GET /tools/export-settings — download the current configuration.
		register_rest_route(
			$this->namespace,
			'/tools/export-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		// POST /tools/recompute-leaderboard — rebuild the snapshot + bust caches.
		register_rest_route(
			$this->namespace,
			'/tools/recompute-leaderboard',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'recompute_leaderboard' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		// POST /tools/reset-progress — wipe member progress (keeps config).
		register_rest_route(
			$this->namespace,
			'/tools/reset-progress',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_progress' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'confirm' => array(
							'required' => true,
							'type'     => 'boolean',
						),
					),
				),
			)
		);

		// POST /tools/retry-side-effect/{id} — re-fire one dead-lettered side effect.
		register_rest_route(
			$this->namespace,
			'/tools/retry-side-effect/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry_side_effect' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);

		// POST /tools/import-settings — apply a previously exported document.
		register_rest_route(
			$this->namespace,
			'/tools/import-settings',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'document' => array(
							'required'    => true,
							'type'        => 'object',
							'description' => 'A document produced by export-settings.',
						),
					),
				),
			)
		);
	}

	/**
	 * Admin-only gate. Importing/exporting settings is site management.
	 *
	 * @return true|WP_Error
	 */
	public function admin_permissions_check(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage settings.', 'wb-gamification' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}
		return true;
	}

	/**
	 * POST /tools/recompute-leaderboard.
	 *
	 * Rebuilds the leaderboard snapshot and invalidates every cached
	 * leaderboard / rank entry. The fast, safe fix for a stale leaderboard
	 * (same operation as `wp wb-gamification doctor --recompute-leaderboard`).
	 *
	 * @return WP_REST_Response
	 */
	public function recompute_leaderboard(): WP_REST_Response {
		\WBGam\Engine\LeaderboardEngine::write_snapshot();
		\WBGam\Engine\LeaderboardEngine::invalidate_cache();
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /tools/retry-side-effect/{id}.
	 *
	 * Manually re-fires one dead-lettered side effect (e.g. an 'exhausted' row
	 * the reconcile cron will not retry again), after the owner has fixed the
	 * underlying cause.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry_side_effect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = \WBGam\Engine\SideEffectDispatcher::retry( (int) $request['id'] );
		if ( ! $result['success'] ) {
			$status = 'not_found' === ( $result['reason'] ?? '' ) ? 404 : 409;
			return new WP_Error(
				'wb_gam_side_effect_' . ( $result['reason'] ?? 'retry_failed' ),
				__( 'Could not re-run this side effect. Check that the cause is fixed and try again.', 'wb-gamification' ),
				array( 'status' => $status )
			);
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /tools/reset-progress.
	 *
	 * Destructive: empties member-progress tables + progress meta, keeping all
	 * configuration and definitions. Requires explicit confirm = true on top of
	 * the admin permission gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_progress( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( true !== $request['confirm'] ) {
			return new WP_Error( 'rest_confirm_required', __( 'Resetting member progress must be explicitly confirmed.', 'wb-gamification' ), array( 'status' => 400 ) );
		}

		$result = \WBGam\Engine\ProgressReset::reset();

		return new WP_REST_Response( array( 'ok' => true ) + $result, 200 );
	}

	/**
	 * GET /tools/export-settings.
	 *
	 * @return WP_REST_Response
	 */
	public function export_settings(): WP_REST_Response {
		return new WP_REST_Response( SettingsIO::export(), 200 );
	}

	/**
	 * POST /tools/import-settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$document = $request['document'];
		if ( ! is_array( $document ) ) {
			return new WP_Error( 'rest_invalid_document', __( 'Import file is not a valid settings export.', 'wb-gamification' ), array( 'status' => 400 ) );
		}

		$result = SettingsIO::import( $document );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'rest_invalid_document', __( 'Import file is not a valid WB Gamification settings export.', 'wb-gamification' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $result, 200 );
	}
}
