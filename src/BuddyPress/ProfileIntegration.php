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

defined( 'ABSPATH' ) || exit;

/**
 * Injects gamification rank and progress bar into the BuddyPress profile header.
 *
 * @package WB_Gamification
 */
final class ProfileIntegration {

	/**
	 * Register hooks when BuddyPress is active.
	 */
	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}
		add_action( 'bp_before_member_header_meta', array( __CLASS__, 'render_rank' ) );
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

		$level_name_raw = get_user_meta( $user_id, 'wb_gam_level_name', true );
		$level_name     = $level_name_raw ? $level_name_raw : __( 'Newcomer', 'wb-gamification' );
		$points         = PointsEngine::get_total( $user_id );

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
				/* translators: %s = formatted number of points */
				printf( esc_html__( '%s pts', 'wb-gamification' ), esc_html( number_format_i18n( $points ) ) );
				?>
			</span>
			<?php if ( $next_level_points > 0 ) : ?>
			<div class="wb-gam-progress-bar" title="<?php echo esc_attr( $progress_pct . '%' ); ?>">
				<div class="wb-gam-progress-fill" style="width:<?php echo esc_attr( $progress_pct ); ?>%"></div>
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
