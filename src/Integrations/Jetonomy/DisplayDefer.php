<?php
/**
 * WB Gamification — defer the LEADERBOARD display to Jetonomy.
 *
 * Jetonomy ships a reputation leaderboard (/community/leaderboard/, ranked
 * `ORDER BY reputation DESC`). wb-gamification mirrors every Jetonomy
 * reputation delta 1:1 into its points ledger (see JetonomyIntegration), so on
 * a Jetonomy site wb-gam's own leaderboard is a genuine DUPLICATE ranking —
 * same members, same order. Rather than show two competing leaderboards, we
 * suppress wb-gam's leaderboard + top-members blocks/shortcodes and let
 * Jetonomy's be the single source of truth.
 *
 * Badges are deliberately NOT deferred: wb-gam's badge engine (OpenBadges 3.0,
 * expiry, share pages, cross-integration triggers) is the stronger, broader
 * system, and the two badge SETS are complementary, not duplicates (Jetonomy =
 * forum-native criteria; wb-gam = site-wide actions). Both keep rendering.
 *
 * Filterable:
 *   - wb_gam_defer_leaderboard_to_jetonomy (default: Jetonomy active)
 *
 * @package WB_Gamification
 * @since   1.5.2
 */

namespace WBGam\Integrations\Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Suppresses wb-gam leaderboard display when Jetonomy owns the ranking.
 *
 * @package WB_Gamification
 */
final class DisplayDefer {

	/**
	 * Block names suppressed when deferring.
	 *
	 * @var string[]
	 */
	private const BLOCKS = array( 'wb-gamification/leaderboard', 'wb-gamification/top-members' );

	/**
	 * Shortcode tags suppressed when deferring.
	 *
	 * @var string[]
	 */
	private const SHORTCODES = array( 'wb_gam_leaderboard', 'wb_gam_top_members' );

	/**
	 * Whether wb-gam's leaderboard display defers to Jetonomy's. Defaults to
	 * "Jetonomy is active" (it ships the reputation leaderboard wb-gam mirrors).
	 */
	public static function defers_leaderboard(): bool {
		return (bool) apply_filters( 'wb_gam_defer_leaderboard_to_jetonomy', defined( 'JETONOMY_VERSION' ) );
	}

	/**
	 * Wire the suppression filters when the leaderboard is being deferred.
	 */
	public static function init(): void {
		if ( ! self::defers_leaderboard() ) {
			return;
		}

		add_filter( 'render_block', array( __CLASS__, 'maybe_suppress_block' ), 10, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'maybe_suppress_shortcode' ), 10, 2 );
	}

	/**
	 * Blank a deferred block's output.
	 *
	 * Visitors see nothing (Jetonomy owns the ranking). Site editors get a
	 * notice instead of a silent blank, so a deferred block on a dedicated page
	 * isn't a mystery — the previous behaviour returned '' for everyone, which
	 * looked like a broken block.
	 *
	 * @param string $content Rendered block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function maybe_suppress_block( $content, $block ) {
		if ( isset( $block['blockName'] ) && in_array( $block['blockName'], self::BLOCKS, true ) ) {
			return self::deferred_replacement( $block['blockName'] );
		}
		return $content;
	}

	/**
	 * Blank a deferred shortcode's output (covers member surfaces that render
	 * the leaderboard via shortcodes too).
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	public static function maybe_suppress_shortcode( $output, $tag ) {
		if ( in_array( $tag, self::SHORTCODES, true ) ) {
			$is_top = false !== strpos( (string) $tag, 'top_members' );
			return self::deferred_replacement( $is_top ? 'wb-gamification/top-members' : 'wb-gamification/leaderboard' );
		}
		return $output;
	}

	/**
	 * What a deferred leaderboard/top-members block renders in place of its
	 * (duplicate) ranking: nothing for visitors, an explanatory notice for
	 * users who can edit content, so the deferral is discoverable instead of a
	 * silently blank block.
	 *
	 * @param string $block_name Suppressed block name.
	 * @return string
	 */
	private static function deferred_replacement( string $block_name ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$label = ( 'wb-gamification/top-members' === $block_name )
			? __( 'Top Members', 'wb-gamification' )
			: __( 'Leaderboard', 'wb-gamification' );

		$message = sprintf(
			/* translators: %s: block label (Leaderboard or Top Members). */
			__( 'The %s block is hidden because Jetonomy is active and owns the community ranking, which this block would duplicate. Only people who can edit content see this notice; visitors see nothing. To show this block anyway, add the filter wb_gam_defer_leaderboard_to_jetonomy and return false.', 'wb-gamification' ),
			$label
		);

		return sprintf(
			'<div class="wb-gam-deferred-notice" role="note" style="border:1px dashed #d0d5dd;border-radius:8px;padding:12px 16px;margin:8px 0;color:#667085;font-size:14px;line-height:1.5;">%s</div>',
			esc_html( $message )
		);
	}
}
