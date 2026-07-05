<?php
/**
 * WB Gamification — competitor import REST controller.
 *
 * Backs the admin Import screen: detect which source plugins have data, run a
 * dry-run preview (reconciliation only, writes nothing), and run the real
 * import. Every route is admin-gated by `wb_gam_manage_members`. The actual
 * work lives in the importer classes (READ source, WRITE via ImportService).
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\API;

use WBGam\Engine\Capabilities;
use WBGam\Integrations\Importers\BadgeOSImporter;
use WBGam\Integrations\Importers\GamiPressImporter;
use WBGam\Integrations\Importers\MyCredImporter;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints for competitor imports.
 *
 * @package WB_Gamification
 */
final class ImportController {

	private const NS = 'wb-gamification/v1';

	/**
	 * Supported sources: slug => [label, importer class].
	 *
	 * @return array<string, array{label:string, class:class-string}>
	 */
	private static function sources(): array {
		return array(
			'gamipress' => array(
				'label' => 'GamiPress',
				'class' => GamiPressImporter::class,
			),
			'mycred'    => array(
				'label' => 'myCred',
				'class' => MyCredImporter::class,
			),
			'badgeos'   => array(
				'label' => 'BadgeOS',
				'class' => BadgeOSImporter::class,
			),
		);
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/import/sources',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_sources' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/import/(?P<source>[a-z]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_import' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'source'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * List sources with availability.
	 *
	 * @return WP_REST_Response
	 */
	public function list_sources(): WP_REST_Response {
		$out = array();
		foreach ( self::sources() as $slug => $meta ) {
			$class = $meta['class'];
			$out[] = array(
				'slug'      => $slug,
				'label'     => $meta['label'],
				'available' => (bool) $class::is_available(),
			);
		}
		return new WP_REST_Response( array( 'sources' => $out ), 200 );
	}

	/**
	 * Run (or preview) an import for one source.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_import( $request ) {
		$source  = (string) $request['source'];
		$dry_run = (bool) $request['dry_run'];
		$sources = self::sources();

		if ( ! isset( $sources[ $source ] ) ) {
			return new WP_Error( 'wb_gam_unknown_source', __( 'Unknown import source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		$class = $sources[ $source ]['class'];
		if ( ! $class::is_available() ) {
			return new WP_Error( 'wb_gam_source_unavailable', __( 'No data found for this source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $class::run( $dry_run ), 200 );
	}

	/**
	 * Only site managers may import.
	 *
	 * @return true|WP_Error
	 */
	public function permissions() {
		if ( Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'You are not allowed to run imports.', 'wb-gamification' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}
}
