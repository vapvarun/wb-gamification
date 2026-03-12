<?php
/**
 * WB Gamification Registry
 *
 * Central registry for all actions, badge triggers, and challenge types.
 * Auto-discovers everything registered via the extension API.
 * Admin reads this registry to render settings — no manual sync needed.
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

final class WB_Gam_Registry {

	/** @var array<string, array> */
	private static array $actions = [];

	/** @var array<string, array> */
	private static array $badge_triggers = [];

	/** @var array<string, array> */
	private static array $challenge_types = [];

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
	 *   @type string   $id             Unique action ID.
	 *   @type string   $label          Human-readable label (shown in admin).
	 *   @type string   $description    Optional description.
	 *   @type string   $hook           WordPress action hook to listen to.
	 *   @type callable $user_callback  Returns the user_id from hook arguments.
	 *   @type int      $default_points Default points awarded. Admin can override.
	 *   @type string   $category       Optional category (buddypress, commerce, etc.)
	 *   @type string   $icon           Dashicon name or URL.
	 *   @type bool     $repeatable     Whether the action can be performed multiple times.
	 *   @type int      $cooldown       Seconds between earnings (0 = unlimited).
	 * }
	 */
	public static function register_action( array $args ): void {
		$defaults = [
			'description'    => '',
			'category'       => 'general',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => true,
			'cooldown'       => 0,
		];

		$action = wp_parse_args( $args, $defaults );

		if ( empty( $action['id'] ) || empty( $action['hook'] ) || ! is_callable( $action['user_callback'] ) ) {
			_doing_it_wrong( __METHOD__, 'WB Gamification: action must have id, hook, and user_callback.', '0.1.0' );
			return;
		}

		self::$actions[ $action['id'] ] = $action;

		// Auto-hook to WordPress.
		add_action(
			$action['hook'],
			static function () use ( $action ) {
				$params  = func_get_args();
				$user_id = (int) call_user_func_array( $action['user_callback'], $params );
				if ( $user_id > 0 ) {
					WB_Gam_Points_Engine::process_action( $action['id'], $user_id );
				}
			}
		);
	}

	/**
	 * Register a badge trigger.
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
				if ( $user_id > 0 && call_user_func_array( $args['condition'], array_merge( $params, [ $user_id ] ) ) ) {
					WB_Gam_Badge_Engine::award_badge( $user_id, $args['id'] );
				}
			}
		);
	}

	/**
	 * Register a challenge type.
	 */
	public static function register_challenge_type( array $args ): void {
		if ( empty( $args['id'] ) || empty( $args['action_id'] ) ) {
			_doing_it_wrong( __METHOD__, 'WB Gamification: challenge type must have id and action_id.', '0.1.0' );
			return;
		}

		self::$challenge_types[ $args['id'] ] = wp_parse_args( $args, [ 'countable' => true ] );
	}

	/** @return array<string, array> */
	public static function get_actions(): array {
		return self::$actions;
	}

	/** @return array<string, array> */
	public static function get_badge_triggers(): array {
		return self::$badge_triggers;
	}

	/** @return array<string, array> */
	public static function get_challenge_types(): array {
		return self::$challenge_types;
	}

	public static function get_action( string $id ): ?array {
		return self::$actions[ $id ] ?? null;
	}
}
