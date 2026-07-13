<?php
/**
 * WB Gamification — Public Profile Pages
 *
 * Adds a permalink at `/u/{user_login}` that renders a member's
 * gamification showcase. Reuses the existing member-points,
 * badge-showcase, and points-history blocks under one wrapper template.
 *
 * Privacy model (opt-OUT, default public since 1.5.2):
 *   - Site-wide toggle: `wb_gam_profile_public_enabled` (default on)
 *   - Per-user toggle:  user_meta `wb_gam_profile_public` (only an explicit
 *     '0' makes a profile private; unset/empty means public)
 *   - Filter `wb_gam_profile_publicly_visible` can override per request
 *
 * The owner and admins can always view a profile regardless of the flags.
 * Profiles are public by default so members are showcased without an opt-in
 * step (pre-1.5.2 this required an opt-in that no screen ever set, so every
 * /u/{user_login} returned 404).
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

	private const QUERY_VAR   = 'wb_gam_profile';
	private const OPT_ENABLED = 'wb_gam_profile_public_enabled';
	private const OPT_SLUG    = 'wb_gam_profile_slug_base';
	public const META_PUBLIC  = 'wb_gam_profile_public';

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

		// The profile owner and admins can always view the profile, even if
		// the member has opted out of public visibility — "where can I see my
		// own progress" must never 404 for the owner.
		$can_view = self::is_publicly_visible( (int) $user->ID )
			|| get_current_user_id() === (int) $user->ID
			|| current_user_can( 'manage_options' );
		if ( ! $can_view ) {
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
			__( '%1$s - %2$s', 'wb-gamification' ),
			$user->display_name,
			$site_name
		);
		$desc = sprintf(
			/* translators: 1: display name, 2: amount, 3: currency label, 4: badge count with label, 5: level name */
			__( '%1$s has earned %2$d %3$s, %4$s, and reached %5$s.', 'wb-gamification' ),
			$user->display_name,
			$points,
			$points_label,
			sprintf(
				/* translators: %d: number of badges earned. */
				_n( '%d badge', '%d badges', $badge_count, 'wb-gamification' ),
				$badge_count
			),
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
	 * Opt-OUT model (default ON): public profiles showcase community
	 * progress out of the box, so a member is visible unless they have
	 * explicitly set the per-user flag to '0'. An empty/unset value means
	 * public. The site-wide kill switch (OPT_ENABLED) still wins, and the
	 * `wb_gam_profile_publicly_visible` filter lets a site override per user.
	 *
	 * (Before 1.5.2 this required an opt-IN flag that no member-facing UI
	 * ever wrote, so every /u/ profile 404'd.)
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_publicly_visible( int $user_id ): bool {
		if ( ! get_option( self::OPT_ENABLED, '1' ) ) {
			return false;
		}
		// Default ON: only an explicit '0' makes the profile private.
		$pref    = get_user_meta( $user_id, self::META_PUBLIC, true );
		$visible = ( '0' !== (string) $pref );

		/**
		 * Filter whether a member's profile page is publicly visible.
		 *
		 * @since 1.5.2
		 * @param bool $visible Whether the profile is public (default ON).
		 * @param int  $user_id Profile owner user ID.
		 */
		return (bool) apply_filters( 'wb_gam_profile_publicly_visible', $visible, $user_id );
	}

	/**
	 * Has the member explicitly opted their own profile private?
	 *
	 * Reads ONLY the per-user choice (`wb_gam_profile_public` === '0'),
	 * independent of the site-wide kill switch and the visibility filter.
	 * Used by the member-facing toggle to reflect the owner's own setting,
	 * not the net computed visibility.
	 *
	 * @since 1.5.5
	 * @param int $user_id User ID.
	 * @return bool True when the member has chosen to hide their profile.
	 */
	public static function member_opted_private( int $user_id ): bool {
		return '0' === (string) get_user_meta( $user_id, self::META_PUBLIC, true );
	}

	/**
	 * Set a member's own profile visibility choice — the write path for the
	 * `wb_gam_profile_public` user-meta the read gates have always consumed.
	 *
	 * Before 1.5.5 this meta was read by {@see Privacy::can_view_public_profile()}
	 * and {@see is_publicly_visible()} and registered in the GDPR export/erase
	 * model, but nothing ever wrote it — so a member could not make their own
	 * profile private. This is that missing surface.
	 *
	 * Stores an explicit '1' (public) or '0' (private) so the choice round-trips
	 * through the privacy exporter; the read side treats anything other than '0'
	 * as public, so unset members stay public by default.
	 *
	 * @since 1.5.5
	 * @param int  $user_id User whose choice to set.
	 * @param bool $public  True to make the profile public, false to hide it.
	 * @return void
	 */
	public static function set_member_visibility( int $user_id, bool $public ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::META_PUBLIC, $public ? '1' : '0' );

		/**
		 * Fires after a member changes their own profile visibility.
		 *
		 * @since 1.5.5
		 * @param int  $user_id Member who changed the setting.
		 * @param bool $public  New visibility (true = public).
		 */
		do_action( 'wb_gam_profile_visibility_set', $user_id, $public );
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
					/* translators: 1: amount, 2: currency, 3: badge count with label, 4: level */
					__( '%1$d %2$s · %3$s · %4$s', 'wb-gamification' ),
					$points,
					$points_label,
					sprintf(
						/* translators: %d: number of badges earned. */
						_n( '%d badge', '%d badges', $badge_count, 'wb-gamification' ),
						$badge_count
					),
					$level['name'] ?? __( 'Newcomer', 'wb-gamification' )
				)
			)
		);
		echo '</div></header>';

		// Owner-only privacy control — the member-facing write surface for
		// wb_gam_profile_public. Only the profile owner sees it (admins
		// viewing another member must not flip that member's choice here).
		if ( get_current_user_id() === (int) $user->ID ) {
			self::render_owner_visibility_control( (int) $user->ID );
		}

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
	 * Render the owner's profile-visibility toggle.
	 *
	 * A button that POSTs to `wb-gamification/v1/members/me/profile-visibility`
	 * (self-only, nonce-guarded) to flip `wb_gam_profile_public`. Self-contained
	 * via data attributes (REST root + nonce), matching the give-kudos pattern,
	 * so no separate wp_localize_script is needed.
	 *
	 * @since 1.5.5
	 * @param int $user_id Profile owner (always the current user here).
	 * @return void
	 */
	private static function render_owner_visibility_control( int $user_id ): void {
		wp_enqueue_script(
			'wb-gam-profile-visibility',
			WB_GAM_URL . 'assets/js/profile-visibility.js',
			array( 'wb-gam-mount', 'wb-gam-rest' ),
			WB_GAM_VERSION,
			true
		);
		wp_enqueue_style( 'wb-gamification' );

		$is_private    = self::member_opted_private( $user_id );
		$site_disabled = ! get_option( self::OPT_ENABLED, '1' );

		$copy_public  = __( 'This profile is visible to anyone with the link.', 'wb-gamification' );
		$copy_private = __( 'Only you and site admins can see this profile.', 'wb-gamification' );
		$make_public  = __( 'Make profile public', 'wb-gamification' );
		$make_private = __( 'Make profile private', 'wb-gamification' );

		printf(
			'<section class="wb-gam-profile-page__section wb-gam-profile-privacy" data-rest-url="%s" data-rest-nonce="%s" data-error="%s">',
			esc_url_raw( rest_url( 'wb-gamification/v1/members/me/profile-visibility' ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_attr__( 'Could not save. Please try again.', 'wb-gamification' )
		);

		echo '<div class="wb-gam-profile-privacy__row">';

		echo '<div class="wb-gam-profile-privacy__copy">';
		echo '<span class="wb-gam-profile-privacy__label">' . esc_html__( 'Profile visibility', 'wb-gamification' ) . '</span>';
		printf(
			'<span class="wb-gam-profile-privacy__state" data-public="%s" data-public-copy="%s" data-private-copy="%s">%s</span>',
			$is_private ? '0' : '1',
			esc_attr( $copy_public ),
			esc_attr( $copy_private ),
			esc_html( $is_private ? $copy_private : $copy_public )
		);
		echo '</div>';

		printf(
			'<button type="button" class="wb-gam-profile-privacy__toggle" aria-pressed="%s" data-make-public="%s" data-make-private="%s" data-saving="%s">%s</button>',
			$is_private ? 'true' : 'false',
			esc_attr( $make_public ),
			esc_attr( $make_private ),
			esc_attr__( 'Saving…', 'wb-gamification' ),
			esc_html( $is_private ? $make_public : $make_private )
		);

		echo '</div>';

		if ( $site_disabled ) {
			echo '<p class="wb-gam-profile-privacy__note">'
				. esc_html__( 'Note: public profiles are currently turned off site-wide by the administrator, so your profile is hidden regardless of this setting.', 'wb-gamification' )
				. '</p>';
		}

		echo '<p class="wb-gam-profile-privacy__status" role="status" aria-live="polite"></p>';
		echo '</section>';
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
