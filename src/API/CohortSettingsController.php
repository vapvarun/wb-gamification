<?php
/**
 * REST API: Cohort Settings Controller
 *
 * GET  /wb-gamification/v1/cohort-settings   Read current cohort settings
 * POST /wb-gamification/v1/cohort-settings   Save cohort settings (full document)
 *
 * Cohort settings are stored as a single options row (`wb_gam_cohort_settings`)
 * plus the `cohort_leagues` feature flag in `FeatureFlags`. The endpoint reads
 * and writes both together so the admin UI can save with one round-trip.
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
use WBGam\Engine\Capabilities;
use WBGam\Engine\FeatureFlags;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for cohort league settings.
 *
 * The admin Cohort Settings page formerly POSTed to admin-post.php; this
 * controller is the canonical write surface. The page now consumes this
 * endpoint via fetch() (Tier 0.C). Mobile + 3rd-party apps see the same API.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class CohortSettingsController extends WP_REST_Controller {

	/**
	 * Option key holding the cohort settings document.
	 */
	private const OPTION_KEY = 'wb_gam_cohort_settings';

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
	protected $rest_base = 'cohort-settings';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->update_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Capability gate for read + write.
	 *
	 * Cohort tiers shape competitive rank and are exposed on profiles, so
	 * they are admin-only. Returns WP_Error(403) for clients without
	 * permission, matching the REST contract.
	 *
	 * @return true|WP_Error
	 */
	public function admin_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) || Capabilities::user_can( 'wb_gam_manage_challenges' ) ) {
			return true;
		}
		return new WP_Error(
			'wb_gam_rest_forbidden',
			__( 'You do not have permission to manage cohort settings.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Read the current cohort settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		return new WP_REST_Response( $this->current_state(), 200 );
	}

	/**
	 * Save cohort settings.
	 *
	 * Payload includes tier names, promote/demote percentages, duration,
	 * and the `enabled` toggle. The endpoint validates ranges and persists
	 * both the option document and the feature flag in one transaction.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$settings = array(
			'tier_1'      => (string) $request->get_param( 'tier_1' ),
			'tier_2'      => (string) $request->get_param( 'tier_2' ),
			'tier_3'      => (string) $request->get_param( 'tier_3' ),
			'tier_4'      => (string) $request->get_param( 'tier_4' ),
			'promote_pct' => (int) $request->get_param( 'promote_pct' ),
			'demote_pct'  => (int) $request->get_param( 'demote_pct' ),
			'duration'    => (string) $request->get_param( 'duration' ),
		);

		$enabled = (bool) $request->get_param( 'enabled' );

		/**
		 * Filter — abort the save by returning WP_Error.
		 *
		 * @param array           $settings Sanitised settings document.
		 * @param bool            $enabled  Whether cohort leagues are enabled.
		 * @param WP_REST_Request $request  Request.
		 */
		$filtered = apply_filters( 'wb_gam_before_save_cohort_settings', $settings, $enabled, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$settings = $filtered;
		}

		update_option( self::OPTION_KEY, $settings );

		$features                    = FeatureFlags::get_all();
		$features['cohort_leagues']  = $enabled;
		FeatureFlags::update( $features );

		do_action( 'wb_gam_after_save_cohort_settings', $settings, $enabled, $request );
		// Backwards-compatible legacy hook (kept until 1.1.0).
		do_action( 'wb_gamification_cohort_settings_saved', $settings, $enabled );

		return new WP_REST_Response( $this->current_state(), 200 );
	}

	/**
	 * Build the canonical state document combining settings + feature flag.
	 *
	 * @return array<string, mixed>
	 */
	private function current_state(): array {
		$settings = (array) get_option( self::OPTION_KEY, array() );
		$features = FeatureFlags::get_all();

		return array(
			'tier_1'      => isset( $settings['tier_1'] ) ? (string) $settings['tier_1'] : 'Bronze',
			'tier_2'      => isset( $settings['tier_2'] ) ? (string) $settings['tier_2'] : 'Silver',
			'tier_3'      => isset( $settings['tier_3'] ) ? (string) $settings['tier_3'] : 'Gold',
			'tier_4'      => isset( $settings['tier_4'] ) ? (string) $settings['tier_4'] : 'Diamond',
			'promote_pct' => isset( $settings['promote_pct'] ) ? (int) $settings['promote_pct'] : 20,
			'demote_pct'  => isset( $settings['demote_pct'] ) ? (int) $settings['demote_pct'] : 20,
			'duration'    => isset( $settings['duration'] ) ? (string) $settings['duration'] : 'weekly',
			'enabled'     => ! empty( $features['cohort_leagues'] ),
		);
	}

	/**
	 * REST args schema for the save endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function update_args(): array {
		return array(
			'tier_1'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tier_2'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tier_3'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tier_4'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'promote_pct' => array(
				'required' => true,
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 50,
			),
			'demote_pct'  => array(
				'required' => true,
				'type'     => 'integer',
				'minimum'  => 1,
				'maximum'  => 50,
			),
			'duration'    => array(
				'required' => true,
				'type'     => 'string',
				'enum'     => array( 'weekly', 'monthly' ),
			),
			'enabled'     => array(
				'required' => true,
				'type'     => 'boolean',
			),
		);
	}

	/**
	 * JSON schema for the cohort-settings document.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-cohort-settings',
			'type'       => 'object',
			'properties' => array(
				'tier_1'      => array( 'type' => 'string' ),
				'tier_2'      => array( 'type' => 'string' ),
				'tier_3'      => array( 'type' => 'string' ),
				'tier_4'      => array( 'type' => 'string' ),
				'promote_pct' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'demote_pct'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'duration'    => array(
					'type' => 'string',
					'enum' => array( 'weekly', 'monthly' ),
				),
				'enabled'     => array( 'type' => 'boolean' ),
			),
		);
	}
}
