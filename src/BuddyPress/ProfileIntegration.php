<?php
/**
 * WB Gamification — BuddyPress Profile Integration
 *
 * Injects rank/level display into the BP profile header automatically.
 * No shortcode required.
 *
 * @package WB_Gamification
 */

namespace WBGam\BuddyPress;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\MemberSurface;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Injects gamification rank and progress bar into the BuddyPress profile header.
 *
 * @package WB_Gamification
 */
final class ProfileIntegration {

	/**
	 * Register hooks when BuddyPress is active.
	 */
	/**
	 * Slug for the "Achievements" profile tab.
	 */
	private const NAV_SLUG = 'achievements';

	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'render_rank' ) );
		add_action( 'bp_setup_nav', array( __CLASS__, 'setup_nav' ), 100 );
	}

	/**
	 * Register the "Achievements" profile tab and its sub-tabs.
	 *
	 * Each sub-tab renders existing gamification blocks (via their
	 * shortcodes, scoped to the displayed member with `user_id`) so the
	 * profile reuses the single source of block markup — no duplicated
	 * profile-only templates to keep in sync.
	 */
	public static function setup_nav(): void {
		$base       = function_exists( 'bp_displayed_user_url' )
			? bp_displayed_user_url()
			: bp_displayed_user_domain();
		$parent_url = trailingslashit( $base ) . self::NAV_SLUG . '/';

		bp_core_new_nav_item(
			array(
				'name'                => __( 'Achievements', 'wb-gamification' ),
				'slug'                => self::NAV_SLUG,
				'screen_function'     => array( __CLASS__, 'screen' ),
				'position'            => 35,
				'default_subnav_slug' => 'overview',
				'item_css_id'         => 'wb-gam-achievements',
			)
		);

		$subtabs  = array(
			'overview' => __( 'Overview', 'wb-gamification' ),
			'badges'   => __( 'Badges', 'wb-gamification' ),
			'points'   => __( 'Points', 'wb-gamification' ),
			'streak'   => __( 'Streak', 'wb-gamification' ),
		);
		$position = 10;
		foreach ( $subtabs as $slug => $name ) {
			bp_core_new_subnav_item(
				array(
					'name'            => $name,
					'slug'            => $slug,
					'parent_url'      => $parent_url,
					'parent_slug'     => self::NAV_SLUG,
					'screen_function' => array( __CLASS__, 'screen' ),
					'position'        => $position,
				)
			);
			$position += 10;
		}
	}

	/**
	 * Screen handler for the Achievements tab + sub-tabs. Hooks the content
	 * renderer and loads BuddyPress's plugin template.
	 */
	public static function screen(): void {
		add_action( 'bp_template_content', array( __CLASS__, 'screen_content' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * Render the current sub-tab's content from existing blocks, scoped to
	 * the displayed member. bp_current_action() resolves to the sub-nav slug
	 * (overview / badges / points / streak).
	 */
	public static function screen_content(): void {
		$user_id = (int) bp_displayed_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Pick the blocks for the active sub-tab; MemberSurface owns the
		// shared plumbing (asset enqueue, mapped hub link, wrapper). Overview
		// stays a concise PERSONAL summary — member-points already shows the
		// next level and streak shows the next milestone, so "what's next" is
		// covered without the site-wide earning guide (that stays on the Hub).
		// Locked badges ("what to earn next") live in the Badges sub-tab.
		switch ( bp_current_action() ) {
			case 'badges':
				$tags = array( 'wb_gam_badge_showcase' );
				break;
			case 'points':
				$tags = array( 'wb_gam_points_history' );
				break;
			case 'streak':
				$tags = array( 'wb_gam_streak' );
				break;
			case 'overview':
			default:
				$tags = array( 'wb_gam_member_points', 'wb_gam_streak' );
				break;
		}

		// Surface markup is block SSR + escaped link; re-escaping would corrupt it.
		echo MemberSurface::render( MemberSurface::blocks( $tags, $user_id ), $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Output rank badge in the profile header.
	 * Respects the member's show_rank preference.
	 */
	public static function render_rank(): void {
		$user_id = bp_displayed_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Respect opt-out preference.
		global $wpdb;
		$show_rank = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT show_rank FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
				$user_id
			)
		);
		// Default to showing (NULL means no row = default on).
		if ( '0' === $show_rank ) {
			return;
		}

		// Route through LevelEngine so the cached user_meta is self-healed
		// when the points ledger has crossed a level threshold but the
		// engine pipeline never ran for this user (manual SQL seed, sister
		// product import, pre-1.4.0 stale cache). Falling back to the
		// user_meta value verbatim leaves the rank stuck on whatever level
		// was last persisted, which is what Simran observed in QA.
		$level      = \WBGam\Engine\LevelEngine::get_level_for_user( (int) $user_id );
		$level_name = $level ? (string) $level['name'] : __( 'Newcomer', 'wb-gamification' );
		$points     = PointsEngine::get_total( $user_id );

		// Resolve the primary currency label so the BP profile shows
		// "1,200 Coins" on a coins-default site instead of "1,200 pts".
		$pt_service   = new \WBGam\Services\PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'pts', 'wb-gamification' ) );

		// Get next level threshold for progress bar.
		$next_level_points = self::get_next_level_points( $user_id );
		$current_level_min = self::get_current_level_min( $user_id );
		$progress_pct      = 0;
		if ( $next_level_points > $current_level_min ) {
			$progress_pct = min(
				100,
				(int) round(
					( ( $points - $current_level_min ) / ( $next_level_points - $current_level_min ) ) * 100
				)
			);
		}

		?>
		<div class="wb-gam-profile-rank">
			<span class="wb-gam-rank-badge"><?php echo esc_html( $level_name ); ?></span>
			<span class="wb-gam-points-count">
				<?php
				printf(
					/* translators: 1: amount, 2: currency label. */
					esc_html__( '%1$s %2$s', 'wb-gamification' ),
					esc_html( number_format_i18n( $points ) ),
					esc_html( $points_label )
				);
				?>
			</span>
			<?php if ( $next_level_points > 0 ) : ?>
			<div class="wb-gam-progress-bar" title="<?php echo esc_attr( $progress_pct . '%' ); ?>">
				<div class="wb-gam-progress-fill" style="--wb-gam-fill:<?php echo esc_attr( (string) $progress_pct ); ?>%"></div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the minimum points required for the next level above the user's current total.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Minimum points for the next level, or 0 if none exists.
	 */
	private static function get_next_level_points( int $user_id ): int {
		global $wpdb;
		$current_points = PointsEngine::get_total( $user_id );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT min_points FROM {$wpdb->prefix}wb_gam_levels
				WHERE min_points > %d ORDER BY min_points ASC LIMIT 1",
				$current_points
			)
		);
	}

	/**
	 * Get the minimum points threshold of the user's current level.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Minimum points for the current level, or 0 if no level matched.
	 */
	private static function get_current_level_min( int $user_id ): int {
		global $wpdb;
		$current_points = PointsEngine::get_total( $user_id );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT min_points FROM {$wpdb->prefix}wb_gam_levels
				WHERE min_points <= %d ORDER BY min_points DESC LIMIT 1",
				$current_points
			)
		);
	}
}
