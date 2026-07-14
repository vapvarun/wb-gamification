<?php
/**
 * The one empty state.
 *
 * Thirteen blocks each hand-rolled the same three lines:
 *
 *     <div {wrapper}><p class="wb-gam-{block}__empty">{message}</p></div>
 *
 * Thirteen copies is not a disaster on its own — they were all roughly right. It is a problem the day
 * someone wants an empty state to do anything MORE than say a sentence: add a call to action, an icon,
 * a link to the earning guide. Then it is thirteen edits, and the twelfth gets forgotten.
 *
 * This is a consolidation, not a redesign. **The markup is byte-identical to what each block emitted
 * before**, including its own BEM class — the CSS does not move, and neither does anything on screen.
 * What changes is that there is now one place to change it, and one filter for a site owner who wants
 * their own words.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a block's empty state.
 *
 * @package WB_Gamification
 */
final class EmptyState {

	/**
	 * Render the empty state for a block.
	 *
	 * @param string $block   Block slug, e.g. `points-history`. Used for the BEM class and the filter.
	 * @param string $wrapper The block wrapper attributes (already escaped by get_block_wrapper_attributes()).
	 * @param string $message The message. Plain text; escaped here.
	 * @param array  $cta     Optional. `url` + `label` for a call to action.
	 * @return string The empty-state HTML.
	 */
	public static function body( string $block, string $message, string $icon = '' ): string {
		// The empty state WITHOUT a wrapper, because half the blocks cannot use the wrappered one.
		//
		// render() below emits its own <div {wrapper}> and is right for a block whose empty state is
		// the WHOLE output -- an early return. But six blocks (leaderboard, badge-showcase, kudos-feed,
		// challenges, community-challenges, redemption-store) render a header first and then the empty
		// state INSIDE that wrapper, so render() could not be used and they kept hand-rolling it.
		//
		// The result was that the shared partial went into the seven blocks that did not really need it
		// and skipped the six the card was about -- the duplication it existed to kill survived exactly
		// where it lived. A shared partial that does not fit the shape of the thing it is meant to
		// share is not shared; it is a ninth copy.
		//
		// Markup is byte-identical to what each of the six emitted, icon included, so no CSS moves.
		$class = 'wb-gam-' . $block . '__empty';

		$inner = '' !== $icon
			? $icon . '<span>' . esc_html( $message ) . '</span>'
			: esc_html( $message );

		$html = '<p class="' . esc_attr( $class ) . '">' . $inner . '</p>';

		/** This filter is documented in src/Blocks/EmptyState.php -- see render(). */
		return (string) apply_filters( "wb_gam_empty_state_{$block}", $html, $block, $message );
	}

	/**
	 * The empty state as a STACKED card: icon above centred text, inside its own div.
	 *
	 * The third and last shape. `challenges` and `points-history` both render this -- icon on top, text
	 * beneath, the whole thing a padded panel -- which is a different design from the inline row that
	 * body() emits, and the reason I left them hand-rolled last time. That was the wrong call: two
	 * blocks sharing a shape is not a special case, it is a shape, and leaving them out meant
	 * `wb_gam_empty_state_challenges` and `wb_gam_empty_state_points-history` were filters that could
	 * never fire. A filter nobody can use is worse than no filter -- it is a documented lie.
	 *
	 * Markup is byte-identical to what both blocks emitted, so no CSS moves.
	 *
	 * @param string $block   Block slug.
	 * @param string $message The message. Plain text; escaped here.
	 * @param string $icon    Pre-rendered icon SVG (already escaped by Icon::svg).
	 * @return string The empty-state HTML, no block wrapper.
	 */
	public static function stacked( string $block, string $message, string $icon = '' ): string {
		$class = 'wb-gam-' . $block . '__empty';

		$html = '<div class="' . esc_attr( $class ) . '">'
			. $icon
			. '<p>' . esc_html( $message ) . '</p>'
			. '</div>';

		/** This filter is documented in src/Blocks/EmptyState.php -- see render(). */
		return (string) apply_filters( "wb_gam_empty_state_{$block}", $html, $block, $message );
	}

	/**
	 * Render the empty state for a block, wrapper and all.
	 *
	 * For a block whose empty state IS the whole output (an early return). If the block renders a
	 * header first and then an empty body inside its own wrapper, use body() instead.
	 *
	 * @param string $block   Block slug, e.g. `points-history`.
	 * @param string $wrapper The block wrapper attributes (already escaped).
	 * @param string $message The message. Plain text; escaped here.
	 * @param array  $cta     Optional. `url` + `label` for a call to action.
	 * @return string The empty-state HTML.
	 */
	public static function render( string $block, string $wrapper, string $message, array $cta = array() ): string {
		$class = 'wb-gam-' . $block . '__empty';

		$html = '<p class="' . esc_attr( $class ) . '">' . esc_html( $message ) . '</p>';

		// A call to action, when there is one worth making. "You have no points yet" is a fact; "here is
		// how to earn some" is a reason to stay.
		if ( ! empty( $cta['url'] ) && ! empty( $cta['label'] ) ) {
			$html .= '<p class="' . esc_attr( $class . '-cta' ) . '"><a href="' . esc_url( (string) $cta['url'] ) . '">'
				. esc_html( (string) $cta['label'] ) . '</a></p>';
		}

		/**
		 * Filter a block's empty state.
		 *
		 * An owner whose community has its own voice can say it their way, per block, without
		 * overriding a template.
		 *
		 * @since 1.6.4
		 *
		 * @param string $html    The empty-state HTML (inside the wrapper).
		 * @param string $block   Block slug.
		 * @param string $message The default message.
		 */
		$html = (string) apply_filters( "wb_gam_empty_state_{$block}", $html, $block, $message );

		// The wrapper is get_block_wrapper_attributes() output — already escaped, and not ours to
		// escape again.
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}
