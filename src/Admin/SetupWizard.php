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

use WBGam\Engine\Log;
use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

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
	 * Template flagged with the "Recommended" pill for new admins. Chosen
	 * because it works on any install (no plugin gating) and seeds the most
	 * popular point shape from the template library.
	 */
	private const RECOMMENDED_TEMPLATE = 'community';

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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_page_css' ) );
	}

	/**
	 * Enqueue the per-page wizard.css bundle on the Setup Wizard page only.
	 *
	 * The global tokens / components / utilities / suppression sheets are
	 * enqueued by `WB_Gamification::enqueue_admin_assets`; this method adds
	 * the page-specific overrides on top.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_page_css( string $hook_suffix ): void {
		// The wizard registers as a hidden submenu with parent = null, which
		// gives it the `admin_page_wb-gamification-setup` hook suffix.
		if ( 'admin_page_wb-gamification-setup' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wb-gam-page-wizard',
			plugins_url( 'assets/css/admin/pages/wizard.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
	}

	/**
	 * Register the wizard URL as a hidden submenu page.
	 *
	 * Always registered so admins can re-run the wizard after completion
	 * (`?page=wb-gamification-setup`). Hidden from the menu (empty parent)
	 * to avoid clutter. Because the page never lands in the `$submenu`
	 * lookup, `get_admin_page_title()` would return null and
	 * admin-header.php would emit a `strip_tags(): Passing null`
	 * deprecation on every visit — so the `load-{hook}` callback pre-sets
	 * the `$title` global, which `get_admin_page_title()` short-circuits
	 * on. (Registering under the real parent and calling
	 * `remove_submenu_page()` is NOT an option: WP then 403s the page via
	 * `user_can_access_admin_page()`.)
	 */
	public static function register_page(): void {
		$hook = add_submenu_page(
			'', // No parent — page accessible only by URL (hidden submenu).
			__( 'Gamification Setup', 'wb-gamification' ),
			'',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function (): void {
					global $title;
					if ( empty( $title ) ) {
						// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Pre-seeding the admin page title for a hidden submenu; get_admin_page_title() returns this verbatim.
						$title = __( 'Gamification Setup', 'wb-gamification' );
					}
				}
			);
		}
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
		// 1. Activation hook explicitly set the pending flag. This is the
		// authoritative signal — fires on every fresh activation, no
		// matter where the admin then navigates next.
		//
		// 2. Wizard has never been completed AND the admin has just landed
		// on the plugin's primary Dashboard page. Catches WP-CLI / hosting
		// panel activations that bypass the activation hook redirect, and
		// test reactivations on dev sandboxes where the pending flag was
		// consumed by an earlier visit. A per-user_meta sticky guard
		// prevents the redirect from looping if the admin dismisses the
		// wizard and walks back to the Dashboard.
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

		$wizard_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		// Class `wb-gam-notice` whitelists this notice past the body-scoped CSS
		// filter declared in assets/css/admin.css (Welcome notice section).
		// Class `wb-gam-notice__cta` styles the CTA button spacing — see
		// the same CSS file. No inline style attributes (coding-rule 3).
		printf(
			'<div class="notice notice-info wb-gam-notice is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary wb-gam-notice__cta">%4$s</a></p></div>',
			esc_html__( 'Welcome to WB Gamification!', 'wb-gamification' ),
			esc_html__( 'Pick a starter template to pre-configure points for your use case - takes 30 seconds.', 'wb-gamification' ),
			esc_url( $wizard_url ),
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

		// Belt-and-braces (Basecamp 9925226356): block server-side if the
		// requested template's required integration is missing. The UI
		// already disables the submit button, but a curious admin who
		// inspects the form and re-enables it would otherwise apply a
		// template seeded for an action the site can't even fire.
		if ( 'skip' !== $template ) {
			$configs = self::get_template_configs();
			$cfg     = $configs[ $template ] ?? null;
			if ( $cfg && ! empty( $cfg['requires']['callback'] ) && is_callable( $cfg['requires']['callback'] ) ) {
				if ( ! (bool) call_user_func( $cfg['requires']['callback'] ) ) {
					$plugin = (string) ( $cfg['requires']['plugin'] ?? __( 'a required plugin', 'wb-gamification' ) );
					wp_die(
						/* translators: %s: required plugin name */
						esc_html( sprintf( __( 'This starter template needs %s active before it can be applied. Install / activate it from the Plugins screen and re-run the wizard.', 'wb-gamification' ), $plugin ) ),
						esc_html__( 'Template unavailable', 'wb-gamification' ),
						array(
							'response'  => 400,
							'back_link' => true,
						)
					);
				}
			}
		}

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
		// Canonical storage shape for every `wb_gam_email_*` option is the
		// PHP-bool-coerced string `'1'` (on) or `'0'` (off). Read sites
		// must use `(bool) get_option(..., false)` — never a strict
		// `=== '1'` compare — because hosts with object-cache layers in
		// the way can return a real bool. `EmailSettingsController::handle_get`
		// and `TransactionalEmailEngine::is_enabled` both follow this
		// contract. Audit/DATA-FLOW-ADMIN-REST-2026-05-27.md §G8.
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
			// Never persist a point value for an action that does not exist.
			//
			// A `wb_gam_points_{id}` option for an unregistered id is DEAD CONFIG:
			// it shows nowhere, fires never, and gives the owner a wizard that
			// silently did nothing. That is precisely how `check_in`,
			// `goal_complete` and `volunteer_hours` shipped in the Coaching and
			// Nonprofit templates — no code ever checked, so nothing ever
			// complained.
			//
			// Registry::get_action() returns null for an unknown id, so this is the
			// single choke point where a bad template id can be caught at runtime.
			// SetupWizardTemplatesTest catches it at build time.
			if ( null === \WBGam\Engine\Registry::get_action( (string) $action_id ) ) {
				Log::warning(
					'setup-wizard: template references an unregistered action; skipping',
					array(
						'template'  => $template,
						'action_id' => (string) $action_id,
					)
				);
				continue;
			}
			update_option( 'wb_gam_points_' . $action_id, (int) $points );
		}
		update_option( 'wb_gam_template', $template );

		// Note: the per-template 'leaderboard' preference is NOT persisted as an
		// option. Nothing reads wb_gam_leaderboard_mode (the leaderboard block /
		// shortcode / hub resolve their period + scope from their own
		// attributes), so writing it was dead data flagged by the contract
		// audit. The value still lives in the template config above, so if a
		// global "default leaderboard view" is wired up later it can read the
		// chosen template's 'leaderboard' directly.
	}

	/**
	 * Return all available starter template definitions.
	 *
	 * @return array<string, array{label: string, description: string, leaderboard: string, requires?: array{callback: callable, plugin: string}, points: array<string, int>}>
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
				'description' => __( 'Balanced - rewards posting, reactions, and social connection. Works with BuddyNext.', 'wb-gamification' ),
				'leaderboard' => 'weekly',
				'requires'    => array(
					'callback' => static function (): bool {
						return defined( 'BUDDYNEXT_VERSION' ) || class_exists( '\\BuddyNext\\Plugin' ); },
					'plugin'   => __( 'BuddyNext', 'wb-gamification' ),
				),
				'points'      => array(
					'bn_post_created'      => 10,
					'bn_comment_created'   => 5,
					'bn_connected'         => 8,
					'bn_space_joined'      => 8,
					'bn_reaction_received' => 3,
					'bn_profile_completed' => 25,
				),
			),
			'course'    => array(
				'label'       => __( 'Online Course', 'wb-gamification' ),
				'description' => __( 'Course completion heavy - progress and credential badges. Works with Learnomy.', 'wb-gamification' ),
				'leaderboard' => 'cohort',
				'requires'    => array(
					'callback' => static function (): bool {
						return defined( 'LEARNOMY_VERSION' ) || class_exists( '\\Learnomy\\Plugin' ); },
					'plugin'   => __( 'Learnomy', 'wb-gamification' ),
				),
				'points'      => array(
					'learnomy_lesson_completed'   => 20,
					'learnomy_course_completed'   => 100,
					'learnomy_certificate_issued' => 50,
					'learnomy_student_enrolled'   => 5,
				),
			),

			/*
			 * Every action_id below MUST exist in a manifest under integrations/.
			 * Enforced by SetupWizardTemplatesTest and, at runtime, by
			 * handle_submission() which drops unregistered ids.
			 *
			 * Until 1.6.4 this template seeded `check_in` (15) and `goal_complete`
			 * (50) — action ids that exist in NO manifest and can therefore never
			 * fire. An owner who picked "Coaching Platform" in the wizard got a
			 * config where 2 of its 3 actions were dead on arrival: a broken first
			 * run, on the one screen whose entire job is the first impression.
			 * Nothing validated the ids, so it failed silently.
			 */
			'coaching'  => array(
				'label'       => __( 'Coaching Platform', 'wb-gamification' ),
				'description' => __( 'Private leaderboard by default - progress vs personal baseline, not peer comparison.', 'wb-gamification' ),
				'leaderboard' => 'private',
				'points'      => array(
					// Standalone template — no `requires`, so it may only use
					// WordPress-core actions, which are the only ones guaranteed
					// to be registered on any site.
					'wp_publish_post'     => 25,
					'wp_leave_comment'    => 10,
					'wp_profile_complete' => 15,
					'wp_first_post'       => 20,
				),
			),
			'nonprofit' => array(
				'label'       => __( 'Nonprofit / Mission', 'wb-gamification' ),
				'description' => __( 'Mission-aligned language. Team leaderboards only - impact over individual competition. Works with BuddyNext.', 'wb-gamification' ),
				'leaderboard' => 'team-only',
				'requires'    => array(
					'callback' => static function (): bool {
						return defined( 'BUDDYNEXT_VERSION' ) || class_exists( '\\BuddyNext\\Plugin' ); },
					'plugin'   => __( 'BuddyNext', 'wb-gamification' ),
				),
				'points'      => array(
					// `volunteer_hours` (30) was seeded here and exists in no
					// manifest — same dead-config bug as Coaching above.
					'bn_post_created'    => 5,
					'bn_space_joined'    => 10,
					'bn_connected'       => 8,
					'bn_comment_created' => 5,
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

		$configs     = self::get_template_configs();
		$icons       = self::get_template_icons();
		$is_re_run   = (bool) get_option( self::COMPLETED_OPTION );
		$current_tpl = (string) get_option( 'wb_gam_template', '' );
		?>
		<div class="wrap wb-gam-wizard-wrap">

			<div class="wb-gam-wizard-hero">
				<div class="wb-gam-wizard-hero__step">
					<span class="wb-gam-wizard-hero__step-num">1</span>
					<?php esc_html_e( 'Pick a starting point', 'wb-gamification' ); ?>
				</div>
				<h1 class="wb-gam-wizard-hero__title">
					<?php
					echo $is_re_run
						? esc_html__( 'Update your gamification template', 'wb-gamification' )
						: esc_html__( 'Welcome to WB Gamification', 'wb-gamification' );
					?>
				</h1>
				<p class="wb-gam-wizard-hero__lede">
					<?php esc_html_e( 'Each template seeds sensible point values for a different use case. Every value is editable later from Settings.', 'wb-gamification' ); ?>
				</p>

				<?php
				// ORIENTATION, before configuration.
				//
				// The wizard used to open on "Pick a starting point" and go straight to a grid of
				// templates. It never said what the plugin DOES, what a member would SEE, or where
				// any of it would show up -- so the owner's first interaction with the product was
				// a configuration choice about a thing nobody had described to them yet.
				//
				// This is concrete rather than aspirational: the Hub page is created at activation,
				// so we can link the owner straight to the thing their members will look at.
				$wb_gam_hub_page = (int) get_option( 'wb_gam_hub_page_id', 0 );
				$wb_gam_hub_link = $wb_gam_hub_page ? get_permalink( $wb_gam_hub_page ) : '';
				?>
				<div class="wb-gam-wizard-orient">
					<h2 class="wb-gam-wizard-orient__title">
						<?php esc_html_e( 'What your members get', 'wb-gamification' ); ?>
					</h2>
					<ul class="wb-gam-wizard-orient__list">
						<li>
							<strong><?php esc_html_e( 'Points', 'wb-gamification' ); ?></strong>
							<?php esc_html_e( 'for things they already do - posting, commenting, connecting.', 'wb-gamification' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Badges and levels', 'wb-gamification' ); ?></strong>
							<?php esc_html_e( 'they earn as they go, and can show on their profile.', 'wb-gamification' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'A leaderboard', 'wb-gamification' ); ?></strong>
							<?php esc_html_e( 'if your community is the competitive kind. It is optional.', 'wb-gamification' ); ?>
						</li>
					</ul>
					<?php if ( $wb_gam_hub_link ) : ?>
						<p class="wb-gam-wizard-orient__where">
							<?php
							printf(
								wp_kses(
									/* translators: %s: link to the member-facing Gamification Hub page. */
									__( 'Members see all of it on their <a href="%s" target="_blank" rel="noopener">Gamification Hub</a> - a page already created for you. Picking a template below just decides what earns what.', 'wb-gamification' ),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								),
								esc_url( $wb_gam_hub_link )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<?php if ( $is_re_run && '' !== $current_tpl ) : ?>
					<p class="wb-gam-wizard-rerun-notice">
						<?php
						printf(
							/* translators: %s: name of the currently-applied template (e.g. "Community Engagement") */
							esc_html__( 'You\'re already running the %s template. Picking a new one will overwrite the matching point values; everything else stays put.', 'wb-gamification' ),
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
						<?php
						foreach ( $configs as $key => $config ) :
							// Integration-gating (Basecamp 9925226356). When a
							// template's required plugin isn't active we don't
							// hide the option — admin still benefits from
							// seeing the full menu — but we disable the
							// submit button and surface an install hint so a
							// non-technical admin understands why.
							$requires_meta = $config['requires'] ?? null;
							$requires_ok   = true;
							if ( is_array( $requires_meta ) && isset( $requires_meta['callback'] ) && is_callable( $requires_meta['callback'] ) ) {
								$requires_ok = (bool) call_user_func( $requires_meta['callback'] );
							}
							$is_recommended = ( ! $is_re_run && self::RECOMMENDED_TEMPLATE === $key && $requires_ok );
							$card_classes   = array( 'wb-gam-wizard-card' );
							if ( $key === $current_tpl ) {
								$card_classes[] = 'wb-gam-wizard-card--current';
							}
							if ( ! $requires_ok ) {
								$card_classes[] = 'wb-gam-wizard-card--unavailable';
							}
							if ( $is_recommended ) {
								$card_classes[] = 'wb-gam-wizard-card--recommended';
							}
							?>
							<div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
								<div class="wb-gam-wizard-card__body">
									<div class="wb-gam-wizard-card__head">
										<div class="wb-gam-wizard-card__icon" aria-hidden="true">
											<?php echo isset( $icons[ $key ] ) ? wp_kses( $icons[ $key ], self::svg_kses_rules() ) : ''; ?>
										</div>
										<div class="wb-gam-wizard-card__head-text">
											<h3 class="wb-gam-wizard-card__title">
												<?php echo esc_html( $config['label'] ); ?>
											</h3>
											<div class="wb-gam-wizard-card__badges">
												<?php if ( $is_recommended ) : ?>
													<span class="wb-gam-wizard-card__badge wb-gam-wizard-card__badge--recommended">
														<?php esc_html_e( 'Recommended', 'wb-gamification' ); ?>
													</span>
												<?php endif; ?>
												<?php if ( $key === $current_tpl ) : ?>
													<span class="wb-gam-wizard-card__badge wb-gam-wizard-card__badge--current">
														<?php esc_html_e( 'Current', 'wb-gamification' ); ?>
													</span>
												<?php endif; ?>
												<?php if ( ! $requires_ok && is_array( $requires_meta ) && ! empty( $requires_meta['plugin'] ) ) : ?>
													<span class="wb-gam-wizard-card__badge wb-gam-wizard-card__badge--locked">
														<?php
														/* translators: %s: required plugin name */
														printf( esc_html__( 'Requires %s', 'wb-gamification' ), esc_html( (string) $requires_meta['plugin'] ) );
														?>
													</span>
												<?php endif; ?>
											</div>
										</div>
									</div>
									<p class="wb-gam-wizard-card__desc">
										<?php echo esc_html( $config['description'] ); ?>
									</p>
									<?php echo self::render_point_summary( $config['points'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes internally. ?>
									<?php if ( ! $requires_ok && is_array( $requires_meta ) ) : ?>
										<p class="wb-gam-wizard-card__unavailable-note">
											<?php
											/* translators: %s: required plugin name */
											printf( esc_html__( 'Install and activate %s to use this starter. The point values it would seed depend on that plugin\'s events.', 'wb-gamification' ), '<strong>' . esc_html( (string) $requires_meta['plugin'] ) . '</strong>' );
											?>
										</p>
									<?php endif; ?>
								</div>
								<button
									type="submit"
									name="wb_gam_template"
									value="<?php echo esc_attr( $key ); ?>"
									class="button button-primary wb-gam-wizard-card__btn"
									<?php disabled( ! $requires_ok ); ?>
									<?php
									if ( ! $requires_ok ) :
										?>
										aria-disabled="true"<?php endif; ?>
								>
									<?php
									if ( ! $requires_ok ) {
										esc_html_e( 'Unavailable', 'wb-gamification' );
									} elseif ( $key === $current_tpl ) {
										esc_html_e( 'Re-apply this template', 'wb-gamification' );
									} else {
										esc_html_e( 'Use this template', 'wb-gamification' );
									}
									?>
								</button>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<fieldset class="wb-gam-wizard-defaults">
					<legend class="wb-gam-wizard-defaults__title">
						<span class="wb-gam-wizard-defaults__step">2</span>
						<?php esc_html_e( 'Tune defaults', 'wb-gamification' ); ?>
					</legend>
					<p class="wb-gam-wizard-defaults__hint">
						<?php esc_html_e( 'Applied alongside the template you pick (skipped if you choose to configure manually). Every option is reversible from Settings.', 'wb-gamification' ); ?>
					</p>

					<div class="wb-gam-wizard-defaults__grid">
						<label class="wb-gam-wizard-toggle">
							<input type="checkbox" name="email_level_up" value="1">
							<span class="wb-gam-wizard-toggle__label">
								<strong><?php esc_html_e( 'Level-up emails', 'wb-gamification' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Notify members when they reach a new level.', 'wb-gamification' ); ?></span>
							</span>
						</label>

						<label class="wb-gam-wizard-toggle">
							<input type="checkbox" name="email_badge_earned" value="1">
							<span class="wb-gam-wizard-toggle__label">
								<strong><?php esc_html_e( 'Badge-earned emails', 'wb-gamification' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Notify members when they earn a badge.', 'wb-gamification' ); ?></span>
							</span>
						</label>

						<label class="wb-gam-wizard-toggle">
							<input type="checkbox" name="email_challenge_completed" value="1">
							<span class="wb-gam-wizard-toggle__label">
								<strong><?php esc_html_e( 'Challenge-completed emails', 'wb-gamification' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Notify members when they finish a challenge.', 'wb-gamification' ); ?></span>
							</span>
						</label>

						<label class="wb-gam-wizard-toggle">
							<input type="checkbox" name="profile_public" value="1" checked>
							<span class="wb-gam-wizard-toggle__label">
								<strong><?php esc_html_e( 'Public profile pages', 'wb-gamification' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Let members opt in to a sharable profile at /u/{username}. Each member still flips their own privacy toggle.', 'wb-gamification' ); ?></span>
							</span>
						</label>
					</div>
				</fieldset>

				<footer class="wb-gam-wizard-footer">
					<div class="wb-gam-wizard-footer__copy">
						<strong><?php esc_html_e( 'Prefer to start from scratch?', 'wb-gamification' ); ?></strong>
						<span><?php esc_html_e( 'Skip leaves engine defaults in place - every email off, public profiles off. Configure everything yourself in Settings.', 'wb-gamification' ); ?></span>
					</div>
					<button
						type="submit"
						name="wb_gam_template"
						value="skip"
						class="button button-secondary wb-gam-wizard-skip-btn"
					>
						<?php esc_html_e( 'Skip & configure manually', 'wb-gamification' ); ?>
					</button>
				</footer>

			</form>
		</div>
		<?php
	}

	/**
	 * Build the bulleted "N pts — Human action" summary for one template card.
	 *
	 * Resolves each action_id through {@see Registry::label_for()} so the
	 * card surfaces a friendly verb ("Publish a blog post") instead of the
	 * raw manifest id ("wp_publish_post") — closes the dev-jargon UX gap
	 * reported alongside Basecamp 9925205802.
	 *
	 * @param array<string, int> $points Map of action_id → point value.
	 * @return string Safe HTML.
	 */
	private static function render_point_summary( array $points ): string {
		if ( empty( $points ) ) {
			return '';
		}
		$items = array();
		foreach ( $points as $action_id => $pts ) {
			$label   = class_exists( Registry::class ) ? Registry::label_for( (string) $action_id ) : (string) $action_id;
			$items[] = sprintf(
				'<li><span class="wb-gam-wizard-card__points-pts">%1$d pts</span> <span class="wb-gam-wizard-card__points-label">%2$s</span></li>',
				(int) $pts,
				esc_html( $label )
			);
		}
		return '<ul class="wb-gam-wizard-card__points">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Inline SVG icon per template, drawn from the Lucide icon set we ship
	 * across the admin. Returned as raw markup so the calling template can
	 * route through {@see svg_kses_rules()} for output sanitization.
	 *
	 * @return array<string, string> Map of template key → inline SVG markup.
	 */
	private static function get_template_icons(): array {
		$attrs = 'width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"';
		return array(
			'blog'      => '<svg ' . $attrs . '><path d="M4 19.5v-15a2 2 0 0 1 2-2h11l4 4v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
			'community' => '<svg ' . $attrs . '><circle cx="9" cy="8" r="3.2"/><path d="M2.5 19.5c.5-3 3.2-5 6.5-5s6 2 6.5 5"/><circle cx="17" cy="9" r="2.6"/><path d="M14.5 19.5c.4-2.4 2.3-4.2 5-4.5"/></svg>',
			'course'    => '<svg ' . $attrs . '><path d="M3 6.5 12 3l9 3.5L12 10 3 6.5Z"/><path d="M7 8.5v5c0 1.7 2.3 3 5 3s5-1.3 5-3v-5"/><path d="M21 6.5v6"/></svg>',
			'coaching'  => '<svg ' . $attrs . '><path d="M12 2.5 14.5 8 20 9l-4 4 1 5.6L12 16l-5 2.6L8 13 4 9l5.5-1L12 2.5Z"/></svg>',
			'nonprofit' => '<svg ' . $attrs . '><path d="M12 21s-7-4.5-7-10.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7 3.5C19 16.5 12 21 12 21Z"/></svg>',
		);
	}

	/**
	 * Allowed-tag rules for the icon SVGs above when passed through
	 * `wp_kses()`. Limits the markup surface to exactly what the icon set
	 * needs — no event attrs, no foreignObject, no script.
	 *
	 * @return array<string, array<string, true>>
	 */
	private static function svg_kses_rules(): array {
		$shared = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'transform'       => true,
			'opacity'         => true,
		);
		return array(
			'svg'      => array(
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'viewBox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'focusable'       => true,
				'aria-hidden'     => true,
				'role'            => true,
				'xmlns'           => true,
			),
			'path'     => array_merge( $shared, array( 'd' => true ) ),
			'circle'   => array_merge(
				$shared,
				array(
					'cx' => true,
					'cy' => true,
					'r'  => true,
				)
			),
			'rect'     => array_merge(
				$shared,
				array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
					'ry'     => true,
				)
			),
			'line'     => array_merge(
				$shared,
				array(
					'x1' => true,
					'y1' => true,
					'x2' => true,
					'y2' => true,
				)
			),
			'polyline' => array_merge( $shared, array( 'points' => true ) ),
			'polygon'  => array_merge( $shared, array( 'points' => true ) ),
			'g'        => $shared,
		);
	}
}
