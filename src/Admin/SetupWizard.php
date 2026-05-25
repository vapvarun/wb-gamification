<?php
/**
 * Setup Wizard — first-impression onboarding flow.
 *
 * Guides the site owner through choosing a starter template so points are
 * pre-configured for their use-case before they ever visit the main settings
 * screen. Idempotent: re-runnable any time via the URL or via the admin
 * notice that appears on plugin pages until completion.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Sets up, renders, and processes the WB Gamification onboarding wizard.
 *
 * @package WB_Gamification
 */
final class SetupWizard {

	/**
	 * URL slug for the wizard page.
	 */
	public const PAGE_SLUG = 'wb-gamification-setup';

	/**
	 * Option flagging that the wizard has been completed at least once.
	 * Suppresses the auto-redirect on subsequent activations and the
	 * "welcome" admin notice on plugin admin pages.
	 */
	public const COMPLETED_OPTION = 'wb_gam_wizard_complete';

	/**
	 * Option set by the activation hook to request a one-time auto-redirect
	 * to the wizard on the next admin page load. Persists indefinitely
	 * until {@see maybe_redirect()} consumes it — survives any
	 * activation-to-admin gap (WP-CLI flows, slow workflows, multisite
	 * cascades).
	 */
	public const PENDING_REDIRECT_OPTION = 'wb_gam_pending_setup_redirect';

	/**
	 * Nonce action name for the wizard form.
	 */
	private const NONCE_ACTION = 'wb_gam_setup_nonce';

	/**
	 * Register hooks. Idempotent — every load registers the same set; gating
	 * happens inside each handler so admins can re-run the wizard at any
	 * time via the URL.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		// Auto-redirect on admin_init:1 — earlier than the default 10 so any
		// later admin_init callback that emits output doesn't block our redirect.
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_welcome_notice' ) );
	}

	/**
	 * Register the wizard URL as a hidden submenu page.
	 *
	 * Always registered so admins can re-run the wizard after completion
	 * (`?page=wb-gamification-setup`). Hidden from the menu (parent = null)
	 * to avoid clutter.
	 */
	public static function register_page(): void {
		add_submenu_page(
			null, // No parent — page accessible only by URL.
			__( 'Gamification Setup', 'wb-gamification' ),
			'',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Auto-redirect to the wizard after activation.
	 *
	 * Driven by {@see PENDING_REDIRECT_OPTION}, set in the activation hook
	 * (only when the wizard hasn't been completed yet). Consumes the flag on
	 * the first admin page load and redirects, unless the admin is already
	 * on the wizard or in the multisite-activate context.
	 */
	public static function maybe_redirect(): void {
		// Skip in contexts where the redirect would be harmful or
		// non-meaningful regardless of the trigger that brought us here.
		if ( is_network_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- activate-multi is a WP core GET flag, not user input we trust.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page-slug compare, no state mutation.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG === $current_page ) {
			return;
		}

		// Two trigger paths into the wizard:
		//
		//  1. Activation hook explicitly set the pending flag. This is the
		//     authoritative signal — fires on every fresh activation, no
		//     matter where the admin then navigates next.
		//
		//  2. Wizard has never been completed AND the admin has just landed
		//     on the plugin's primary Dashboard page. Catches WP-CLI / hosting
		//     panel activations that bypass the activation hook redirect, and
		//     test reactivations on dev sandboxes where the pending flag was
		//     consumed by an earlier visit. A per-user_meta sticky guard
		//     prevents the redirect from looping if the admin dismisses the
		//     wizard and walks back to the Dashboard.
		$has_pending = (bool) get_option( self::PENDING_REDIRECT_OPTION );
		if ( $has_pending ) {
			delete_option( self::PENDING_REDIRECT_OPTION );
		}

		$first_visit_redirect = false;
		if ( ! $has_pending && ! get_option( self::COMPLETED_OPTION ) && 'wb-gamification' === $current_page ) {
			$user_id = get_current_user_id();
			if ( $user_id > 0 && ! get_user_meta( $user_id, 'wb_gam_setup_seen', true ) ) {
				update_user_meta( $user_id, 'wb_gam_setup_seen', '1' );
				$first_visit_redirect = true;
			}
		}

		if ( ! $has_pending && ! $first_visit_redirect ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Show a "welcome — run setup" notice on plugin admin pages until done.
	 *
	 * Fallback for installs where the activation auto-redirect was suppressed
	 * (must-use plugin redirects, hosting filters, or activation via a route
	 * that didn't pass through admin_init). Notice is scoped to plugin pages
	 * only — never spams the global dashboard.
	 */
	public static function maybe_show_welcome_notice(): void {
		if ( get_option( self::COMPLETED_OPTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return;
		}
		// Scope to plugin's own admin pages (any wb-gamification-* screen id).
		if ( false === strpos( (string) $screen->id, 'wb-gamification' ) ) {
			return;
		}
		// Don't double-up: the wizard page itself is the welcome experience.
		if ( false !== strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$wizard_url = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		// Class `wb-gam-notice` whitelists this notice past the body-scoped CSS
		// filter declared in assets/css/admin.css (Welcome notice section).
		// Class `wb-gam-notice__cta` styles the CTA button spacing — see
		// the same CSS file. No inline style attributes (coding-rule 3).
		printf(
			'<div class="notice notice-info wb-gam-notice"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary wb-gam-notice__cta">%4$s</a></p></div>',
			esc_html__( 'Welcome to WB Gamification!', 'wb-gamification' ),
			esc_html__( 'Pick a starter template to pre-configure points for your use case — takes 30 seconds.', 'wb-gamification' ),
			$wizard_url, // Already escaped via esc_url.
			esc_html__( 'Run the setup wizard', 'wb-gamification' )
		);
	}

	/**
	 * Process the template selection form submission.
	 */
	public static function handle_submission(): void {
		if ( ! isset( $_POST['wb_gam_template'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to complete the setup wizard.', 'wb-gamification' ) );
		}

		$template = sanitize_key( wp_unslash( $_POST['wb_gam_template'] ) );

		/**
		 * Fires when an admin submits the setup wizard, before any state
		 * is persisted. Extensions can short-circuit defaults or capture
		 * the choice for analytics.
		 *
		 * @param string $template Template slug, or 'skip'.
		 */
		do_action( 'wb_gamification_setup_wizard_started', $template );

		// Skip path: don't write any template or toggle defaults — site owner
		// will configure manually. Just mark the wizard complete.
		if ( 'skip' !== $template ) {
			self::apply_template( $template );
			self::apply_defaults_from_form();
		}

		update_option( self::COMPLETED_OPTION, true );

		/**
		 * Fires after the wizard's state has been persisted.
		 *
		 * @param string $template Template slug, or 'skip'.
		 */
		do_action( 'wb_gamification_setup_wizard_completed', $template );

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification&setup=complete' ) );
		exit;
	}

	/**
	 * Persist the wizard's notification + privacy toggles.
	 *
	 * Only called when a real template was chosen — the Skip path leaves all
	 * toggle defaults at their engine ship-defaults so an admin who said
	 * "configure manually" doesn't get unexpected option writes.
	 *
	 * Each option is whitelisted — unknown POST keys never reach update_option().
	 */
	private static function apply_defaults_from_form(): void {
		$toggles = array(
			'wb_gam_email_level_up'            => 'email_level_up',
			'wb_gam_email_badge_earned'        => 'email_badge_earned',
			'wb_gam_email_challenge_completed' => 'email_challenge_completed',
			'wb_gam_profile_public_enabled'    => 'profile_public',
		);

		foreach ( $toggles as $option_key => $form_key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in caller (handle_submission).
			$value = isset( $_POST[ $form_key ] ) ? '1' : '0';
			update_option( $option_key, $value );
		}
	}

	/**
	 * Persist the chosen template's point values and leaderboard mode.
	 *
	 * @param string $template Template key.
	 */
	private static function apply_template( string $template ): void {
		$configs = self::get_template_configs();
		if ( ! isset( $configs[ $template ] ) ) {
			return;
		}
		foreach ( $configs[ $template ]['points'] as $action_id => $points ) {
			update_option( 'wb_gam_points_' . $action_id, (int) $points );
		}
		update_option( 'wb_gam_template', $template );
		update_option( 'wb_gam_leaderboard_mode', $configs[ $template ]['leaderboard'] );
	}

	/**
	 * Return all available starter template definitions.
	 *
	 * @return array<string, array{label: string, description: string, leaderboard: string, points: array<string, int>}>
	 */
	private static function get_template_configs(): array {
		return array(
			'blog'      => array(
				'label'       => __( 'Blog / Publisher', 'wb-gamification' ),
				'description' => __( 'Rewards writing and meaningful comments. For standalone WordPress blogs.', 'wb-gamification' ),
				'leaderboard' => 'monthly',
				'points'      => array(
					'wp_publish_post'          => 25,
					'wp_first_post'            => 20,
					'wp_leave_comment'         => 5,
					'wp_post_receives_comment' => 3,
				),
			),
			'community' => array(
				'label'       => __( 'Community Engagement', 'wb-gamification' ),
				'description' => __( 'Balanced — rewards posting, reactions, and social connection. Requires BuddyPress.', 'wb-gamification' ),
				'leaderboard' => 'weekly',
				'points'      => array(
					'bp_activity_update'    => 10,
					'bp_activity_comment'   => 5,
					'friends_accepted'      => 8,
					'groups_join'           => 8,
					'bp_reactions_received' => 3,
					'bp_give_kudos'         => 2,
					'bp_receive_kudos'      => 5,
				),
			),
			'course'    => array(
				'label'       => __( 'Online Course', 'wb-gamification' ),
				'description' => __( 'Course completion heavy — progress and credential badges.', 'wb-gamification' ),
				'leaderboard' => 'cohort',
				'points'      => array(
					'lesson_complete' => 20,
					'course_complete' => 100,
					'quiz_pass'       => 30,
					'wp_first_post'   => 10,
				),
			),
			'coaching'  => array(
				'label'       => __( 'Coaching Platform', 'wb-gamification' ),
				'description' => __( 'Private leaderboard by default — progress vs personal baseline, not peer comparison.', 'wb-gamification' ),
				'leaderboard' => 'private',
				'points'      => array(
					'check_in'      => 15,
					'goal_complete' => 50,
					'wp_first_post' => 10,
				),
			),
			'nonprofit' => array(
				'label'       => __( 'Nonprofit / Mission', 'wb-gamification' ),
				'description' => __( 'Mission-aligned language. Team leaderboards only — impact over individual competition.', 'wb-gamification' ),
				'leaderboard' => 'team-only',
				'points'      => array(
					'volunteer_hours'    => 30,
					'bp_activity_update' => 5,
					'groups_join'        => 10,
				),
			),
		);
	}

	/**
	 * Render the wizard page HTML.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		$configs        = self::get_template_configs();
		$is_re_run      = (bool) get_option( self::COMPLETED_OPTION );
		$current_tpl    = (string) get_option( 'wb_gam_template', '' );
		?>
		<div class="wrap wb-gam-wizard-wrap">

			<div class="wb-gam-wizard-header">
				<h1><?php esc_html_e( 'Welcome to WB Gamification', 'wb-gamification' ); ?></h1>
				<p>
					<?php esc_html_e( 'Choose a starter template to pre-configure your point values. You can change everything later from the settings screen.', 'wb-gamification' ); ?>
				</p>
				<?php if ( $is_re_run && '' !== $current_tpl ) : ?>
					<p class="notice notice-info wb-gam-wizard-rerun-notice">
						<?php
						printf(
							/* translators: %s: name of the currently-applied template (e.g. "Community Engagement") */
							esc_html__( 'You\'ve already completed setup with the %s template. Picking a new one will overwrite the matching point values.', 'wb-gamification' ),
							'<strong>' . esc_html( $configs[ $current_tpl ]['label'] ?? $current_tpl ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<fieldset class="wb-gam-wizard-grid-fieldset">
					<legend class="screen-reader-text">
						<?php esc_html_e( 'Choose a starter template', 'wb-gamification' ); ?>
					</legend>

					<div class="wb-gam-wizard-grid" role="group" aria-label="<?php esc_attr_e( 'Starter template options', 'wb-gamification' ); ?>">
						<?php foreach ( $configs as $key => $config ) : ?>
							<div class="wb-gam-wizard-card<?php echo $key === $current_tpl ? ' wb-gam-wizard-card--current' : ''; ?>">
								<div>
									<h3 class="wb-gam-wizard-card__title">
										<?php echo esc_html( $config['label'] ); ?>
										<?php if ( $key === $current_tpl ) : ?>
											<span class="wb-gam-wizard-card__badge">
												<?php esc_html_e( 'Current', 'wb-gamification' ); ?>
											</span>
										<?php endif; ?>
									</h3>
									<p class="wb-gam-wizard-card__desc">
										<?php echo esc_html( $config['description'] ); ?>
									</p>
									<?php echo self::render_point_summary( $config['points'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes internally. ?>
								</div>
								<button
									type="submit"
									name="wb_gam_template"
									value="<?php echo esc_attr( $key ); ?>"
									class="button button-primary wb-gam-wizard-card__btn"
								>
									<?php
									echo $key === $current_tpl
										? esc_html__( 'Re-apply this template', 'wb-gamification' )
										: esc_html__( 'Use this template', 'wb-gamification' );
									?>
								</button>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="wb-gam-wizard-defaults">
					<legend class="wb-gam-wizard-defaults__title">
						<?php esc_html_e( 'Default notifications & privacy', 'wb-gamification' ); ?>
					</legend>
					<p class="description wb-gam-wizard-defaults__hint">
						<?php esc_html_e( 'Applied alongside the template you pick (skipped if you choose to configure manually). Change any of these later in Settings.', 'wb-gamification' ); ?>
					</p>

					<label class="wb-gam-wizard-toggle">
						<input type="checkbox" name="email_level_up" value="1">
						<span class="wb-gam-wizard-toggle__label">
							<strong><?php esc_html_e( 'Send level-up emails', 'wb-gamification' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Notify members when they reach a new level.', 'wb-gamification' ); ?></span>
						</span>
					</label>

					<label class="wb-gam-wizard-toggle">
						<input type="checkbox" name="email_badge_earned" value="1">
						<span class="wb-gam-wizard-toggle__label">
							<strong><?php esc_html_e( 'Send badge-earned emails', 'wb-gamification' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Notify members when they earn a badge.', 'wb-gamification' ); ?></span>
						</span>
					</label>

					<label class="wb-gam-wizard-toggle">
						<input type="checkbox" name="email_challenge_completed" value="1">
						<span class="wb-gam-wizard-toggle__label">
							<strong><?php esc_html_e( 'Send challenge-completed emails', 'wb-gamification' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Notify members when they finish a challenge.', 'wb-gamification' ); ?></span>
						</span>
					</label>

					<label class="wb-gam-wizard-toggle">
						<input type="checkbox" name="profile_public" value="1" checked>
						<span class="wb-gam-wizard-toggle__label">
							<strong><?php esc_html_e( 'Enable public profile pages', 'wb-gamification' ); ?></strong>
							<span class="description">
								<?php esc_html_e( 'Let members opt in to a sharable profile at /u/{username}. Each member must still flip the per-user privacy toggle.', 'wb-gamification' ); ?>
							</span>
						</span>
					</label>
				</fieldset>

				<div class="wb-gam-wizard-skip-row">
					<button
						type="submit"
						name="wb_gam_template"
						value="skip"
						class="button button-link wb-gam-wizard-skip-btn"
					>
						<?php esc_html_e( 'Skip & configure manually', 'wb-gamification' ); ?>
					</button>
					<p class="description wbgam-mt-sm">
						<?php esc_html_e( 'Skip leaves the engine\'s conservative defaults in place — every email off, public profiles off. Change anything later in Settings.', 'wb-gamification' ); ?>
					</p>
				</div>

			</form>
		</div>
		<?php
	}

	/**
	 * Build the "N pts — action" summary block for one template card.
	 *
	 * Each label is composed via sprintf with `(int)` and esc_html on the
	 * variable parts; the only HTML is the literal `<br>` separator and
	 * the `&mdash;` entity. wp_kses_post() wraps the final string for an
	 * extra safety net.
	 *
	 * @param array<string, int> $points Map of action_id → point value.
	 * @return string Safe HTML.
	 */
	private static function render_point_summary( array $points ): string {
		if ( empty( $points ) ) {
			return '';
		}
		$rows = array();
		foreach ( $points as $action_id => $pts ) {
			$rows[] = sprintf(
				'%d pts &mdash; %s',
				(int) $pts,
				esc_html( $action_id )
			);
		}
		return '<p class="wb-gam-wizard-card__points">' . wp_kses_post( implode( '<br>', $rows ) ) . '</p>';
	}
}
