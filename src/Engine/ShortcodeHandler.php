<?php
/**
 * WB Gamification Shortcode Handler
 *
 * Registers [wb_gam_*] shortcodes for all blocks, delegating rendering
 * to render_block() so block logic is not duplicated.
 *
 * Usage examples:
 *   [wb_gam_leaderboard period="week" limit="5"]
 *   [wb_gam_member_points user_id="42"]
 *   [wb_gam_badge_showcase show_locked="1"]
 *   [wb_gam_level_progress]
 *   [wb_gam_challenges limit="3"]
 *   [wb_gam_streak show_longest="1"]
 *   [wb_gam_top_members limit="5" layout="list"]
 *   [wb_gam_kudos_feed limit="5"]
 *   [wb_gam_year_recap]
 *   [wb_gam_points_history limit="20"]
 *
 * @package WB_Gamification
 * @since   0.5.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress shortcodes that delegate rendering to the block render layer.
 *
 * @package WB_Gamification
 */
final class ShortcodeHandler {

	/**
	 * Register all [wb_gam_*] shortcodes.
	 *
	 * Called on the `init` action.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'wb_gam_leaderboard', array( __CLASS__, 'render_leaderboard' ) );
		add_shortcode( 'wb_gam_member_points', array( __CLASS__, 'render_member_points' ) );
		add_shortcode( 'wb_gam_badge_showcase', array( __CLASS__, 'render_badge_showcase' ) );
		add_shortcode( 'wb_gam_level_progress', array( __CLASS__, 'render_level_progress' ) );
		add_shortcode( 'wb_gam_challenges', array( __CLASS__, 'render_challenges' ) );
		add_shortcode( 'wb_gam_streak', array( __CLASS__, 'render_streak' ) );
		add_shortcode( 'wb_gam_top_members', array( __CLASS__, 'render_top_members' ) );
		add_shortcode( 'wb_gam_kudos_feed', array( __CLASS__, 'render_kudos_feed' ) );
		add_shortcode( 'wb_gam_year_recap', array( __CLASS__, 'render_year_recap' ) );
		add_shortcode( 'wb_gam_points_history', array( __CLASS__, 'render_points_history' ) );
		add_shortcode( 'wb_gam_earning_guide', array( __CLASS__, 'render_earning_guide' ) );
		add_shortcode( 'wb_gam_hub', array( __CLASS__, 'render_hub' ) );
		add_shortcode( 'wb_gam_community_challenges', array( __CLASS__, 'render_community_challenges' ) );
		add_shortcode( 'wb_gam_cohort_rank', array( __CLASS__, 'render_cohort_rank' ) );
		add_shortcode( 'wb_gam_redemption_store', array( __CLASS__, 'render_redemption_store' ) );
	}

	// ── Shortcode renderers ───────────────────────────────────────────────────

	/**
	 * Render [wb_gam_leaderboard].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_leaderboard( $atts ): string {
		return self::block( 'leaderboard', self::normalize_leaderboard_atts( (array) $atts ) );
	}

	/**
	 * Render [wb_gam_member_points].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_member_points( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'show_level'        => true,
				'show_progress_bar' => true,
			),
			(array) $atts,
			'wb_gam_member_points'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['show_level']        = filter_var( $atts['show_level'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_progress_bar'] = filter_var( $atts['show_progress_bar'], FILTER_VALIDATE_BOOLEAN );

		return self::block( 'member-points', $atts );
	}

	/**
	 * Render [wb_gam_badge_showcase].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_badge_showcase( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'     => 0,
				'show_locked' => false,
				'category'    => '',
				'limit'       => 0,
			),
			(array) $atts,
			'wb_gam_badge_showcase'
		);

		$atts['user_id']     = (int) $atts['user_id'];
		$atts['show_locked'] = filter_var( $atts['show_locked'], FILTER_VALIDATE_BOOLEAN );
		$atts['limit']       = max( 0, (int) $atts['limit'] );
		$atts['category']    = sanitize_key( $atts['category'] );

		return self::block( 'badge-showcase', $atts );
	}

	/**
	 * Render [wb_gam_level_progress].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_level_progress( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'show_progress_bar' => true,
				'show_next_level'   => true,
				'show_icon'         => true,
			),
			(array) $atts,
			'wb_gam_level_progress'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['show_progress_bar'] = filter_var( $atts['show_progress_bar'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_next_level']   = filter_var( $atts['show_next_level'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_icon']         = filter_var( $atts['show_icon'], FILTER_VALIDATE_BOOLEAN );

		return self::block( 'level-progress', $atts );
	}

	/**
	 * Render [wb_gam_challenges].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_challenges( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'show_completed'    => true,
				'show_progress_bar' => true,
				'limit'             => 0,
			),
			(array) $atts,
			'wb_gam_challenges'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['show_completed']    = filter_var( $atts['show_completed'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_progress_bar'] = filter_var( $atts['show_progress_bar'], FILTER_VALIDATE_BOOLEAN );
		$atts['limit']             = max( 0, (int) $atts['limit'] );

		return self::block( 'challenges', $atts );
	}

	/**
	 * Render [wb_gam_streak].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_streak( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'      => 0,
				'show_longest' => false,
				'show_heatmap' => false,
				'heatmap_days' => 90,
			),
			(array) $atts,
			'wb_gam_streak'
		);

		$atts['user_id']      = (int) $atts['user_id'];
		$atts['show_longest'] = filter_var( $atts['show_longest'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_heatmap'] = filter_var( $atts['show_heatmap'], FILTER_VALIDATE_BOOLEAN );
		$atts['heatmap_days'] = max( 1, min( 365, (int) $atts['heatmap_days'] ) );

		return self::block( 'streak', $atts );
	}

	/**
	 * Render [wb_gam_top_members].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_top_members( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'       => 3,
				'period'      => 'all_time',
				'show_badges' => false,
				'show_level'  => false,
				'layout'      => 'podium',
			),
			(array) $atts,
			'wb_gam_top_members'
		);

		$atts['limit']       = max( 1, min( 20, (int) $atts['limit'] ) );
		$atts['show_badges'] = filter_var( $atts['show_badges'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_level']  = filter_var( $atts['show_level'], FILTER_VALIDATE_BOOLEAN );
		$atts['layout']      = in_array( $atts['layout'], array( 'podium', 'list' ), true )
			? $atts['layout'] : 'podium';

		return self::block( 'top-members', $atts );
	}

	/**
	 * Render [wb_gam_kudos_feed].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_kudos_feed( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'         => 10,
				'show_messages' => true,
			),
			(array) $atts,
			'wb_gam_kudos_feed'
		);

		$atts['limit']         = max( 1, min( 50, (int) $atts['limit'] ) );
		$atts['show_messages'] = filter_var( $atts['show_messages'], FILTER_VALIDATE_BOOLEAN );

		return self::block( 'kudos-feed', $atts );
	}

	/**
	 * Render [wb_gam_year_recap].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_year_recap( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'      => 0,
				'year'         => 0,
				'show_share'   => true,
				'show_badges'  => true,
				'show_kudos'   => true,
				'accent_color' => '',
			),
			(array) $atts,
			'wb_gam_year_recap'
		);

		$atts['user_id']      = (int) $atts['user_id'];
		$atts['year']         = (int) $atts['year'];
		$atts['show_share']   = filter_var( $atts['show_share'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_badges']  = filter_var( $atts['show_badges'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_kudos']   = filter_var( $atts['show_kudos'], FILTER_VALIDATE_BOOLEAN );
		$atts['accent_color'] = sanitize_hex_color( $atts['accent_color'] );

		// Map 'show_share' to the block attribute name 'show_share_button'.
		$block_atts                      = $atts;
		$block_atts['show_share_button'] = $block_atts['show_share'];
		unset( $block_atts['show_share'] );

		return self::block( 'year-recap', $block_atts );
	}

	/**
	 * Render [wb_gam_points_history].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_points_history( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'           => 0,
				'limit'             => 20,
				'show_action_label' => true,
			),
			(array) $atts,
			'wb_gam_points_history'
		);

		$atts['user_id']           = (int) $atts['user_id'];
		$atts['limit']             = max( 1, min( 100, (int) $atts['limit'] ) );
		$atts['show_action_label'] = filter_var( $atts['show_action_label'], FILTER_VALIDATE_BOOLEAN );

		return self::block( 'points-history', $atts );
	}

	/**
	 * Render [wb_gam_hub].
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $atts Shortcode attributes (none used).
	 * @return string HTML output.
	 */
	public static function render_hub( $atts = array() ): string {
		return self::block( 'hub', array() );
	}

	/**
	 * Render the community challenges shortcode.
	 *
	 * @param array|string $atts [limit, show_progress_bar].
	 * @return string Rendered HTML.
	 */
	public static function render_community_challenges( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'             => 0,
				'show_progress_bar' => 'true',
			),
			(array) $atts,
			'wb_gam_community_challenges'
		);
		return self::block( 'community-challenges', array(
			'limit'             => (int) $atts['limit'],
			'show_progress_bar' => 'false' !== $atts['show_progress_bar'],
		) );
	}

	/**
	 * Render the cohort rank shortcode.
	 *
	 * @param array|string $atts [user_id, limit].
	 * @return string Rendered HTML.
	 */
	public static function render_cohort_rank( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'user_id' => 0,
				'limit'   => 5,
			),
			(array) $atts,
			'wb_gam_cohort_rank'
		);
		return self::block( 'cohort-rank', array(
			'user_id' => (int) $atts['user_id'],
			'limit'   => (int) $atts['limit'],
		) );
	}

	/**
	 * Render the redemption store shortcode.
	 *
	 * @param array|string $atts [limit, columns, show_balance].
	 * @return string Rendered HTML.
	 */
	public static function render_redemption_store( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'limit'        => 0,
				'columns'      => 3,
				'show_balance' => 'true',
			),
			(array) $atts,
			'wb_gam_redemption_store'
		);
		return self::block( 'redemption-store', array(
			'limit'        => (int) $atts['limit'],
			'columns'      => max( 1, min( 4, (int) $atts['columns'] ) ),
			'show_balance' => 'false' !== $atts['show_balance'],
		) );
	}

	// ── Public attribute normalizers (used by tests) ──────────────────────────

	/**
	 * Normalize leaderboard shortcode attributes.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array Normalized and sanitized attributes.
	 */
	public static function normalize_leaderboard_atts( array $atts ): array {
		$atts = shortcode_atts(
			array(
				'period'       => 'all',
				'limit'        => 10,
				'scope_type'   => '',
				'scope_id'     => 0,
				'show_avatars' => true,
			),
			$atts,
			'wb_gam_leaderboard'
		);

		$atts['limit']        = max( 1, min( 100, (int) $atts['limit'] ) );
		$atts['scope_id']     = (int) $atts['scope_id'];
		$atts['show_avatars'] = filter_var( $atts['show_avatars'], FILTER_VALIDATE_BOOLEAN );

		return $atts;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Call render_block() for a registered wb-gamification block.
	 *
	 * Blocks are registered on `init` (via register_block_type), which fires
	 * before shortcodes are ever processed (shortcodes run on `the_content`).
	 * This call is therefore always safe.
	 *
	 * @param string $block_slug Slug matching a directory in /blocks/, e.g. 'leaderboard'.
	 * @param array  $attrs     Block attributes array.
	 * @return string HTML output.
	 */
	private static function block( string $block_slug, array $attrs ): string {
		wp_enqueue_style( 'wb-gamification' );

		return render_block(
			array(
				'blockName'    => "wb-gamification/{$block_slug}",
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Render [wb_gam_earning_guide].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_earning_guide( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'columns'               => 3,
				'show_category_headers' => 'true',
			),
			$atts,
			'wb_gam_earning_guide'
		);

		$attrs = array(
			'columns'               => max( 1, min( 4, (int) $atts['columns'] ) ),
			'show_category_headers' => 'true' === $atts['show_category_headers'],
		);

		return self::block( 'earning-guide', $attrs );
	}
}
