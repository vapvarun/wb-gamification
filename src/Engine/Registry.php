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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

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
	 * Initialize the registry — fires 'wb_gam_register' action
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
		do_action( 'wb_gam_register' );
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
			'icon'        => 'icon-star',
			'repeatable'  => true,
			'cooldown'    => 0,
			'daily_cap'   => 0,
			'weekly_cap'  => 0,
			// null = derive from repeatable: true → async, false → sync.
			'async'       => null,
			// Empty string = primary point type (resolved at process() time).
			'point_type'  => '',
		);

		$action = wp_parse_args( $args, $defaults );

		// Validate point_type slug shape (we don't hit the DB here — resolution
		// happens at award-time in PointTypeService::resolve(), which falls back
		// to the primary type if the slug isn't registered).
		if ( '' !== $action['point_type'] ) {
			$action['point_type'] = (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $action['point_type'] ) );
		}

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
					esc_html( $action['id'] ),
					esc_html( self::$actions[ $action['id'] ]['plugin'] ?? 'unknown' )
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
			static function ( ...$params ) use ( $action ) {
				$user_id = (int) call_user_func_array( $action['user_callback'], $params );
				if ( $user_id <= 0 ) {
					/** This filter is documented in src/Engine/PointsEngine.php — see wb_gam_award_skipped. */
					do_action(
						'wb_gam_award_skipped',
						$user_id,
						(string) $action['id'],
						'self_action',
						array()
					);
					return;
				}

				// Optionally extract metadata from hook args via metadata_callback.
				$metadata = isset( $action['metadata_callback'] ) && is_callable( $action['metadata_callback'] )
					? (array) call_user_func_array( $action['metadata_callback'], $params )
					: array();

				// Dynamic point scaling — when the manifest declares a
				// points_callback, invoke it with the hook args so the action
				// can scale points by rank, streak length, order total, etc.
				// Result is stashed in metadata['_dynamic_points'] and picked
				// up by Engine::process() in place of default_points. The
				// metadata field travels through Action Scheduler intact, so
				// the value computed here is still authoritative when the
				// async job runs later. Returning 0 or a negative value falls
				// back to default_points (Engine::process drops awards at 0
				// regardless).
				if ( isset( $action['points_callback'] ) && is_callable( $action['points_callback'] ) ) {
					$dynamic = (int) call_user_func_array( $action['points_callback'], $params );
					if ( $dynamic > 0 ) {
						$metadata['_dynamic_points'] = $dynamic;
					}
				}

				// Resolve the currency this action awards via the canonical
				// helper so both ledger-write AND rate-limit checks see the
				// same value. PointsEngine::insert_point_row() and
				// Engine::persist_event() read metadata['point_type'] when set.
				if ( ! isset( $metadata['point_type'] ) ) {
					$resolved = self::resolve_action_point_type( $action );
					if ( '' !== $resolved ) {
						$metadata['point_type'] = $resolved;
					}
				}

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
				$params = func_get_args();

				// Prefer user_callback (works in cron/CLI); fall back to get_current_user_id().
				$user_id = isset( $args['user_callback'] ) && is_callable( $args['user_callback'] )
					? (int) call_user_func_array( $args['user_callback'], $params )
					: get_current_user_id();

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
		$overrides = self::get_overrides();
		if ( empty( $overrides ) ) {
			return self::$actions;
		}
		$out = array();
		foreach ( self::$actions as $id => $action ) {
			$out[ $id ] = self::apply_overrides( $action, $overrides );
		}
		return $out;
	}

	/**
	 * Read the per-action override option.
	 *
	 * Site admins can edit `cooldown`, `daily_cap`, and `weekly_cap` per
	 * action without forking the manifest. The override row is stored in
	 * the `wb_gam_action_overrides` option, keyed by action_id.
	 *
	 * Empty / missing override values fall through to manifest defaults.
	 *
	 * @return array<string, array{cooldown?:int, daily_cap?:int, weekly_cap?:int}>
	 */
	private static function get_overrides(): array {
		$opt = get_option( 'wb_gam_action_overrides', array() );
		return is_array( $opt ) ? $opt : array();
	}

	/**
	 * Layer admin overrides onto a manifest action definition.
	 *
	 * Override keys: cooldown (seconds), daily_cap (count), weekly_cap (count).
	 * A value of 0 means "no limit"; only positive overrides are applied so
	 * an empty override row doesn't silently disable a manifest cap.
	 *
	 * @param array $action    Manifest action.
	 * @param array $overrides Full overrides option (see `get_overrides()`).
	 * @return array Action with admin overrides applied.
	 */
	private static function apply_overrides( array $action, array $overrides ): array {
		$id = $action['id'] ?? '';
		if ( '' === $id || empty( $overrides[ $id ] ) || ! is_array( $overrides[ $id ] ) ) {
			return $action;
		}
		foreach ( array( 'cooldown', 'daily_cap', 'weekly_cap' ) as $key ) {
			if ( isset( $overrides[ $id ][ $key ] ) ) {
				$val = (int) $overrides[ $id ][ $key ];
				if ( $val >= 0 ) {
					$action[ $key ] = $val;
				}
			}
		}
		return $action;
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
	 * Suggest registered action IDs similar to an unknown one.
	 *
	 * Used by the Engine to turn silent "action not in registry" misses into
	 * actionable "did you mean…" hints — the #1 footgun is a typo'd action_id
	 * that resolves to `$action === null`, drops `$points` to 0, and returns
	 * false from `Engine::process()` without surfacing anything to the caller
	 * or the debug log. Worked example: `wc_product_review` (singular) ->
	 * suggests `wc_product_reviewed` (manifest's canonical past-tense form).
	 *
	 * Matching strategy (combined for breadth):
	 *   1. Substring match — catches singular/plural and missing tense
	 *      affixes (`wc_product_review` ⊂ `wc_product_reviewed`).
	 *   2. Levenshtein distance ≤ 3 — catches transpositions and single-char
	 *      typos.
	 *
	 * Results are deduplicated and ranked by score, capped at $limit. When
	 * nothing fits the bar, an empty array is returned — "no suggestions"
	 * is more honest than namespace-prefix noise that doesn't help the
	 * caller. The owning plugin may simply be inactive, in which case the
	 * canonical action isn't registered to be suggested.
	 *
	 * @since 1.5.0
	 *
	 * @param string $action_id The unknown / typo'd action_id.
	 * @param int    $limit     Max suggestions to return.
	 * @return string[] Suggested action IDs, best-match first.
	 */
	public static function suggest_similar( string $action_id, int $limit = 3 ): array {
		if ( '' === $action_id || $limit <= 0 ) {
			return array();
		}

		$candidates = array_keys( self::$actions );
		if ( empty( $candidates ) ) {
			return array();
		}

		$lower  = strtolower( $action_id );
		$scored = array();

		foreach ( $candidates as $candidate ) {
			$cand_lower = strtolower( $candidate );

			// Substring either direction — strongest signal.
			if ( false !== strpos( $cand_lower, $lower ) || false !== strpos( $lower, $cand_lower ) ) {
				$scored[ $candidate ] = -1;
				continue;
			}

			$distance = levenshtein( $lower, $cand_lower );
			if ( $distance <= 3 ) {
				$scored[ $candidate ] = $distance;
			}
		}

		if ( empty( $scored ) ) {
			return array();
		}

		asort( $scored );

		return array_slice( array_keys( $scored ), 0, $limit );
	}

	/**
	 * Get a single registered action by ID.
	 *
	 * @param string $id Action ID to look up.
	 * @return array|null Action definition or null if not registered.
	 */
	public static function get_action( string $id ): ?array {
		if ( ! isset( self::$actions[ $id ] ) ) {
			return null;
		}
		$overrides = self::get_overrides();
		return empty( $overrides )
			? self::$actions[ $id ]
			: self::apply_overrides( self::$actions[ $id ], $overrides );
	}

	/**
	 * Resolve an action_id to a human-readable label.
	 *
	 * Used by surfaces that display history rows (points-history block,
	 * the [wb_gam_points_history] shortcode, the REST history response,
	 * admin diagnostics) so members see "Write a meaningful comment"
	 * instead of "mvs_give_comment".
	 *
	 * Resolution order:
	 *   1. Manifest `label` field, if the action is currently registered.
	 *   2. Built-in label for engine-emitted action_ids (manual award,
	 *      manual debit, redemption, debit) that have no manifest entry
	 *      because they're fired directly by the engine, not by a trigger.
	 *   3. Title-cased action_id (e.g. "Mvs Give Comment") as a final
	 *      fallback so a deactivated plugin doesn't leave history rows
	 *      with an unrecognisable identifier.
	 *
	 * @since 1.0.1
	 *
	 * @param string $action_id Action identifier (may be from a deactivated plugin).
	 * @return string Display label. Always returns a non-empty string when
	 *                $action_id is non-empty.
	 */
	public static function label_for( string $action_id ): string {
		if ( '' === $action_id ) {
			return '';
		}

		$action = self::get_action( $action_id );
		if ( is_array( $action ) && ! empty( $action['label'] ) ) {
			return (string) $action['label'];
		}

		$built_in = array(
			'manual'       => __( 'Manual award', 'wb-gamification' ),
			'manual_award' => __( 'Manual award', 'wb-gamification' ),
			'manual_debit' => __( 'Manual adjustment', 'wb-gamification' ),
			'debit'        => __( 'Debit', 'wb-gamification' ),
			'redemption'   => __( 'Redemption', 'wb-gamification' ),
		);
		if ( isset( $built_in[ $action_id ] ) ) {
			return $built_in[ $action_id ];
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $action_id ) );
	}

	/**
	 * Resolve the currency slug an action should award.
	 *
	 * Single source of truth used by both the award path
	 * (Registry::register_action() closure) and the rate-limit path
	 * (PointsEngine::passes_rate_limits()) so cap counts and ledger writes
	 * always agree on which currency the action belongs to.
	 *
	 * Resolution order:
	 *   1. Admin override — `wb_gam_point_type_<action_id>` option
	 *   2. Manifest declaration — `$action['point_type']`
	 *   3. Empty string — caller passes through `PointTypeService::resolve()`
	 *      which falls back to the primary slug.
	 *
	 * @param array $action Registered action config.
	 * @return string Resolved point-type slug, or empty for "use primary".
	 */
	public static function resolve_action_point_type( array $action ): string {
		$action_id = (string) ( $action['id'] ?? '' );
		if ( '' !== $action_id ) {
			$override = (string) get_option( 'wb_gam_point_type_' . $action_id, '' );
			if ( '' !== $override ) {
				return $override;
			}
		}
		return (string) ( $action['point_type'] ?? '' );
	}
}
