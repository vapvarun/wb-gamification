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
					__( '%1$s — earned by %2$s', 'wb-gamification' ),
					$badge['name'],
					$user->display_name
				);
				return $parts;
			}
		);

		add_action(
			'wp_head',
			static function () use ( $badge, $user, $share_url ): void {
				$title = esc_attr(
					sprintf(
						/* translators: 1: badge name, 2: display name */
						__( '%1$s — earned by %2$s', 'wb-gamification' ),
						$badge['name'],
						$user->display_name
					)
				);
				$desc = esc_attr( $badge['description'] );
				$img  = esc_attr( $badge['image_url'] ?: '' );
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
				}
				echo "<meta name=\"twitter:card\" content=\"summary\" />\n";
			}
		);

		wp_enqueue_style( 'wb-gam-share-page', WB_GAM_URL . 'assets/css/share-page.css', array(), WB_GAM_VERSION );
		get_header();
		self::render_share_body( $badge, $user, $linkedin_url, $cred_url, $issued_dt );
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
	 */
	private static function render_share_body( array $badge, \WP_User $user, string $linkedin_url, string $cred_url, ?\DateTime $issued_dt ): void {
		$issued_label = $issued_dt
			? esc_html( date_i18n( get_option( 'date_format' ), $issued_dt->getTimestamp() ) )
			: '';
		?>
		<div class="wb-gam-share-page">
			<?php if ( $badge['image_url'] ) : ?>
				<img src="<?php echo esc_url( $badge['image_url'] ); ?>"
					alt="<?php echo esc_attr( $badge['name'] ); ?>"
					width="160" height="160"
					class="wb-gam-share-page__badge-img" />
			<?php endif; ?>

			<h1 class="wb-gam-share-page__title"><?php echo esc_html( $badge['name'] ); ?></h1>
			<p class="wb-gam-share-page__desc"><?php echo esc_html( $badge['description'] ); ?></p>

			<p class="wb-gam-share-page__earned-by">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- all arguments are pre-escaped with esc_html/esc_html__.
				printf(
					/* translators: 1: display name, 2: date */
					esc_html__( 'Earned by %1$s%2$s', 'wb-gamification' ),
					'<strong>' . esc_html( $user->display_name ) . '</strong>',
					$issued_label ? ( ' ' . esc_html__( 'on', 'wb-gamification' ) . ' ' . $issued_label ) : ''
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</p>

			<?php if ( $linkedin_url ) : ?>
				<a href="<?php echo esc_url( $linkedin_url ); ?>"
					rel="noopener noreferrer"
					target="_blank"
					class="wb-gam-share-page__linkedin-btn">
					<?php esc_html_e( 'Add to LinkedIn', 'wb-gamification' ); ?>
				</a>
				<br />
			<?php endif; ?>

			<?php if ( $badge['is_credential'] ) : ?>
				<a href="<?php echo esc_url( $cred_url ); ?>"
					rel="noopener noreferrer"
					target="_blank"
					class="wb-gam-share-page__credential-link">
					<?php esc_html_e( 'View verifiable credential (JSON-LD)', 'wb-gamification' ); ?>
				</a>
			<?php endif; ?>
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
