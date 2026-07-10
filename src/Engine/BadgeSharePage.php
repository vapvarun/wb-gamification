<?php
/**
 * WB Gamification — Badge Share Page
 *
 * Registers a public-facing shareable credential page at:
 *   /gamification/badge/{badge_id}/{user_id}/share/
 *
 * The page renders HTML with Open Graph meta tags and a LinkedIn
 * "Add Certification" deep-link button.
 *
 * @package WB_Gamification
 * @since   0.4.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the badge share page rewrite rule and renders the shareable
 * credential page with Open Graph meta and a LinkedIn deep-link button.
 */
final class BadgeSharePage {

	/**
	 * Hook into WordPress rewrite, query_vars, and template_redirect.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * Register rewrite rules and flush on plugin activation.
	 */
	public static function activate(): void {
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrite rules on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	// ── Rewrite ───────────────────────────────────────────────────────────────

	/**
	 * Register the gamification badge share rewrite rule.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^gamification/badge/([a-z0-9_-]+)/([0-9]+)/share/?$',
			'index.php?wb_gam_badge_share=1&wb_gam_share_badge_id=$matches[1]&wb_gam_share_user_id=$matches[2]',
			'top'
		);
	}

	/**
	 * Register custom query vars for the share page.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'wb_gam_badge_share';
		$vars[] = 'wb_gam_share_badge_id';
		$vars[] = 'wb_gam_share_user_id';
		return $vars;
	}

	// ── Template ──────────────────────────────────────────────────────────────

	/**
	 * Intercept template_redirect and render the share page if applicable.
	 */
	public static function maybe_render(): void {
		if ( ! get_query_var( 'wb_gam_badge_share' ) ) {
			return;
		}

		$badge_id = sanitize_key( get_query_var( 'wb_gam_share_badge_id' ) );
		$user_id  = absint( get_query_var( 'wb_gam_share_user_id' ) );

		if ( ! $badge_id || ! $user_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$badge = BadgeEngine::get_badge_def( $badge_id );
		$user  = get_userdata( $user_id );

		if ( ! $badge || ! $user || ! BadgeEngine::has_badge( $user_id, $badge_id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$share_url   = self::get_share_url( $badge_id, $user_id );
		$cred_url    = rest_url( 'wb-gamification/v1/badges/' . $badge_id . '/credential/' . $user_id );
		$earned_at   = BadgeEngine::get_badge_row( $user_id, $badge_id )['earned_at'] ?? '';
		$issued_dt   = $earned_at ? new \DateTime( $earned_at, new \DateTimeZone( 'UTC' ) ) : null;
		$issue_year  = $issued_dt ? (int) $issued_dt->format( 'Y' ) : (int) gmdate( 'Y' );
		$issue_month = $issued_dt ? (int) $issued_dt->format( 'n' ) : (int) gmdate( 'n' );

		$linkedin_url = $badge['is_credential']
			? self::build_linkedin_url(
				$badge['name'],
				get_bloginfo( 'name' ),
				$issue_year,
				$issue_month,
				$cred_url,
				$badge_id . '_' . $user_id
			)
			: '';

		add_filter(
			'document_title_parts',
			static function ( array $parts ) use ( $badge, $user ): array {
				$parts['title'] = sprintf(
					/* translators: 1: badge name, 2: display name */
					__( '%1$s - earned by %2$s', 'wb-gamification' ),
					$badge['name'],
					$user->display_name
				);
				return $parts;
			}
		);

		// Dynamic, raster OG card so social platforms render a rich preview.
		// Falls back to the badge artwork URL when GD/font is unavailable.
		$og_image   = BadgeOgImage::ensure( $badge, $user, $issued_dt );
		$og_is_card = '' !== $og_image;
		if ( '' === $og_image ) {
			$og_image = $badge['image_url'] ?: '';
		}

		add_action(
			'wp_head',
			static function () use ( $badge, $user, $share_url, $og_image, $og_is_card ): void {
				$title = esc_attr(
					sprintf(
						/* translators: 1: badge name, 2: display name */
						__( '%1$s - earned by %2$s', 'wb-gamification' ),
						$badge['name'],
						$user->display_name
					)
				);
				$desc = esc_attr( $badge['description'] );
				$img  = esc_attr( $og_image );
				$url  = esc_attr( $share_url );
				echo "<meta property=\"og:type\" content=\"website\" />\n";
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				echo "<meta property=\"og:title\" content=\"{$title}\" />\n";
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				echo "<meta property=\"og:description\" content=\"{$desc}\" />\n";
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
				echo "<meta property=\"og:url\" content=\"{$url}\" />\n";
				if ( $img ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
					echo "<meta property=\"og:image\" content=\"{$img}\" />\n";
					if ( $og_is_card ) {
						echo '<meta property="og:image:width" content="' . (int) BadgeOgImage::WIDTH . "\" />\n";
						echo '<meta property="og:image:height" content="' . (int) BadgeOgImage::HEIGHT . "\" />\n";
					}
				}
				// Large card when we have the generated 1200x630 image.
				$card = $og_is_card ? 'summary_large_image' : 'summary';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
				echo "<meta name=\"twitter:card\" content=\"{$card}\" />\n";
				if ( $img ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
					echo "<meta name=\"twitter:image\" content=\"{$img}\" />\n";
				}
			}
		);

		// Depend on the shared design tokens (registered on wp_enqueue_scripts,
		// resolved at print time) so the card consumes the live theme palette.
		wp_enqueue_style( 'wb-gam-share-page', WB_GAM_URL . 'assets/css/share-page.css', array( 'wb-gam-tokens' ), WB_GAM_VERSION );
		wp_enqueue_script( 'wb-gam-share-page', WB_GAM_URL . 'assets/js/share-page.js', array(), WB_GAM_VERSION, true );
		get_header();
		self::render_share_body( $badge, $user, $linkedin_url, $cred_url, $issued_dt, $share_url );
		get_footer();
		exit;
	}

	/**
	 * Render the share page body HTML.
	 *
	 * @param array          $badge       Badge definition array.
	 * @param \WP_User       $user        Earner user object.
	 * @param string         $linkedin_url LinkedIn "Add Certification" URL, or empty.
	 * @param string         $cred_url    REST endpoint URL for the verifiable credential.
	 * @param \DateTime|null $issued_dt   Issue date/time (UTC), or null if unknown.
	 * @param string         $share_url   Canonical public URL of this share page.
	 */
	private static function render_share_body( array $badge, \WP_User $user, string $linkedin_url, string $cred_url, ?\DateTime $issued_dt, string $share_url ): void {
		$issued_label = $issued_dt
			? date_i18n( get_option( 'date_format' ), $issued_dt->getTimestamp() )
			: '';

		$site_name   = get_bloginfo( 'name' );
		$avatar_url  = get_avatar_url( $user->ID, array( 'size' => 96 ) );
		$profile_url = \WBGam\BuddyPress\UserUrl::resolve( (int) $user->ID );
		if ( '' === $profile_url ) {
			$profile_url = get_author_posts_url( $user->ID );
		}

		$share_text = sprintf(
			/* translators: 1: badge name, 2: site name */
			__( 'I earned the %1$s badge on %2$s!', 'wb-gamification' ),
			$badge['name'],
			$site_name
		);

		// Social share intents (no API keys; values encoded by add_query_arg).
		$twitter_share  = add_query_arg(
			array(
				'text' => $share_text,
				'url'  => $share_url,
			),
			'https://twitter.com/intent/tweet'
		);
		$facebook_share = add_query_arg( array( 'u' => $share_url ), 'https://www.facebook.com/sharer/sharer.php' );
		$linkedin_share = add_query_arg( array( 'url' => $share_url ), 'https://www.linkedin.com/sharing/share-offsite/' );
		?>
		<div class="wb-gam-share-page">
			<article class="wb-gam-share-card">
				<div class="wb-gam-share-card__badge">
					<?php if ( $badge['image_url'] ) : ?>
						<img alt="<?php echo esc_attr( $badge['name'] ); ?>"
							src="<?php echo esc_url( $badge['image_url'] ); ?>"
							width="96" height="96"
							class="wb-gam-share-card__badge-img" />
					<?php else : ?>
						<svg class="wb-gam-share-card__badge-fallback" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 2l2.9 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l7.1-1.01L12 2z" />
						</svg>
					<?php endif; ?>
				</div>

				<h1 class="wb-gam-share-card__title"><?php echo esc_html( $badge['name'] ); ?></h1>
				<?php if ( $badge['description'] ) : ?>
					<p class="wb-gam-share-card__desc"><?php echo esc_html( $badge['description'] ); ?></p>
				<?php endif; ?>

				<div class="wb-gam-share-card__earner">
					<img class="wb-gam-share-card__avatar" src="<?php echo esc_url( $avatar_url ); ?>"
						width="32" height="32" alt="" loading="lazy" />
					<span class="wb-gam-share-card__earner-text">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- all arguments are pre-escaped.
						printf(
							/* translators: 1: display name, 2: date */
							esc_html__( 'Earned by %1$s%2$s', 'wb-gamification' ),
							'<strong>' . esc_html( $user->display_name ) . '</strong>',
							$issued_label ? ( ' · ' . esc_html( $issued_label ) ) : ''
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</span>
				</div>

				<div class="wb-gam-share-card__actions">
					<button type="button" class="wb-gam-share-card__action wb-gam-share-card__action--copy"
						data-wb-gam-copy="<?php echo esc_attr( $share_url ); ?>"
						data-copied-label="<?php esc_attr_e( 'Copied!', 'wb-gamification' ); ?>"
						data-copy-fail-label="<?php esc_attr_e( 'Press Ctrl/Cmd+C to copy', 'wb-gamification' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span class="wb-gam-share-card__action-label"><?php esc_html_e( 'Copy link', 'wb-gamification' ); ?></span>
					</button>
					<a class="wb-gam-share-card__action wb-gam-share-card__action--icon"
						href="<?php echo esc_url( $twitter_share ); ?>" target="_blank" rel="noopener noreferrer"
						aria-label="<?php esc_attr_e( 'Share on X', 'wb-gamification' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
					</a>
					<a class="wb-gam-share-card__action wb-gam-share-card__action--icon"
						href="<?php echo esc_url( $facebook_share ); ?>" target="_blank" rel="noopener noreferrer"
						aria-label="<?php esc_attr_e( 'Share on Facebook', 'wb-gamification' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11.01 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8v8.44C19.61 23.08 24 18.09 24 12.07z"/></svg>
					</a>
					<a class="wb-gam-share-card__action wb-gam-share-card__action--icon"
						href="<?php echo esc_url( $linkedin_share ); ?>" target="_blank" rel="noopener noreferrer"
						aria-label="<?php esc_attr_e( 'Share on LinkedIn', 'wb-gamification' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.13 1.45-2.13 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.45v6.29zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.22.79 24 1.77 24h20.45c.98 0 1.78-.78 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/></svg>
					</a>
				</div>
				<p class="wb-gam-share-card__copy-status" role="status" aria-live="polite"></p>

				<?php if ( $linkedin_url || $badge['is_credential'] ) : ?>
					<div class="wb-gam-share-card__credential">
						<?php if ( $linkedin_url ) : ?>
							<a href="<?php echo esc_url( $linkedin_url ); ?>" rel="noopener noreferrer" target="_blank"
								class="wb-gam-share-card__cred-btn">
								<?php esc_html_e( 'Add to LinkedIn profile', 'wb-gamification' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $badge['is_credential'] ) : ?>
							<a href="<?php echo esc_url( $cred_url ); ?>" rel="noopener noreferrer" target="_blank"
								class="wb-gam-share-card__cred-link">
								<?php esc_html_e( 'View verifiable credential', 'wb-gamification' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="wb-gam-share-card__cta">
					<a class="wb-gam-share-card__cta-primary" href="<?php echo esc_url( $profile_url ); ?>">
						<?php
						printf(
							/* translators: %s: member display name. */
							esc_html__( "View %s's achievements", 'wb-gamification' ),
							esc_html( $user->display_name )
						);
						?>
					</a>
					<a class="wb-gam-share-card__cta-secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php
						printf(
							/* translators: %s: site name. */
							esc_html__( 'Explore %s', 'wb-gamification' ),
							esc_html( $site_name )
						);
						?>
					</a>
				</div>
			</article>
		</div>
		<?php
	}

	// ── URL builders ──────────────────────────────────────────────────────────

	/**
	 * Return the front-end share page URL for a badge + user.
	 *
	 * @param string $badge_id Badge identifier.
	 * @param int    $user_id  Earner user ID.
	 * @return string
	 */
	public static function get_share_url( string $badge_id, int $user_id ): string {
		return home_url( 'gamification/badge/' . $badge_id . '/' . $user_id . '/share/' );
	}

	/**
	 * Build a LinkedIn "Add Certification" deep-link URL.
	 *
	 * @param string $badge_name  Display name of the badge/credential.
	 * @param string $org_name    Issuing organisation name.
	 * @param int    $issue_year  Year credential was issued.
	 * @param int    $issue_month Month credential was issued (1–12).
	 * @param string $cred_url    Publicly accessible credential verification URL.
	 * @param string $cert_id     Unique cert identifier (e.g. "champion_42").
	 * @return string
	 */
	public static function build_linkedin_url(
		string $badge_name,
		string $org_name,
		int $issue_year,
		int $issue_month,
		string $cred_url,
		string $cert_id
	): string {
		return add_query_arg(
			array(
				'startTask'        => 'CERTIFICATION_NAME',
				'name'             => $badge_name,
				'organizationName' => $org_name,
				'issueYear'        => $issue_year,
				'issueMonth'       => $issue_month,
				'certUrl'          => $cred_url,
				'certId'           => $cert_id,
			),
			'https://www.linkedin.com/profile/add'
		);
	}
}
