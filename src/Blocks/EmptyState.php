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
