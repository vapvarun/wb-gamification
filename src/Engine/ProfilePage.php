<?php
/**
 * WB Gamification — Public Profile Pages
 *
 * Adds a permalink at `/u/{user_login}` that renders a member's
 * gamification showcase. Reuses the existing member-points,
 * badge-showcase, and points-history blocks under one wrapper template.
 *
 * Privacy model:
 *   - Site-wide toggle: `wb_gam_profile_public_enabled` (default on)
 *   - Per-user toggle:  user_meta `wb_gam_profile_public` (default 0 → opt-in)
 *
 * Without both flags set, the URL returns 404 — opt-in by default so
 * existing members aren't suddenly indexed.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

use WBGam\Services\PointTypeService;

defined( 'ABSPATH' ) || exit;

/**
 * Renders public member profile pages.
 */
final class ProfilePage {

	private const QUERY_VAR    = 'wb_gam_profile';
	private const OPT_ENABLED  = 'wb_gam_profile_public_enabled';
	private const OPT_SLUG     = 'wb_gam_profile_slug_base';
	public const META_PUBLIC   = 'wb_gam_profile_public';

	/**
	 * Boot — register rewrite rule + query var + template hook.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_action( 'wp_head', array( __CLASS__, 'render_meta' ), 1 );
	}

	/**
	 * Register the rewrite rule. Slug base is filterable (default 'u').
	 */
	public static function register_rewrite(): void {
		$slug = self::slug_base();
		add_rewrite_rule(
			'^' . preg_quote( $slug, '#' ) . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Whitelist the query var so WP query-vars survive parsing.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * If the request matches our query var, render the profile page and exit.
	 *
	 * Hooked on `template_redirect` (runs after WP::main, before any template
	 * include). We control the entire response — no template lookup needed.
	 */
	public static function maybe_render(): void {
		$slug = get_query_var( self::QUERY_VAR );
		if ( '' === (string) $slug ) {
			return;
		}

		$user = get_user_by( 'login', sanitize_user( (string) $slug ) );
		if ( ! $user ) {
			self::trigger_404();
			return;
		}

		if ( ! self::is_publicly_visible( (int) $user->ID ) ) {
			self::trigger_404();
			return;
		}

		status_header( 200 );
		nocache_headers();

		self::render_profile( $user );
		exit;
	}

	/**
	 * Render the OG / Schema.org meta tags in <head>.
	 */
	public static function render_meta(): void {
		$slug = get_query_var( self::QUERY_VAR );
		if ( '' === (string) $slug ) {
			return;
		}
		$user = get_user_by( 'login', sanitize_user( (string) $slug ) );
		if ( ! $user || ! self::is_publicly_visible( (int) $user->ID ) ) {
			return;
		}

		$pt_service   = new PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );
		$points       = (int) PointsEngine::get_total( (int) $user->ID );
		$level        = LevelEngine::get_level_for_user( (int) $user->ID );
		$badge_count  = count( BadgeEngine::get_user_earned_badge_ids( (int) $user->ID ) );
		$avatar       = get_avatar_url( (int) $user->ID, array( 'size' => 256 ) );
		$page_url     = self::profile_url( (string) $user->user_login );
		$site_name    = (string) get_bloginfo( 'name' );

		$title = sprintf(
			/* translators: 1: display name, 2: site name */
			__( '%1$s — %2$s', 'wb-gamification' ),
			$user->display_name,
			$site_name
		);
		$desc  = sprintf(
			/* translators: 1: display name, 2: amount, 3: currency label, 4: badge count, 5: level name */
			__( '%1$s has earned %2$d %3$s, %4$d badges, and reached %5$s.', 'wb-gamification' ),
			$user->display_name,
			$points,
			$points_label,
			$badge_count,
			$level['name'] ?? __( 'Newcomer', 'wb-gamification' )
		);

		// Canonical link — claim the /u/{slug} URL as the authoritative
		// destination for this member's gamification showcase. Without this,
		// search engines may treat WP core's /author/{slug}/ archive as the
		// canonical and our richer /u/{slug} page never wins. Themes that
		// emit their own canonical for the 404/template should not also
		// fire here because we exit before the theme template loads — but
		// a defensive remove_action skips any duplicate from rel-canonical
		// plugins that hooked at the same priority.
		remove_action( 'wp_head', 'rel_canonical' );
		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( $page_url )
		);

		// OG tags.
		printf(
			'<meta property="og:title" content="%s" />' . "\n",
			esc_attr( $title )
		);
		printf(
			'<meta property="og:description" content="%s" />' . "\n",
			esc_attr( $desc )
		);
		printf(
			'<meta property="og:url" content="%s" />' . "\n",
			esc_url( $page_url )
		);
		printf(
			'<meta property="og:image" content="%s" />' . "\n",
			esc_url( $avatar )
		);
		printf(
			'<meta property="og:type" content="profile" />' . "\n"
		);
		printf(
			'<meta name="twitter:card" content="summary" />' . "\n"
		);

		// Schema.org Person + achievements as JSON-LD.
		$jsonld = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Person',
			'name'             => (string) $user->display_name,
			'image'            => (string) $avatar,
			'url'              => $page_url,
			'description'      => $desc,
			'mainEntityOfPage' => $page_url,
		);
		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Build the public URL for a user.
	 *
	 * @param string $user_login User login (slug).
	 */
	public static function profile_url( string $user_login ): string {
		return home_url( '/' . self::slug_base() . '/' . rawurlencode( $user_login ) );
	}

	/**
	 * Determine whether the profile is publicly visible.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_publicly_visible( int $user_id ): bool {
		if ( ! get_option( self::OPT_ENABLED, '1' ) ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::META_PUBLIC, true );
	}

	/**
	 * Profile slug base (default 'u'). Filterable so themes can use
	 * `/profile/`, `/member/`, etc.
	 */
	public static function slug_base(): string {
		$slug = (string) get_option( self::OPT_SLUG, 'u' );
		$slug = trim( sanitize_title( $slug ), '/' );
		return $slug ?: 'u';
	}

	/**
	 * Render the profile body — header card + showcase blocks.
	 *
	 * @param \WP_User $user Profile owner.
	 */
	private static function render_profile( \WP_User $user ): void {
		$pt_service   = new PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );

		$points      = (int) PointsEngine::get_total( (int) $user->ID );
		$level       = LevelEngine::get_level_for_user( (int) $user->ID );
		$badge_count = count( BadgeEngine::get_user_earned_badge_ids( (int) $user->ID ) );
		$avatar      = get_avatar( (int) $user->ID, 96 );

		get_header();

		echo '<div class="wb-gam-profile-page">';
		echo '<header class="wb-gam-profile-page__hero">';
		echo '<div class="wb-gam-profile-page__avatar">' . wp_kses_post( $avatar ) . '</div>';
		echo '<div class="wb-gam-profile-page__head">';
		echo '<h1 class="wb-gam-profile-page__name">' . esc_html( $user->display_name ) . '</h1>';
		printf(
			'<p class="wb-gam-profile-page__stats">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: amount, 2: currency, 3: badges, 4: level */
					__( '%1$d %2$s · %3$d badges · %4$s', 'wb-gamification' ),
					$points,
					$points_label,
					$badge_count,
					$level['name'] ?? __( 'Newcomer', 'wb-gamification' )
				)
			)
		);
		echo '</div></header>';

		echo '<section class="wb-gam-profile-page__section">';
		echo do_shortcode( '[wb_gam_badge_showcase user_id="' . (int) $user->ID . '"]' );
		echo '</section>';

		echo '<section class="wb-gam-profile-page__section">';
		echo do_shortcode( '[wb_gam_points_history user_id="' . (int) $user->ID . '" limit="10"]' );
		echo '</section>';

		echo '</div>';

		get_footer();
	}

	/**
	 * Set the request to 404 — so theme renders its standard not-found page.
	 *
	 * Used when the slug doesn't resolve to a user OR the profile is private.
	 */
	private static function trigger_404(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
