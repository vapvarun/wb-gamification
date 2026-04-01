<?php
/**
 * WP Abilities API Registration
 *
 * Registers gamification abilities with the WP Abilities API (6.9+)
 * and provides a fallback REST endpoint for older WP versions.
 * Enables AI agents (Claude, GPT, Gemini) and mobile apps to discover
 * what the gamification API offers.
 *
 * @package WB_Gamification
 */

namespace WBGam\API;

defined( 'ABSPATH' ) || exit;

/**
 * Registers gamification abilities with the WP Abilities API (6.9+).
 * Provides a fallback REST endpoint for older WP versions.
 *
 * @since 1.0.0
 */
final class AbilitiesRegistration {

	/**
	 * REST namespace for all gamification routes.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'wb-gamification/v1';

	/**
	 * Boot abilities registration.
	 *
	 * Hooks into the WP Abilities API when available (WP 6.9+),
	 * and always registers the fallback REST endpoint.
	 *
	 * @return void
	 */
	public static function init(): void {
		// WP 6.9+ Abilities API.
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'init', array( __CLASS__, 'register_abilities' ) );
		}

		// Fallback REST endpoint — works on any WP version.
		add_action( 'rest_api_init', array( __CLASS__, 'register_fallback_route' ) );
	}

	/**
	 * Register each gamification ability with the WP Abilities API.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		foreach ( self::get_abilities() as $id => $ability ) {
			wp_register_ability( $id, $ability );
		}
	}

	/**
	 * Register the fallback /abilities REST route.
	 *
	 * @return void
	 */
	public static function register_fallback_route(): void {
		register_rest_route(
			self::NAMESPACE,
			'/abilities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_abilities_response' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Build the REST response for the /abilities endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_abilities_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'plugin'      => 'wb-gamification',
				'version'     => WB_GAM_VERSION,
				'description' => 'Complete gamification engine for WordPress — points, badges, levels, leaderboards, challenges, streaks.',
				'abilities'   => self::get_abilities(),
			),
			200
		);
	}

	/**
	 * All gamification abilities with their metadata.
	 *
	 * Each ability describes an API capability — its endpoint, HTTP methods,
	 * parameters, and auth requirements — so AI agents and mobile apps
	 * can discover and invoke the right operations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_abilities(): array {
		$base = rest_url( self::NAMESPACE );

		return array(
			// === Read abilities ===
			'wb-gamification/read-leaderboard'  => array(
				'label'       => 'Read gamification leaderboard',
				'description' => 'Retrieve ranked member lists by points for any period (daily, weekly, monthly, all-time). Supports group scoping.',
				'endpoint'    => $base . '/leaderboard',
				'methods'     => array( 'GET' ),
				'parameters'  => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => array( 'daily', 'weekly', 'monthly', 'all' ),
						'default' => 'all',
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 10,
						'maximum' => 100,
					),
				),
				'auth'        => 'none',
				'category'    => 'gamification',
			),
			'wb-gamification/read-member'       => array(
				'label'       => 'Read member gamification profile',
				'description' => 'Get a member\'s total points, current level, earned badges, active streak, and rank.',
				'endpoint'    => $base . '/members/{id}',
				'methods'     => array( 'GET' ),
				'parameters'  => array(
					'id' => array(
						'type'        => 'integer',
						'description' => 'WordPress user ID',
						'required'    => true,
					),
				),
				'auth'        => 'optional',
				'category'    => 'gamification',
			),
			'wb-gamification/read-badges'       => array(
				'label'       => 'List badge definitions',
				'description' => 'Get all available badges with their name, image, description, and auto-award criteria.',
				'endpoint'    => $base . '/badges',
				'methods'     => array( 'GET' ),
				'auth'        => 'none',
				'category'    => 'gamification',
			),
			'wb-gamification/read-challenges'   => array(
				'label'       => 'List active challenges',
				'description' => 'Get current challenges with target action, progress, deadline, and bonus points.',
				'endpoint'    => $base . '/challenges',
				'methods'     => array( 'GET' ),
				'auth'        => 'optional',
				'category'    => 'gamification',
			),
			'wb-gamification/read-actions'      => array(
				'label'       => 'List registered gamification actions',
				'description' => 'Enumerate all point-earning actions with their values, cooldowns, and categories.',
				'endpoint'    => $base . '/actions',
				'methods'     => array( 'GET' ),
				'auth'        => 'none',
				'category'    => 'gamification',
			),
			'wb-gamification/read-member-badges' => array(
				'label'       => 'Get member earned badges',
				'description' => 'List all badges a specific member has earned with dates.',
				'endpoint'    => $base . '/members/{id}/badges',
				'methods'     => array( 'GET' ),
				'auth'        => 'optional',
				'category'    => 'gamification',
			),
			'wb-gamification/read-member-streak' => array(
				'label'       => 'Get member streak data',
				'description' => 'Current streak count, longest streak, and contribution heatmap.',
				'endpoint'    => $base . '/members/{id}/streak',
				'methods'     => array( 'GET' ),
				'auth'        => 'optional',
				'category'    => 'gamification',
			),

			// === Write abilities ===
			'wb-gamification/submit-event'      => array(
				'label'       => 'Submit a gamification event',
				'description' => 'Report a user action (e.g. post published, course completed) for point evaluation. The engine applies rules, cooldowns, and multipliers automatically.',
				'endpoint'    => $base . '/events',
				'methods'     => array( 'POST' ),
				'parameters'  => array(
					'action_id' => array(
						'type'        => 'string',
						'description' => 'Registered action ID',
						'required'    => true,
					),
					'user_id'   => array(
						'type'        => 'integer',
						'description' => 'User who performed the action',
						'required'    => true,
					),
					'object_id' => array(
						'type'        => 'integer',
						'description' => 'Related object ID (post, comment, etc.)',
						'default'     => 0,
					),
					'metadata'  => array(
						'type'        => 'object',
						'description' => 'Additional context',
						'default'     => new \stdClass(),
					),
				),
				'auth'        => 'required',
				'category'    => 'gamification',
			),
			'wb-gamification/award-points'      => array(
				'label'       => 'Manually award points',
				'description' => 'Award points to a user with a custom reason. Bypasses action rules — direct point grant.',
				'endpoint'    => $base . '/events',
				'methods'     => array( 'POST' ),
				'parameters'  => array(
					'action_id' => array(
						'type'    => 'string',
						'default' => 'manual',
					),
					'user_id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'metadata'  => array(
						'type'       => 'object',
						'properties' => array(
							'points' => array(
								'type'     => 'integer',
								'required' => true,
							),
							'reason' => array(
								'type' => 'string',
							),
						),
					),
				),
				'auth'        => 'admin',
				'category'    => 'gamification',
			),
			'wb-gamification/give-kudos'        => array(
				'label'       => 'Give kudos to another member',
				'description' => 'Send peer recognition with a message. Subject to daily limit.',
				'endpoint'    => $base . '/kudos',
				'methods'     => array( 'POST' ),
				'parameters'  => array(
					'receiver_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'message'     => array(
						'type'      => 'string',
						'maxLength' => 280,
					),
				),
				'auth'        => 'required',
				'category'    => 'gamification',
			),

			// === Admin abilities ===
			'wb-gamification/manage-badges'     => array(
				'label'       => 'Manage badge definitions',
				'description' => 'Create, update, and delete badge definitions and their auto-award conditions.',
				'endpoint'    => $base . '/badges',
				'methods'     => array( 'GET', 'POST', 'PUT', 'DELETE' ),
				'auth'        => 'admin',
				'category'    => 'gamification',
			),
			'wb-gamification/manage-api-keys'   => array(
				'label'       => 'Manage API keys',
				'description' => 'Create, list, revoke, and delete API keys for cross-site gamification access.',
				'endpoint'    => $base . '/api-keys',
				'methods'     => array( 'GET', 'POST', 'DELETE' ),
				'auth'        => 'admin',
				'category'    => 'gamification',
			),
		);
	}
}
