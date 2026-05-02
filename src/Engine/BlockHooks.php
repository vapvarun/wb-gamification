<?php
/**
 * WB Gamification — Block extension hooks
 *
 * Provides a uniform extension API for the 12 server-rendered blocks.
 * Every block render fires:
 *
 *   wb_gam_block_before_render( $slug, $attributes, $context )
 *   wb_gam_block_after_render(  $slug, $attributes, $context )
 *
 * Listeners can hook the generic action and switch on $slug to inject
 * UI before or after any block. The $context array carries per-block
 * runtime state (typically user_id and any block-specific keys), so a
 * listener doesn't have to re-derive what the block already computed.
 *
 * For data-shape mutation, a separate filter
 * wb_gam_block_data is fired by blocks whose render benefits from
 * a transformable payload. Not all blocks fire it — see each render.php.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Block extension hook helpers.
 *
 * Each block render.php calls BlockHooks::before() / BlockHooks::after()
 * around its main output. Early-return empty-state paths intentionally
 * skip the hooks — extensions should not fire for non-rendered blocks.
 *
 * @package WB_Gamification
 */
final class BlockHooks {

	/**
	 * Fire the before_render action for a block.
	 *
	 * @param string $slug       Block slug (without the wb-gamification/ namespace prefix).
	 * @param array  $attributes Block attributes resolved by the editor / shortcode.
	 * @param array  $context    Per-block runtime state — user_id, target_id, etc.
	 *                           Listeners receive this to avoid re-deriving state.
	 */
	public static function before( string $slug, array $attributes, array $context = array() ): void {
		/**
		 * Fires immediately before a WB Gamification block emits any HTML.
		 *
		 * Use this hook to inject UI above the block, modify CSS classes
		 * via output buffering, log impressions, or short-circuit the
		 * render via output capture.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug       Block slug (e.g. 'leaderboard', 'streak').
		 * @param array  $attributes Block attributes.
		 * @param array  $context    Per-block runtime state.
		 */
		do_action( 'wb_gam_block_before_render', $slug, $attributes, $context );
	}

	/**
	 * Fire the after_render action for a block.
	 *
	 * @param string $slug       Block slug.
	 * @param array  $attributes Block attributes.
	 * @param array  $context    Per-block runtime state.
	 */
	public static function after( string $slug, array $attributes, array $context = array() ): void {
		/**
		 * Fires immediately after a WB Gamification block finishes its HTML.
		 *
		 * Use this hook to append UI (e.g. a "share this rank" button below
		 * the leaderboard, a "send kudos" CTA below the kudos feed), inject
		 * analytics beacons, or react to the block having rendered.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug       Block slug (e.g. 'leaderboard', 'streak').
		 * @param array  $attributes Block attributes.
		 * @param array  $context    Per-block runtime state.
		 */
		do_action( 'wb_gam_block_after_render', $slug, $attributes, $context );
	}

	/**
	 * Run a value through the wb_gam_block_data filter for a block.
	 *
	 * Blocks call this to let extensions transform the data they're about
	 * to render — leaderboard rows, badge cards, point-history entries,
	 * etc. Listeners inspect $slug and act only on the blocks they care
	 * about.
	 *
	 * @param string $slug       Block slug.
	 * @param mixed  $data       Data the block is about to render.
	 * @param array  $attributes Block attributes.
	 * @return mixed Filtered data (same shape as input).
	 */
	public static function filter_data( string $slug, $data, array $attributes ) {
		/**
		 * Filter the data a WB Gamification block is about to render.
		 *
		 * Use this to annotate, sort, slice, or replace block payloads.
		 * The shape varies per block — see each block's render.php for
		 * the contract.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $data       Block-specific payload.
		 * @param string $slug       Block slug.
		 * @param array  $attributes Block attributes.
		 */
		return apply_filters( 'wb_gam_block_data', $data, $slug, $attributes );
	}
}
