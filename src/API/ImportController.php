<?php
/**
 * WB Gamification: competitor import REST controller.
 *
 * Backs the admin Import screen and any app or script: detect which source plugins have data,
 * preview a run (counts only, writes nothing), start a real import in the background, read its
 * progress, and resume it if the queue stalled.
 *
 *   GET  /import/sources                  Sources, availability and each one's latest run
 *   POST /import/{source}                 dry_run=true (default) previews; dry_run=false starts (202)
 *   GET  /import/{source}/progress        Phase, counts, percent, mismatches, `stalled`
 *   POST /import/{source}/resume          Continue a stalled or failed run from its checkpoint
 *   POST /import/{source}/undo            dry_run=true (default) counts what would be removed; dry_run=false
 *                                         with confirm=true removes it in the background (202)
 *
 * Every route is admin-gated by `wb_gam_manage_members`, except undo, which deletes data and needs
 * `manage_options` (the same bar as the plugin's progress reset). The work lives in ImportRunner (paged,
 * resumable, one page per background job) and the importer classes (READ the source, one keyset
 * page at a time); this controller only translates HTTP.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\API;

use WBGam\Engine\Capabilities;
use WBGam\Engine\ImportLedger;
use WBGam\Engine\ImportRunner;
use WBGam\Engine\ImportUndo;
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
		register_rest_route(
			self::NS,
			'/import/(?P<source>[a-z]+)/progress',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'progress' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/import/(?P<source>[a-z]+)/undo',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'undo' ),
					'permission_callback' => array( $this, 'admin_permissions' ),
					'args'                => array(
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'confirm' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/import/(?P<source>[a-z]+)/resume',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resume' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
	}

	/**
	 * List sources with availability, whether anything imported is still here, and each one's latest run.
	 *
	 * @return WP_REST_Response
	 */
	public function list_sources(): WP_REST_Response {
		$out = array();
		foreach ( ImportRunner::sources() as $slug => $meta ) {
			$class = $meta['class'];
			$run   = ImportRunner::progress( $slug );
			$out[] = array(
				'slug'      => $slug,
				'label'     => $meta['label'],
				'available' => (bool) $class::is_available(),
				// Undo needs neither the source plugin nor a saved run: imported data is found by prefix.
				'imported'  => '' !== $class::KEY_PREFIX && ImportLedger::has_imported( $class::KEY_PREFIX, $class::BADGE_PREFIX ),
				'run'       => array(
					'status'  => $run['status'],
					'phase'   => $run['phase'],
					'percent' => $run['percent'],
					'stalled' => $run['stalled'],
				),
			);
		}
		return new WP_REST_Response( array( 'sources' => $out ), 200 );
	}

	/**
	 * Preview (dry_run) or start (background) an import for one source.
	 *
	 * A real run answers 202 at once with the run's state; the work happens in chained background
	 * jobs, one page each, and the caller polls /progress. Answering 200 with the finished result, as
	 * this route used to, meant one request held the whole source in memory.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_import( $request ) {
		$source = (string) $request['source'];

		if ( (bool) $request['dry_run'] ) {
			$preview = ImportRunner::preview( $source );
			return is_wp_error( $preview ) ? $preview : new WP_REST_Response( $preview, 200 );
		}

		$started = ImportRunner::start( $source );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		return new WP_REST_Response( ImportRunner::progress( $source ), 202 );
	}

	/**
	 * A source's latest run: phase, counts, percent, mismatches, and whether it has stalled.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function progress( $request ) {
		$source = (string) $request['source'];
		if ( null === ImportRunner::source_class( $source ) ) {
			return new WP_Error( 'wb_gam_unknown_source', __( 'Unknown import source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( ImportRunner::progress( $source ), 200 );
	}

	/**
	 * Continue a stalled or failed run from its last checkpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume( $request ) {
		$source  = (string) $request['source'];
		$resumed = ImportRunner::resume( $source );
		if ( is_wp_error( $resumed ) ) {
			return $resumed;
		}
		return new WP_REST_Response( ImportRunner::progress( $source ), 202 );
	}

	/**
	 * Take an import back out: preview what would go, or remove it in the background.
	 *
	 * A real undo needs `confirm=true` as well as `dry_run=false`: a request that merely forgot a flag
	 * must never delete data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( $request ) {
		$source = (string) $request['source'];

		if ( (bool) $request['dry_run'] ) {
			$preview = ImportUndo::preview( $source );
			return is_wp_error( $preview ) ? $preview : new WP_REST_Response( $preview, 200 );
		}

		if ( ! (bool) $request['confirm'] ) {
			return new WP_Error(
				'wb_gam_confirm_required',
				__( 'Removing imported data needs confirm=true. Preview it first with dry_run=true.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		$started = ImportRunner::start_undo( $source );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		return new WP_REST_Response( ImportRunner::progress( $source ), 202 );
	}

	/**
	 * Only site owners may delete imported data.
	 *
	 * @return true|WP_Error
	 */
	public function admin_permissions() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'Only a site administrator can remove imported data.', 'wb-gamification' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
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
