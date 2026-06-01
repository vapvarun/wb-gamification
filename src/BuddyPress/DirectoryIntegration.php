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
	 * Per-request map of user_id => show_rank (string|null), primed once for
	 * the whole directory page by prime_directory(). Null until primed.
	 *
	 * @var array<int, string|null>|null
	 */
	private static ?array $prefs = null;

	/**
	 * Register hooks when BuddyPress is active.
	 */
	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}
		// Prime ALL per-member rank data once, before the loop renders, so
		// each row does zero queries. Without this the directory is a 3-4x
		// query N+1 that grows with community size.
		add_action( 'bp_before_directory_members_list', array( __CLASS__, 'prime_directory' ) );
		add_action( 'bp_directory_members_item', array( __CLASS__, 'render_rank_in_directory' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_directory_styles' ) );
	}

	/**
	 * Batch-warm every per-member rank read for the current directory page.
	 *
	 * Fires on bp_before_directory_members_list (after the member loop is
	 * queried, before the list HTML). Collects the page's member IDs and
	 * warms points totals, earned-badge counts, level user_meta, and the
	 * show_rank preference in a fixed handful of queries — turning
	 * render_rank_in_directory()'s former O(rows) queries into O(1).
	 *
	 * @return void
	 */
	public static function prime_directory(): void {
		$members = isset( $GLOBALS['members_template']->members ) ? $GLOBALS['members_template']->members : array();
		if ( empty( $members ) || ! is_array( $members ) ) {
			return;
		}

		$ids = array();
		foreach ( $members as $member ) {
			$uid = (int) ( $member->ID ?? 0 );
			if ( $uid > 0 ) {
				$ids[] = $uid;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return;
		}

		// Engine cache primers — each subsequent per-row read becomes a hit.
		\WBGam\Engine\PointsEngine::prime_totals( $ids );
		\WBGam\Engine\BadgeEngine::prime_earned_badges( $ids );
		// LevelEngine::get_level_for_user reads wb_gam_level_id / _name meta.
		update_meta_cache( 'user', $ids );

		// Batch the show_rank preference into the per-request map.
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is built from an int count; all values pass through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, show_rank FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id IN ( $placeholders )",
				$ids
			),
			OBJECT_K
		);

		self::$prefs = array();
		foreach ( $ids as $uid ) {
			self::$prefs[ $uid ] = isset( $rows[ $uid ] ) ? (string) $rows[ $uid ]->show_rank : null;
		}
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

		// Respect opt-out preference. Use the per-page primed map (set by
		// prime_directory on bp_before_directory_members_list); fall back to
		// a single lookup only if this rendered outside the normal loop.
		if ( is_array( self::$prefs ) && array_key_exists( (int) $user_id, self::$prefs ) ) {
			$show_rank = self::$prefs[ (int) $user_id ];
		} else {
			global $wpdb;
			$show_rank = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT show_rank FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
					$user_id
				)
			);
		}
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
