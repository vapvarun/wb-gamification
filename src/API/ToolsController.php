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
