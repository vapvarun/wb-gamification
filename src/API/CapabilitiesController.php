<?php
/**
 * REST API: Capabilities Controller
 *
 * Discovery endpoint for mobile apps and remote sites. Returns the
 * authentication status, available permissions, enabled features,
 * and all known endpoint URLs for the current auth context.
 *
 * GET /wp-json/wb-gamification/v1/capabilities
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WBGam\Engine\FeatureFlags;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for gamification API capabilities discovery.
 *
 * Handles GET /wb-gamification/v1/capabilities.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class CapabilitiesController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wb-gamification/v1';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/capabilities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_capabilities' ),
					'permission_callback' => '__return_true',
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Return the capabilities and features available to the current auth context.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing capabilities data.
	 */
	public function get_capabilities( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$is_admin  = current_user_can( 'manage_options' );
		$is_authed = $user_id > 0;
		$site_id   = $GLOBALS['wb_gam_remote_site_id'] ?? '';

		$capabilities = array(
			'authenticated' => $is_authed,
			'user_id'       => $user_id,
			'site_id'       => $site_id,
			'mode'          => $site_id ? 'remote' : 'local',
			'can'           => array(
				'read_leaderboard'  => true,
				'read_badges'       => true,
				'read_own_profile'  => $is_authed,
				'read_any_profile'  => $is_admin,
				'award_points'      => $is_admin,
				'revoke_points'     => $is_admin,
				'manage_badges'     => $is_admin,
				'manage_challenges' => $is_admin,
				'manage_rules'      => $is_admin,
				'manage_webhooks'   => $is_admin,
				'submit_events'     => $is_authed,
				'give_kudos'        => $is_authed,
				'redeem_points'     => $is_authed,
				'manage_api_keys'   => $is_admin,
			),
			'features'      => FeatureFlags::get_all(),
			'version'       => WB_GAM_VERSION,
			'endpoints'     => array(
				'members'      => rest_url( $this->namespace . '/members' ),
				'leaderboard'  => rest_url( $this->namespace . '/leaderboard' ),
				'badges'       => rest_url( $this->namespace . '/badges' ),
				'challenges'   => rest_url( $this->namespace . '/challenges' ),
				'events'       => rest_url( $this->namespace . '/events' ),
				'actions'      => rest_url( $this->namespace . '/actions' ),
				'kudos'        => rest_url( $this->namespace . '/kudos' ),
				'capabilities' => rest_url( $this->namespace . '/capabilities' ),
			),
		);

		return new WP_REST_Response( $capabilities, 200 );
	}

	/**
	 * Retrieve the JSON schema for the capabilities response.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-capabilities',
			'type'       => 'object',
			'properties' => array(
				'authenticated' => array(
					'type'        => 'boolean',
					'description' => 'Whether the request is authenticated.',
				),
				'user_id'       => array(
					'type'        => 'integer',
					'description' => 'Authenticated user ID, or 0 if unauthenticated.',
				),
				'site_id'       => array(
					'type'        => 'string',
					'description' => 'Remote site identifier, empty for local mode.',
				),
				'mode'          => array(
					'type'        => 'string',
					'enum'        => array( 'local', 'remote' ),
					'description' => 'Deployment mode: local (same site) or remote (API key).',
				),
				'can'           => array(
					'type'        => 'object',
					'description' => 'Permission map for the current auth context.',
				),
				'features'      => array(
					'type'        => 'object',
					'description' => 'Feature flags and their current state.',
				),
				'version'       => array(
					'type'        => 'string',
					'description' => 'Plugin version.',
				),
				'endpoints'     => array(
					'type'        => 'object',
					'description' => 'Map of endpoint names to their full REST URLs.',
				),
			),
		);
	}
}
