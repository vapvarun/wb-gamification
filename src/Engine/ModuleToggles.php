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
	 * Toggleable modules and where each one surfaces.
	 *
	 * @return array<string,array{label:string,blocks:string[],shortcodes:string[],admin_slugs:string[]}>
	 */
	public static function modules(): array {
		return array(
			'kudos'                => array(
				'label'       => __( 'Kudos', 'wb-gamification' ),
				'blocks'      => array( 'kudos-feed', 'give-kudos' ),
				'shortcodes'  => array( 'wb_gam_kudos_feed', 'wb_gam_give_kudos' ),
				'admin_slugs' => array(),
			),
			'streaks'              => array(
				'label'       => __( 'Streaks', 'wb-gamification' ),
				'blocks'      => array( 'streak' ),
				'shortcodes'  => array( 'wb_gam_streak' ),
				'admin_slugs' => array(),
			),
			'challenges'           => array(
				'label'       => __( 'Challenges', 'wb-gamification' ),
				'blocks'      => array( 'challenges' ),
				'shortcodes'  => array( 'wb_gam_challenges' ),
				'admin_slugs' => array( 'wb-gam-challenges' ),
			),
			'community_challenges' => array(
				'label'       => __( 'Community challenges', 'wb-gamification' ),
				'blocks'      => array( 'community-challenges' ),
				'shortcodes'  => array( 'wb_gam_community_challenges' ),
				'admin_slugs' => array( 'wb-gam-community-challenges' ),
			),
			'cohort_leagues'       => array(
				'label'       => __( 'Cohort leagues', 'wb-gamification' ),
				'blocks'      => array( 'cohort-rank' ),
				'shortcodes'  => array( 'wb_gam_cohort_rank' ),
				'admin_slugs' => array(),
			),
			'redemption'           => array(
				'label'       => __( 'Redemption store', 'wb-gamification' ),
				'blocks'      => array( 'redemption-store' ),
				'shortcodes'  => array( 'wb_gam_redemption_store', 'wb_gam_my_rewards' ),
				'admin_slugs' => array( 'wb-gam-redemption' ),
			),
		);
	}

	/**
	 * Whether a module is enabled. Default ON; only an explicit '0' in the
	 * wb_gam_modules option disables it. Filterable per module.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function enabled( string $slug ): bool {
		$map     = (array) get_option( self::OPTION, array() );
		$enabled = ! array_key_exists( $slug, $map ) || '0' !== (string) $map[ $slug ];

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
		foreach ( self::modules() as $slug => $module ) {
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
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'maybe_suppress_shortcode' ), 10, 2 );
		// Remove disabled modules' admin pages after every page is registered.
		add_action( 'admin_menu', array( __CLASS__, 'remove_admin_pages' ), 999 );
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
