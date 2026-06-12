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
		// WP 6.9+ Abilities API. Registration MUST happen on
		// `wp_abilities_api_init` per core's contract — using the generic
		// `init` hook triggers a `_doing_it_wrong` notice on every page
		// load and the abilities silently fail to register. The category
		// must be registered first, on its own earlier hook, or every
		// ability referencing it triggers the same notice and is dropped.
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		}

		// Fallback REST endpoint — works on any WP version.
		add_action( 'rest_api_init', array( __CLASS__, 'register_fallback_route' ) );
	}

	/**
	 * Register the "gamification" ability category.
	 *
	 * Both `label` and `description` are required — omitting either makes
	 * `wp_register_ability_category()` silently return null.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			'gamification',
			array(
				'label'       => __( 'Gamification', 'wb-gamification' ),
				'description' => __( 'Points, badges, levels, leaderboards, challenges, and streaks provided by WB Gamification.', 'wb-gamification' ),
			)
		);
	}

	/**
	 * Register each gamification ability with the WP Abilities API.
	 *
	 * The discovery metadata from get_abilities() is mapped onto the args
	 * core actually validates: `execute_callback` and `permission_callback`
	 * are REQUIRED — without them WP_Ability::prepare_properties() throws,
	 * the registry emits `_doing_it_wrong`, and the ability is dropped.
	 * Execution proxies to the documented REST route, so the controller's
	 * own permission_callback, validation, and sanitization still apply.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {
		foreach ( self::get_abilities() as $id => $ability ) {
			wp_register_ability(
				$id,
				array(
					'label'               => $ability['label'],
					'description'         => $ability['description'],
					'category'            => $ability['category'],
					'execute_callback'    => self::make_execute_callback( $ability ),
					'permission_callback' => self::make_permission_callback( $ability['auth'] ),
					'input_schema'        => self::make_input_schema( $ability ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => array( 'GET' ) === $ability['methods'],
							'destructive' => in_array( 'DELETE', $ability['methods'], true ),
							'idempotent'  => array( 'GET' ) === $ability['methods'],
						),
						'show_in_rest' => true,
					),
				)
			);
		}
	}

	/**
	 * Derive the ability's JSON input schema from its parameter metadata.
	 *
	 * Core refuses to execute an ability with input unless an input schema
	 * exists, so every ability gets at least an empty object schema. The
	 * per-parameter `required` booleans collapse into the object-level
	 * `required` array JSON Schema expects.
	 *
	 * @param array<string, mixed> $ability Ability definition from get_abilities().
	 * @return array<string, mixed>
	 */
	private static function make_input_schema( array $ability ): array {
		$properties = array();
		$required   = array();

		foreach ( (array) ( $ability['parameters'] ?? array() ) as $key => $param ) {
			if ( ! empty( $param['required'] ) ) {
				$required[] = $key;
			}
			unset( $param['required'] );
			$properties[ $key ] = $param;
		}

		if ( count( $ability['methods'] ) > 1 ) {
			$properties['method'] = array(
				'type'    => 'string',
				'enum'    => $ability['methods'],
				'default' => $ability['methods'][0],
			);
		}

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Build the execute callback that proxies an ability to its REST route.
	 *
	 * @param array<string, mixed> $ability Ability definition from get_abilities().
	 * @return callable
	 */
	private static function make_execute_callback( array $ability ): callable {
		return static function ( $input = array() ) use ( $ability ) {
			$input  = is_array( $input ) ? $input : array();
			$suffix = substr( (string) $ability['endpoint'], strlen( rest_url( self::NAMESPACE ) ) );
			$route  = '/' . self::NAMESPACE . $suffix;

			// Fill path placeholders like {id} from the input.
			$route = preg_replace_callback(
				'/\{([a-z_]+)\}/',
				static function ( array $matches ) use ( &$input ): string {
					$value = $input[ $matches[1] ] ?? '';
					unset( $input[ $matches[1] ] );
					return rawurlencode( (string) $value );
				},
				$route
			);

			// Multi-method abilities (e.g. manage-badges) pick via input.method.
			$method = strtoupper( (string) ( $input['method'] ?? $ability['methods'][0] ) );
			unset( $input['method'] );
			if ( ! in_array( $method, $ability['methods'], true ) ) {
				return new \WP_Error(
					'wb_gam_ability_method_not_allowed',
					sprintf(
						/* translators: 1: HTTP method, 2: comma-separated list of allowed methods. */
						__( 'Method %1$s is not supported by this ability. Allowed: %2$s.', 'wb-gamification' ),
						$method,
						implode( ', ', $ability['methods'] )
					)
				);
			}

			$request = new \WP_REST_Request( $method, $route );
			if ( 'GET' === $method ) {
				$request->set_query_params( $input );
			} else {
				$request->set_body_params( $input );
			}

			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				return $response->as_error();
			}

			return $response->get_data();
		};
	}

	/**
	 * Map an ability's documented auth level to a permission callback.
	 *
	 * The REST controller behind each ability enforces its own permissions
	 * on execution; this gate mirrors the documented auth level so clients
	 * get an upfront answer from the Abilities API.
	 *
	 * @param string $auth Auth level: 'none', 'optional', 'required', or 'admin'.
	 * @return callable
	 */
	private static function make_permission_callback( string $auth ): callable {
		switch ( $auth ) {
			case 'admin':
				return static function (): bool {
					return current_user_can( 'manage_options' );
				};
			case 'required':
				return static function (): bool {
					return is_user_logged_in();
				};
			default:
				// 'none' / 'optional' — the proxied endpoint is public.
				return static function (): bool {
					return true;
				};
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
				'description' => 'Complete gamification engine for WordPress - points, badges, levels, leaderboards, challenges, streaks.',
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
			'wb-gamification/read-leaderboard'   => array(
				'label'       => 'Read gamification leaderboard',
				'description' => 'Retrieve ranked member lists by points for any period (daily, weekly, monthly, all-time). Supports group scoping.',
				'endpoint'    => $base . '/leaderboard',
				'methods'     => array( 'GET' ),
				'parameters'  => array(
					// Period values must match LeaderboardController::get_scope_args()
					// — singular forms (`day`, `week`, `month`, `all`). Plural forms
					// (`daily`, `weekly`, `monthly`) get rejected by the controller's
					// sanitize_key + enum check. Enum-drift bug caught by
					// wppqa_check_enum_consistency 2026-05-03.
					'period' => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'day', 'week', 'month' ),
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
			'wb-gamification/read-member'        => array(
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
			'wb-gamification/read-badges'        => array(
				'label'       => 'List badge definitions',
				'description' => 'Get all available badges with their name, image, description, and auto-award criteria.',
				'endpoint'    => $base . '/badges',
				'methods'     => array( 'GET' ),
				'auth'        => 'none',
				'category'    => 'gamification',
			),
			'wb-gamification/read-challenges'    => array(
				'label'       => 'List active challenges',
				'description' => 'Get current challenges with target action, progress, deadline, and bonus points.',
				'endpoint'    => $base . '/challenges',
				'methods'     => array( 'GET' ),
				'auth'        => 'optional',
				'category'    => 'gamification',
			),
			'wb-gamification/read-actions'       => array(
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
			'wb-gamification/submit-event'       => array(
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
			'wb-gamification/award-points'       => array(
				'label'       => 'Manually award points',
				'description' => 'Award points to a user with a custom reason. Bypasses action rules - direct point grant.',
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
			'wb-gamification/give-kudos'         => array(
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
			'wb-gamification/manage-badges'      => array(
				'label'       => 'Manage badge definitions',
				'description' => 'Create, update, and delete badge definitions and their auto-award conditions.',
				'endpoint'    => $base . '/badges',
				'methods'     => array( 'GET', 'POST', 'PUT', 'DELETE' ),
				'auth'        => 'admin',
				'category'    => 'gamification',
			),
			'wb-gamification/manage-api-keys'    => array(
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
