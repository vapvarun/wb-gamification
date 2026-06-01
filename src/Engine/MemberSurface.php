<?php
/**
 * WB Gamification — Member achievements surface (shared renderer).
 *
 * One source of truth for the "achievements surface" that integration
 * adapters mount inside a host's member area (BuddyPress profile tab,
 * WooCommerce My Account endpoint, LearnDash profile, ...). Each adapter
 * decides WHICH blocks to show and WHERE to mount; this class owns the
 * common plumbing — asset enqueue, block rendering scoped to a member, the
 * mapped "View full dashboard" link, and the shared wrapper — so no adapter
 * duplicates display logic.
 *
 * @package WB_Gamification
 * @since   1.5.2
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reusable member achievements surface.
 *
 * @package WB_Gamification
 */
final class MemberSurface {

	/**
	 * Enqueue the styles the reused blocks need on a non-block host page
	 * (BP screen, WC endpoint, LMS profile). Tokens + base frontend CSS +
	 * the Lucide icon font (action/stat glyphs) + the hub stylesheet when
	 * the hub block is used.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( 'wb-gam-tokens' );
		wp_enqueue_style( 'wb-gamification' );
		wp_enqueue_style( 'lucide-icons' );
		if ( wp_style_is( 'wb-gamification-hub', 'registered' ) ) {
			wp_enqueue_style( 'wb-gamification-hub' );
		}
	}

	/**
	 * Render one or more block shortcodes scoped to a member, concatenated.
	 *
	 * @param string[] $tags    Shortcode tags, e.g. ['wb_gam_member_points'].
	 * @param int      $user_id Member the blocks render for.
	 * @return string Concatenated block markup.
	 */
	public static function blocks( array $tags, int $user_id ): string {
		$out = '';
		foreach ( $tags as $tag ) {
			$out .= do_shortcode( sprintf( '[%s user_id="%d"]', $tag, $user_id ) );
		}
		return $out;
	}

	/**
	 * "View full dashboard" link to the MAPPED hub page (never a hardcoded
	 * slug). The hub renders the CURRENT user's data, so the link only
	 * appears on the member's own surface and when a hub page is mapped.
	 *
	 * @param int $user_id The surface's subject member.
	 * @return string Link markup, or '' when not applicable.
	 */
	public static function hub_link( int $user_id ): string {
		if ( get_current_user_id() !== $user_id ) {
			return '';
		}
		$hub_page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		$hub_url     = $hub_page_id ? get_permalink( $hub_page_id ) : '';
		if ( ! $hub_url ) {
			return '';
		}
		return sprintf(
			'<p class="wb-gam-bp-achievements__more"><a href="%s">%s</a></p>',
			esc_url( $hub_url ),
			esc_html__( 'View full dashboard', 'wb-gamification' )
		);
	}

	/**
	 * Build the full surface HTML: enqueue assets, append the hub link, wrap
	 * in the shared container. Callers echo the return value (it is already
	 * escaped — block SSR markup + escaped link).
	 *
	 * @param string $inner   Pre-rendered block markup.
	 * @param int    $user_id The surface's subject member.
	 * @return string Surface HTML.
	 */
	public static function render( string $inner, int $user_id ): string {
		self::enqueue_assets();
		$inner .= self::hub_link( $user_id );

		/**
		 * Filter the member achievements surface markup before output.
		 *
		 * Lets a host/integration wrap or augment the surface (e.g. add a
		 * heading) without duplicating the renderer.
		 *
		 * @since 1.5.2
		 *
		 * @param string $html    The wrapped surface markup.
		 * @param int    $user_id The surface's subject member.
		 */
		return (string) apply_filters(
			'wb_gam_member_surface_html',
			'<div class="wb-gam-bp-achievements">' . $inner . '</div>',
			$user_id
		);
	}
}
