<?php
/**
 * Prev/next pager shared by the admin list pages.
 *
 * Styled by `.wbgam-pager` in assets/css/admin/components.css, which every
 * WB Gamification admin page loads.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Previous / "Page x of y" / Next row under an admin list.
 */
final class Pager {

	/**
	 * Echo the pager. Prints nothing when everything fits on one page.
	 *
	 * @param int                  $paged  Current page (1-based).
	 * @param int                  $pages  Total pages.
	 * @param array<string, mixed> $query  Query args every page link keeps (page slug, filters, sort).
	 * @param string               $label  Accessible name for the nav, e.g. "Kudos pages".
	 * @param string               $status Translated status text, e.g. "Page 2 of 5 (93 kudos)".
	 */
	public static function render( int $paged, int $pages, array $query, string $label, string $status ): void {
		if ( $pages <= 1 ) {
			return;
		}

		echo '<nav class="wbgam-pager" aria-label="' . esc_attr( $label ) . '">';
		if ( $paged > 1 ) {
			printf(
				'<a class="button" href="%s">%s</a> ',
				esc_url( add_query_arg( array_merge( $query, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Previous', 'wb-gamification' )
			);
		}
		printf( '<span class="wbgam-pager__status">%s</span> ', esc_html( $status ) );
		if ( $paged < $pages ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( add_query_arg( array_merge( $query, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Next', 'wb-gamification' )
			);
		}
		echo '</nav>';
	}

	/**
	 * Current page from the request, clamped to 1..$pages.
	 *
	 * @param int $pages Total pages.
	 * @return int
	 */
	public static function current( int $pages ): int {
		// Read-only list navigation; no state change, so no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		return min( max( 1, $paged ), max( 1, $pages ) );
	}
}
