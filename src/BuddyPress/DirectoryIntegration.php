<?php
/**
 * WB Gamification — BuddyPress Member Directory Integration
 *
 * Shows rank title next to member name in the member directory.
 *
 * @package WB_Gamification
 */

namespace WBGam\BuddyPress;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Renders member rank in the BuddyPress member directory listing.
 *
 * @package WB_Gamification
 */
final class DirectoryIntegration {

	/**
	 * Register hooks when BuddyPress is active.
	 */
	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}
		add_action( 'bp_directory_members_item', array( __CLASS__, 'render_rank_in_directory' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_directory_styles' ) );
	}

	/**
	 * Load the shared frontend stylesheet on the BP members directory so the
	 * rank line below each card picks up the `.wb-gam-directory-*` rules
	 * appended in `assets/css/frontend.css`. Without this, the inline-SVG
	 * icons render unstyled (no size, no muted colour) and the rank meta
	 * collapses onto one un-spaced line.
	 */
	public static function enqueue_directory_styles(): void {
		if ( function_exists( 'bp_is_members_directory' ) && bp_is_members_directory() ) {
			wp_enqueue_style( 'wb-gamification' );
		}
	}

	/**
	 * Output rank badge in the member directory listing.
	 */
	public static function render_rank_in_directory(): void {
		$user_id = bp_get_member_user_id();
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
		if ( '0' === $show_rank ) {
			return;
		}

		// Route through LevelEngine so the cached user_meta is self-healed
		// when the ledger has crossed a threshold without the engine running
		// (admin-imported users, manual SQL seed, sister-plugin migrations).
		$level        = \WBGam\Engine\LevelEngine::get_level_for_user( (int) $user_id );
		$level_name   = $level ? (string) $level['name'] : '';
		$badge_count  = \WBGam\Engine\BadgeEngine::count_user_badges( (int) $user_id );
		$points_total = (int) \WBGam\Engine\PointsEngine::get_total( (int) $user_id );

		if ( '' === $level_name && 0 === $badge_count && 0 === $points_total ) {
			return; // Don't show anything for members with nothing earned yet.
		}

		$parts = array();
		if ( '' !== $level_name ) {
			$parts[] = '<span class="wb-gam-directory-level">' . esc_html( $level_name ) . '</span>';
		}
		if ( $points_total > 0 ) {
			$parts[] = sprintf(
				'<span class="wb-gam-directory-points">%1$s %2$s</span>',
				\WBGam\Admin\Icon::svg(
					'sparkles',
					array(
						'size'  => 14,
						'class' => 'wb-gam-directory-icon',
					)
				),
				esc_html( number_format_i18n( $points_total ) )
			);
		}
		if ( $badge_count > 0 ) {
			$badge_text = sprintf(
				/* translators: %d: number of badges earned. */
				_n( '%d badge', '%d badges', $badge_count, 'wb-gamification' ),
				$badge_count
			);
			$parts[] = sprintf(
				'<span class="wb-gam-directory-badges">%1$s %2$s</span>',
				\WBGam\Admin\Icon::svg(
					'medal',
					array(
						'size'  => 14,
						'class' => 'wb-gam-directory-icon',
					)
				),
				esc_html( $badge_text )
			);
		}

		echo '<span class="wb-gam-directory-rank">' . implode( ' &middot; ', $parts ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is individually escaped above.
	}
}
