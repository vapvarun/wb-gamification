<?php
/**
 * WB Gamification Registry
 *
 * Central registry for all actions, badge triggers, and challenge types.
 * Auto-discovers everything registered via the extension API.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry for all gamification actions, badge triggers, and challenge types.
 *
 * @package WB_Gamification
 */
final class Registry {

	/**
	 * Registered gamification actions keyed by action ID.
	 *
	 * @var array<string, array>
	 */
	private static array $actions = array();

	/**
	 * Registered badge triggers keyed by trigger ID.
	 *
	 * @var array<string, array>
	 */
	private static array $badge_triggers = array();

	/**
	 * Registered challenge types keyed by type ID.
	 *
	 * @var array<string, array>
	 */
	private static array $challenge_types = array();

	/**
	 * Whether the registry has already been initialised.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Initialize the registry — fires 'wb_gamification_register' action
	 * so all extensions can register their actions.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		/**
		 * Hook for extensions to register their actions, badge triggers,
		 * and challenge types.
		 *
		 * @since 0.1.0
		 */
		do_action( 'wb_gamification_register' );
	}

	/**
	 * Register a gamification action.
	 *
	 * @param array $args {
	 *   Action definition array.
	 *
	 *   @type string   $id             Unique action ID.
	 *   @type string   $label          Human-readable label (shown in admin).
	 *   @type string   $description    Optional description.
	 *   @type string   $hook           WordPress action hook to listen to.
	 *   @type callable $user_callback  Returns the user_id from hook arguments.
	 *   @type int      $default_points Default points awarded. Admin can override.
	 *   @type string   $category       Optional category (buddypress, commerce, etc.).
	 *   @type string   $icon           Dashicon name or URL.
	 *   @type bool     $repeatable     Whether the action can be performed multiple times.
	 *   @type int      $cooldown       Seconds between earnings (0 = unlimited).
	 *   @type int      $daily_cap      Max times per day (0 = unlimited).
	 *   @type int      $weekly_cap     Max times per week (0 = unlimited).
	 * }
	 */
	public static function register_action( array $args ): void {
		$defaults = array(
			'description' => '',
			'category'    => 'general',
			'icon'        => 'dashicons-star-filled',
			'repeatable'  => true,
			'cooldown'    => 0,
			'daily_cap'   => 0,
			'weekly_cap'  => 0,
			// null = derive from repeatable: true → async, false → sync.
			'async'       => null,
		);

		$action = wp_parse_args( $args, $defaults );

		if ( empty( $action['id'] ) || empty( $action['hook'] ) || ! is_callable( $action['user_callback'] ) ) {
			_doing_it_wrong( __METHOD__, 'WB Gamification: action must have id, hook, and user_callback.', '0.1.0' );
			return;
		}

		if ( isset( self::$actions[ $action['id'] ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: action ID, 2: plugin name */
					'Gamification action ID "%1$s" is already registered by "%2$s". Use a unique vendor-prefixed ID (e.g. "myplugin/action_name").',
					$action['id'],
					self::$actions[ $action['id'] ]['plugin'] ?? 'unknown'
				),
				'1.0.0'
			);
			return;
		}

		self::$actions[ $action['id'] ] = $action;

		// Auto-hook to WordPress — routes through Engine::process() (Phase 0+).
		// $accepted_args=10 ensures hooks that pass multiple args (e.g. wp_login
		// passes $user_login + $user) forward all of them to user_callback.
		add_action(
			$action['hook'],
			static function () use ( $action ) {
				$params  = func_get_args();
				$user_id = (int) call_user_func_array( $action['user_callback'], $params );
				if ( $user_id <= 0 ) {
					return;
				}

				// Optionally extract metadata from hook args via metadata_callback.
				$metadata = isset( $action['metadata_callback'] ) && is_callable( $action['metadata_callback'] )
					? (array) call_user_func_array( $action['metadata_callback'], $params )
					: array();

				$event = new Event(
					array(
						'action_id' => $action['id'],
						'user_id'   => $user_id,
						'metadata'  => $metadata,
					)
				);

				// Repeatable actions run async by default — high-volume and must not
				// block the request path. Non-repeatable once-only actions run sync
				// so callers get immediate confirmation. $action['async'] overrides.
				if ( $action['async'] ?? $action['repeatable'] ) {
					Engine::process_async( $event );
				} else {
					Engine::process( $event );
				}
			},
			10,
			10
		);
	}

	/**
	 * Register a badge trigger.
	 *
	 * @param array $args Badge trigger definition (id, hook, condition).
	 */
	public static function register_badge_trigger( array $args ): void {
		if ( empty( $args['id'] ) || empty( $args['hook'] ) || ! is_callable( $args['condition'] ) ) {
			_doing_it_wrong( __METHOD__, 'WB Gamification: badge trigger must have id, hook, and condition.', '0.1.0' );
			return;
		}

		self::$badge_triggers[ $args['id'] ] = $args;

		add_action(
			$args['hook'],
			static function () use ( $args ) {
				$params  = func_get_args();
				$user_id = get_current_user_id();
				if ( $user_id > 0 && call_user_func_array( $args['condition'], array_merge( $params, array( $user_id ) ) ) ) {
					BadgeEngine::award_badge( $user_id, $args['id'] );
				}
			}
		);
	}

	/**
	 * Register a challenge type.
	 *
	 * @param array $args Challenge type definition (id, action_id, countable).
	 */
	public static function register_challenge_type( array $args ): void {
		if ( empty( $args['id'] ) || empty( $args['action_id'] ) ) {
			_doing_it_wrong( __METHOD__, 'WB Gamification: challenge type must have id and action_id.', '0.1.0' );
			return;
		}

		self::$challenge_types[ $args['id'] ] = wp_parse_args( $args, array( 'countable' => true ) );
	}

	/**
	 * Get all registered actions.
	 *
	 * @return array<string, array>
	 */
	public static function get_actions(): array {
		return self::$actions;
	}

	/**
	 * Get all registered badge triggers.
	 *
	 * @return array<string, array>
	 */
	public static function get_badge_triggers(): array {
		return self::$badge_triggers;
	}

	/**
	 * Get all registered challenge types.
	 *
	 * @return array<string, array>
	 */
	public static function get_challenge_types(): array {
		return self::$challenge_types;
	}

	/**
	 * Get a single registered action by ID.
	 *
	 * @param string $id Action ID to look up.
	 * @return array|null Action definition or null if not registered.
	 */
	public static function get_action( string $id ): ?array {
		return self::$actions[ $id ] ?? null;
	}
}
