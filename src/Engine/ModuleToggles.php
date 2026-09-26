<?php
/**
 * WB Gamification - optional module on/off toggles.
 *
 * Lets a site owner turn off engagement modules they don't use (kudos,
 * streaks, challenges, community challenges, cohort leagues, redemption store)
 * so members and admins aren't shown features the community doesn't run. A
 * disabled module is hidden everywhere it surfaces:
 *   - its blocks + shortcodes render nothing (render_block + do_shortcode_tag),
 *   - its admin submenu page is removed.
 *
 * The underlying engine may keep computing (harmless); this is a presentation
 * declutter, not a data switch, so re-enabling a module restores it intact.
 *
 * NOTE: distinct from WBGam\Engine\FeatureFlags, which are DB-schema version
 * gates - this is the user-facing module visibility option (wb_gam_modules).
 *
 * @package WB_Gamification
 * @since   1.5.3
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * User-facing optional-module visibility toggles.
 *
 * @package WB_Gamification
 */
final class ModuleToggles {

	private const OPTION = 'wb_gam_modules';

	/**
	 * Modules that are also background engines, mapped to their FeatureFlags key. These have one
	 * switch (Settings > Modules) that controls both the visible module and its engine.
	 */
	public const ENGINE_FLAGS = array(
		'cohort_leagues'       => 'cohort_leagues',
		'community_challenges' => 'community_challenges',
	);

	/**
	 * Label-free module map: where each toggleable module surfaces. Contains NO
	 * __() so it is safe to call early (init() runs on plugins_loaded, before
	 * the init hook - translating here triggers _load_textdomain_just_in_time
	 * on WP 6.7+). Labels are added by modules(), which only runs at admin
	 * render time (after init).
	 *
	 * @return array<string,array{blocks:string[],shortcodes:string[],admin_slugs:string[]}>
	 */
	public static function map(): array {
		return array(
			'kudos'                => array(
				'blocks'      => array( 'kudos-feed', 'give-kudos' ),
				'shortcodes'  => array( 'wb_gam_kudos_feed', 'wb_gam_give_kudos' ),
				'admin_slugs' => array(),
			),
			'streaks'              => array(
				'blocks'      => array( 'streak' ),
				'shortcodes'  => array( 'wb_gam_streak' ),
				'admin_slugs' => array(),
			),
			'challenges'           => array(
				'blocks'      => array( 'challenges' ),
				'shortcodes'  => array( 'wb_gam_challenges' ),
				'admin_slugs' => array( 'wb-gam-challenges' ),
			),
			'community_challenges' => array(
				'blocks'      => array( 'community-challenges' ),
				'shortcodes'  => array( 'wb_gam_community_challenges' ),
				'admin_slugs' => array( 'wb-gam-community-challenges' ),
			),
			'cohort_leagues'       => array(
				'blocks'      => array( 'cohort-rank' ),
				'shortcodes'  => array( 'wb_gam_cohort_rank' ),
				'admin_slugs' => array(),
			),
			'redemption'           => array(
				'blocks'      => array( 'redemption-store' ),
				'shortcodes'  => array( 'wb_gam_redemption_store', 'wb_gam_my_rewards' ),
				'admin_slugs' => array( 'wb-gam-redemption' ),
			),
		);
	}

	/**
	 * Translated module labels, keyed by slug. Call only at render time (admin,
	 * after init) - it runs __().
	 *
	 * @return array<string,string>
	 */
	private static function labels(): array {
		return array(
			'kudos'                => __( 'Kudos', 'wb-gamification' ),
			'streaks'              => __( 'Streaks', 'wb-gamification' ),
			'challenges'           => __( 'Challenges', 'wb-gamification' ),
			'community_challenges' => __( 'Community challenges', 'wb-gamification' ),
			'cohort_leagues'       => __( 'Cohort leagues', 'wb-gamification' ),
			'redemption'           => __( 'Redemption store', 'wb-gamification' ),
		);
	}

	/**
	 * Toggleable modules with translated labels, for the admin UI. Composes
	 * map() + labels(). Do NOT call before the init hook (it translates).
	 *
	 * @return array<string,array{label:string,blocks:string[],shortcodes:string[],admin_slugs:string[]}>
	 */
	public static function modules(): array {
		$labels = self::labels();
		$out    = array();
		foreach ( self::map() as $slug => $module ) {
			$out[ $slug ] = array( 'label' => $labels[ $slug ] ?? $slug ) + $module;
		}
		return $out;
	}

	/**
	 * Whether a module is enabled. Default ON; only an explicit '0' in the
	 * wb_gam_modules option disables it. Filterable per module.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function enabled( string $slug ): bool {
		$enabled = self::option_enabled( $slug );

		// A module that is also a background engine is on only when its engine flag is on too:
		// one switch in Settings > Modules writes both (see set()), and a site that switched the
		// engine off under the old second switch still reads 'off' here.
		if ( $enabled && isset( self::ENGINE_FLAGS[ $slug ] ) ) {
			$flags   = FeatureFlags::get_all();
			$enabled = ! empty( $flags[ self::ENGINE_FLAGS[ $slug ] ] );
		}

		/**
		 * Filter whether an optional module is enabled.
		 *
		 * @since 1.5.3
		 *
		 * @param bool   $enabled Whether the module is on.
		 * @param string $slug    Module slug.
		 */
		return (bool) apply_filters( 'wb_gam_module_enabled', $enabled, $slug );
	}

	/**
	 * The module's own stored switch, without the engine flag or the filter.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function option_enabled( string $slug ): bool {
		$map = (array) get_option( self::OPTION, array() );
		return ! array_key_exists( $slug, $map ) || '0' !== (string) $map[ $slug ];
	}

	/**
	 * Turn a module on or off - the one switch for it. For a module that is also a background
	 * engine this writes the engine flag too, so no second switch can disagree.
	 *
	 * @since 1.6.5
	 *
	 * @param string $slug Module slug.
	 * @param bool   $on   On or off.
	 * @return void
	 */
	public static function set( string $slug, bool $on ): void {
		$map          = (array) get_option( self::OPTION, array() );
		$map[ $slug ] = $on ? '1' : '0';
		update_option( self::OPTION, $map );

		if ( isset( self::ENGINE_FLAGS[ $slug ] ) ) {
			$flags                                = FeatureFlags::get_all();
			$flags[ self::ENGINE_FLAGS[ $slug ] ] = $on;
			FeatureFlags::update( $flags );
		}
	}

	/**
	 * Block names suppressed this request (disabled modules' blocks).
	 *
	 * @var string[]
	 */
	private static array $blocks = array();

	/**
	 * Shortcode tags suppressed this request.
	 *
	 * @var string[]
	 */
	private static array $shortcodes = array();

	/**
	 * Admin submenu slugs to remove this request.
	 *
	 * @var string[]
	 */
	private static array $admin_slugs = array();

	/**
	 * Wire suppression for any disabled module.
	 */
	public static function init(): void {
		// Use the label-free map() here - init() runs on plugins_loaded, before
		// the init hook, so it must not translate (would trigger
		// _load_textdomain_just_in_time on WP 6.7+).
		foreach ( self::map() as $slug => $module ) {
			if ( self::enabled( $slug ) ) {
				continue;
			}
			self::$blocks      = array_merge( self::$blocks, $module['blocks'] );
			self::$shortcodes  = array_merge( self::$shortcodes, $module['shortcodes'] );
			self::$admin_slugs = array_merge( self::$admin_slugs, $module['admin_slugs'] );
		}

		if ( empty( self::$blocks ) && empty( self::$admin_slugs ) ) {
			return;
		}

		// Prefix block names with the plugin namespace once.
		self::$blocks = array_map(
			static fn( $b ) => 'wb-gamification/' . $b,
			self::$blocks
		);

		add_filter( 'render_block', array( __CLASS__, 'maybe_suppress_block' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_refuse_rest' ), 10, 3 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'maybe_suppress_shortcode' ), 10, 2 );
		// Remove disabled modules' admin pages after every page is registered.
		add_action( 'admin_menu', array( __CLASS__, 'remove_admin_pages' ), 999 );
	}

	/**
	 * REST route families each module owns (under wb-gamification/v1). A module that is off
	 * answers them with 404 wb_gam_module_disabled, so apps get a clear 'turned off' rather than
	 * a working endpoint for a hidden feature. Admin settings routes (cohort-settings) stay open.
	 */
	private const REST_ROUTES = array(
		'kudos'                => 'kudos',
		'challenges'           => 'challenges',
		'community_challenges' => 'community-challenges',
		'redemption'           => 'redemptions',
	);

	/**
	 * Refuse REST requests to a switched-off module's routes.
	 *
	 * @since 1.6.5
	 *
	 * @param mixed            $result  Response to short-circuit with, or null.
	 * @param \WP_REST_Server  $server  Server (unused).
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function maybe_refuse_rest( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- filter signature.
		if ( null !== $result ) {
			return $result;
		}
		$route = (string) $request->get_route();
		foreach ( self::REST_ROUTES as $slug => $base ) {
			if ( preg_match( '#^/wb-gamification/v1/' . preg_quote( $base, '#' ) . '(/|$)#', $route ) && ! self::enabled( $slug ) ) {
				return self::disabled_error();
			}
		}
		return $result;
	}

	/**
	 * The one refusal for a switched-off module, shared by the REST gate and any engine a
	 * partner plugin calls directly (a 404 over REST: the feature does not exist here).
	 *
	 * @return \WP_Error
	 */
	public static function disabled_error(): \WP_Error {
		return new \WP_Error( 'wb_gam_module_disabled', __( 'This feature is turned off on this site.', 'wb-gamification' ), array( 'status' => 404 ) );
	}

	/**
	 * Blank a disabled module's block.
	 *
	 * @param string $content Rendered block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function maybe_suppress_block( $content, $block ) {
		if ( isset( $block['blockName'] ) && in_array( $block['blockName'], self::$blocks, true ) ) {
			return '';
		}
		return $content;
	}

	/**
	 * Blank a disabled module's shortcode.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	public static function maybe_suppress_shortcode( $output, $tag ) {
		if ( in_array( $tag, self::$shortcodes, true ) ) {
			return '';
		}
		return $output;
	}

	/**
	 * Remove disabled modules' admin submenu pages.
	 */
	public static function remove_admin_pages(): void {
		foreach ( self::$admin_slugs as $slug ) {
			remove_submenu_page( 'wb-gamification', $slug );
		}
	}
}
