<?php
/**
 * WB Gamification — Inject UI into block renders (extension slots)
 *
 * Use case: add custom UI before or after any built-in block (leaderboard,
 * streak, hub, kudos-feed, …) without forking the block.
 *
 * Three patterns demonstrated:
 *   1. Append a "Share" CTA below the leaderboard block.
 *   2. Prepend a banner above the streak block during a campaign window.
 *   3. Filter the leaderboard rows array to add a "trending" indicator.
 *
 * Two hooks fire for every block render:
 *   wb_gam_block_before_render( $slug, $attributes, $context )
 *   wb_gam_block_after_render(  $slug, $attributes, $context )
 *
 * Plus an optional data filter (where blocks invoke it):
 *   wb_gam_block_data( $data, $slug, $attributes )
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pattern 1: Append a "Share my rank" CTA below the leaderboard.
 */
add_action(
	'wb_gam_block_after_render',
	function ( string $slug, array $attributes, array $context ): void {
		if ( 'leaderboard' !== $slug ) {
			return;
		}

		// Skip the CTA on archive / category pages — only render on the
		// dedicated leaderboard page (use $attributes / $context as needed).
		if ( ! empty( $attributes['hide_cta'] ) ) {
			return;
		}

		?>
		<div class="yourplugin-leaderboard-cta">
			<button type="button" class="yourplugin-share-rank">
				<?php esc_html_e( 'Share my rank →', 'your-plugin' ); ?>
			</button>
		</div>
		<?php
	},
	10,
	3
);

/**
 * Pattern 2: Prepend a campaign banner above the streak block during
 *            a "double points week" promotion.
 */
add_action(
	'wb_gam_block_before_render',
	function ( string $slug, array $attributes, array $context ): void {
		if ( 'streak' !== $slug ) {
			return;
		}

		$campaign_start = strtotime( '2026-06-01 00:00:00 UTC' );
		$campaign_end   = strtotime( '2026-06-08 23:59:59 UTC' );
		$now            = time();
		if ( $now < $campaign_start || $now > $campaign_end ) {
			return;
		}

		?>
		<div class="yourplugin-campaign-banner">
			<strong>🔥 <?php esc_html_e( 'Double points week!', 'your-plugin' ); ?></strong>
			<?php esc_html_e( 'Every action this week earns 2× points.', 'your-plugin' ); ?>
		</div>
		<?php
	},
	10,
	3
);

/**
 * Pattern 3: Annotate leaderboard rows via wb_gam_block_data filter.
 *
 * Listeners receive ($data, $slug, $attributes). For the leaderboard,
 * $data is an array of row dictionaries with rank/user_id/points.
 *
 * Note: not every block fires this filter today — see each block's
 * render.php to verify before relying on it. The hooks above
 * (before_render / after_render) fire for ALL blocks unconditionally.
 */
add_filter(
	'wb_gam_block_data',
	function ( $data, string $slug, array $attributes ) {
		if ( 'leaderboard' !== $slug ) {
			return $data;
		}

		// Annotate each row with a "trending" indicator.
		foreach ( (array) $data as &$row ) {
			$row['trending'] = ! empty( $row['user_id'] )
				&& yourplugin_user_is_trending( (int) $row['user_id'] );
		}

		return $data;
	},
	10,
	3
);

/**
 * Pattern 4: Generic "any block rendered" listener — for analytics.
 *
 * Fires once per block render. Useful for tracking which blocks are
 * actually used on the site (analytics + impressions).
 */
add_action(
	'wb_gam_block_after_render',
	function ( string $slug, array $attributes, array $context ): void {
		// Increment a per-block render counter (for impressions analytics).
		$counts = (array) get_transient( 'yourplugin_block_renders' );
		$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
		set_transient( 'yourplugin_block_renders', $counts, HOUR_IN_SECONDS );
	},
	5,
	3
);

/**
 * Helper for Pattern 3 (stub — replace with your real trending logic).
 */
function yourplugin_user_is_trending( int $user_id ): bool {
	// Real implementation: query last 7 days of points for this user.
	return (bool) get_user_meta( $user_id, '_yourplugin_trending', true );
}
