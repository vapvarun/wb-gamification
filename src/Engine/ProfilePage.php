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
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
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
		if ( 'profile' !== self::decide( $user ? $user : null ) ) {
			// No such member and a private profile get the same response (status, headers, body),
			// so the page cannot be used to find out which usernames exist.
			self::render_private();
			exit;
		}

		/**
		 * Filter a URL to redirect this standalone `/u/` profile to. BuddyNext —
		 * the master community — owns the member profile, and the same standing,
		 * badges and points already render on the BuddyNext profile, so its bridge
		 * returns the BuddyNext profile URL here and the visitor lands there
		 * (BuddyNext then applies its own profile visibility). Empty string (the
		 * default) renders wb-gamification's own page, for standalone sites.
		 *
		 * @param string   $url     Redirect target. Default '' (render own page).
		 * @param int      $user_id Profile owner.
		 * @param \WP_User  $user    Profile owner object.
		 */
		$wb_gam_redirect = (string) apply_filters( 'wb_gam_profile_redirect_url', '', (int) $user->ID, $user );
		if ( '' !== $wb_gam_redirect ) {
			wp_safe_redirect( $wb_gam_redirect, 302 );
			exit;
		}

		status_header( 200 );
		nocache_headers();

		self::$title = (string) $user->display_name;
		self::render_profile( $user );
		exit;
	}

	/**
	 * What a /u/ request shows: 'profile' (the full page) or 'private' (the neutral page).
	 *
	 * The owner and admins always see the full page, even when the member opted out of public
	 * visibility ("where can I see my own progress" must never fail for the owner). An unknown
	 * username is 'private', exactly like a private profile.
	 *
	 * @param \WP_User|null $user Profile owner, null when no member has that username.
	 */
	public static function decide( ?\WP_User $user ): string {
		if ( ! $user ) {
			return 'private';
		}
		$can_view = self::is_publicly_visible( (int) $user->ID )
			|| get_current_user_id() === (int) $user->ID
			|| current_user_can( 'manage_options' );
		return $can_view ? 'profile' : 'private';
	}

	/**
	 * Browser-tab title of the page this class is rendering ('' = not ours).
	 *
	 * @var string
	 */
	private static string $title = '';

	/**
	 * Title the standalone profile after the member (the page has no post, so the theme's title
	 * was the site name alone).
	 *
	 * @param array<string, string> $parts Document title parts.
	 * @return array<string, string>
	 */
	public static function document_title( $parts ) {
		if ( '' === self::$title || ! is_array( $parts ) ) {
			return $parts;
		}
		$parts['title'] = self::$title;
		unset( $parts['tagline'] );
		return $parts;
	}

	/**
	 * The page for a private profile AND for a username no member has: one neutral line, no
	 * name, no avatar, no numbers, and nothing that depends on the member, so the two cases are
	 * byte-identical. 403 rather than a soft 200, so search engines and caches never treat it as
	 * public content; noindex for crawlers that index error pages anyway.
	 */
	public static function render_private(): void {
		status_header( 403 );
		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		wp_enqueue_style( 'wb-gamification' );

		self::$title = __( 'Private profile', 'wb-gamification' );

		get_header();
		echo '<div class="wb-gam-profile-page wb-gam-profile-page--private">';
		echo '<h1 class="wb-gam-profile-page__name">' . esc_html__( 'This profile is private', 'wb-gamification' ) . '</h1>';
		echo '<p class="wb-gam-profile-page__stats">' . esc_html__( 'The member has chosen not to share their achievements.', 'wb-gamification' ) . '</p>';
		echo '</div>';
		get_footer();
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
		// Trailing slash deliberately. The rewrite rule accepts both forms, but
		// WordPress canonical-redirects the unslashed one, so emitting it cost a
		// 301 on every link. That was cheap to ignore while only the share page
		// linked here; since 1.6.4 every leaderboard row, podium slot and kudos
		// entry resolves through MemberUrl to this URL, so it is the form that
		// should ship — and the one og:url ought to advertise.
		return home_url( '/' . self::slug_base() . '/' . rawurlencode( $user_login ) . '/' );
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
		// The guest view of the one privacy rule (site switch, member toggle, and a host
		// community's own profile privacy via wb_gam_can_view_public_profile) - so the /u/ page
		// and member links agree with every block and REST route.
		$visible = Privacy::can_view_public_profile( $user_id, 0 );

		/**
		 * Filter whether a member's profile page is publicly visible.
		 *
		 * Prefer wb_gam_can_view_public_profile (viewer-aware, applies everywhere); this one
		 * still runs after it for the /u/ page and member links.
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
		// Hidden when a community plugin decides privacy: the switch would do nothing there.
		if ( get_current_user_id() === (int) $user->ID && ! Privacy::host_decides() ) {
			self::render_owner_visibility_control( (int) $user->ID );
		}
		// The member's own rank / leaderboard / email choices (owner-only inside).
		MemberPrefs::render_controls( (int) $user->ID );

		echo '<section class="wb-gam-profile-page__section">';
		echo do_shortcode( '[wb_gam_badge_showcase user_id="' . (int) $user->ID . '"]' );
		echo '</section>';

		// Points history is the member's own record: only they and admins see it. Anyone else
		// sees the public parts above with no history block and no log-in prompt.
		if ( Privacy::can_view_private_history( (int) $user->ID ) ) {
			echo '<section class="wb-gam-profile-page__section">';
			echo do_shortcode( '[wb_gam_points_history user_id="' . (int) $user->ID . '" limit="10"]' );
			echo '</section>';
		}

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
}
