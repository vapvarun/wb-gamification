<?php
/**
 * Setup: the owner's first screen, built from the plugins on the site.
 *
 * Four steps in one form: your community (which plugins to reward), how members earn (pace and
 * per-action amounts), what members get (levels, badges, leaderboard), review and launch.
 * Nothing is saved before Launch, and Launch writes the same options the Earning screen and the
 * settings write. Re-runnable any time from Tools > Setup; it opens with the site's values.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Admin;

use WBGam\Engine\Privacy;
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
	 * Per-user flag: this admin has dismissed the welcome notice for good.
	 *
	 * Per USER, not per site: on a multi-admin site one person dismissing the prompt should not decide
	 * for everyone else that they have seen it.
	 *
	 * @var string
	 */
	public const NOTICE_DISMISSED_META = 'wb_gam_dismissed_wizard_notice';

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
	 * Hook suffix of the wizard screen, set when the page is registered.
	 *
	 * @var string
	 */
	private static string $hook_suffix = '';

	/**
	 * Register hooks. Idempotent — every load registers the same set; gating
	 * happens inside each handler so admins can re-run the wizard at any
	 * time via the URL.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		// Listed nowhere in the menu and highlighted as Dashboard: AdminNav::CHILD_OF.
		// Auto-redirect on admin_init:1 — earlier than the default 10 so any
		// later admin_init callback that emits output doesn't block our redirect.
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_welcome_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_welcome_notice' ) );
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
		// The hook suffix add_submenu_page() returned (it depends on the parent menu's title).
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wb-gam-page-wizard',
			plugins_url( 'assets/css/admin/pages/wizard.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
		wp_enqueue_script(
			'wb-gam-admin-setup',
			plugins_url( 'assets/js/admin-setup.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-setup',
			'wbGamSetup',
			array(
				'i18n' => array(
					/* translators: 1: actions switched on, 2: all actions of the plugin. */
					'groupCount' => __( '%1$d of %2$d on', 'wb-gamification' ),
					/* translators: %s: comma-separated plugin names. */
					'plugins'    => __( '%s, and the WordPress basics.', 'wb-gamification' ),
					'onlyWp'     => __( 'The WordPress basics.', 'wb-gamification' ),
					/* translators: 1: actions switched on, 2: pace name. */
					'earning'    => __( '%1$d actions on. Pace: %2$s.', 'wb-gamification' ),
					/* translators: 1: number of levels, 2: leaderboard choice. */
					'rewards'    => __( '%1$d levels, your badge library, %2$s.', 'wb-gamification' ),
					'boardOurs'  => __( 'the Gamification leaderboard', 'wb-gamification' ),
					'boardJt'    => __( 'Jetonomy\'s leaderboard', 'wb-gamification' ),
				),
			)
		);
		SettingsPage::load_test_event_script();
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
			'wb-gamification', // Under Gamification; AdminNav lists it as Tools > Setup.
			__( 'Gamification Setup', 'wb-gamification' ),
			'',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		if ( $hook ) {
			self::$hook_suffix = $hook;
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
		// Only a normal admin page load by someone who can run the wizard may use up the flag. Any
		// other request reaches admin_init too: Heartbeat, a member's admin-ajax call from the front
		// end (BuddyPress loads the activity stream that way), cron, REST. Redirecting one of those
		// broke it (a member got a 403 and /activity/ hung) and spent the flag, so the admin never
		// saw the wizard either.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
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
	 * Persist a dismissal of the welcome notice.
	 *
	 * WordPress's `is-dismissible` is CLIENT-SIDE ONLY: clicking the X hides the notice for that page
	 * view, and it comes straight back on the next one. So an owner who had decided they did not want
	 * the starter template was told about it again on every plugin page, forever, until they gave in
	 * and ran the wizard.
	 *
	 * A dismissal that does not dismiss anything is worse than no X at all: it teaches the owner that
	 * our controls do not work.
	 *
	 * Same shape as the welcome-card dismissal already in SettingsPage -- a nonced GET, a capability
	 * check, a user-meta flag, and a redirect so a refresh cannot replay it.
	 *
	 * @return void
	 */
	public static function maybe_dismiss_welcome_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked immediately below.
		if ( empty( $_GET['wb_gam_dismiss_wizard_notice'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this IS the check.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wb_gam_dismiss_wizard_notice' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META, 1 );

		wp_safe_redirect( remove_query_arg( array( 'wb_gam_dismiss_wizard_notice', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Whether members are already earning points - a starter template is moot on a live site.
	 *
	 * @return bool
	 */
	private static function site_is_live(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one PK-indexed row, plugin admin screens only.
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}wb_gam_points LIMIT 1" );
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

		// They already said no. Asking again on every page load is not a reminder, it is nagging.
		if ( get_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META, true ) ) {
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
		// Members are already earning points, so the site is set up in practice.
		if ( self::site_is_live() ) {
			return;
		}

		$wizard_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		// Class `wb-gam-notice` whitelists this notice past the body-scoped CSS
		// filter declared in assets/css/admin.css (Welcome notice section).
		// Class `wb-gam-notice__cta` styles the CTA button spacing — see
		// the same CSS file. No inline style attributes (coding-rule 3).
		// A REAL dismiss link, not just the X.
		//
		// WordPress's `is-dismissible` X is client-side: it hides the notice for this page view and it
		// returns on the next one. The link below actually records the decision.
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'wb_gam_dismiss_wizard_notice', '1' ),
			'wb_gam_dismiss_wizard_notice'
		);

		printf(
			'<div class="notice notice-info wb-gam-notice is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary wb-gam-notice__cta">%4$s</a> <a href="%5$s" class="wb-gam-notice__dismiss">%6$s</a></p></div>',
			esc_html__( 'Welcome to WB Gamification!', 'wb-gamification' ),
			esc_html__( 'Choose which of your plugins reward members, and how generously - about two minutes.', 'wb-gamification' ),
			esc_url( $wizard_url ),
			esc_html__( 'Open setup', 'wb-gamification' ),
			esc_url( $dismiss_url ),
			esc_html__( 'No thanks', 'wb-gamification' )
		);
	}

	/**
	 * Save Setup on Launch (or mark it done on Skip). Nothing is written before this.
	 *
	 * One form, one write: the same options the Earning screen and the settings write
	 * (wb_gam_enabled_{id}, wb_gam_points_{id}, wb_gam_action_overrides, the leaderboard
	 * owner, public profiles, the three email switches). No option of its own.
	 */
	public static function handle_submission(): void {
		if ( ! isset( $_POST['wbgam_setup'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to complete setup.', 'wb-gamification' ) );
		}
		$choice = 'skip' === sanitize_key( wp_unslash( $_POST['wbgam_setup'] ) ) ? 'skip' : 'launch';

		/**
		 * Fires when an owner submits Setup, before anything is saved.
		 *
		 * @param string $choice 'launch' or 'skip'.
		 */
		do_action( 'wb_gamification_setup_wizard_started', $choice );

		if ( 'launch' === $choice ) {
			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- plan() whitelists and casts every value.
			$plan = self::plan(
				Registry::get_actions(),
				is_array( $post ) ? $post : array(),
				(array) get_option( 'wb_gam_action_overrides', array() ),
				array(
					'jetonomy'     => defined( 'JETONOMY_VERSION' ),
					'host_privacy' => Privacy::host_decides(),
				)
			);
			foreach ( $plan as $option => $value ) {
				update_option( $option, $value, 'wb_gam_action_overrides' === $option ? false : null );
			}
		}

		update_option( self::COMPLETED_OPTION, true );

		/**
		 * Fires after Setup has been saved.
		 *
		 * @param string $choice 'launch' or 'skip'.
		 */
		do_action( 'wb_gamification_setup_wizard_completed', $choice );

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification&setup=complete' ) );
		exit;
	}

	/**
	 * Turn the submitted Setup form into the options to save. Pure: no WordPress calls, so the
	 * whole launch is testable.
	 *
	 * - A plugin switched off: every one of its actions off. WordPress basics are always kept.
	 * - Kept plugins: each action on or off as ticked, with its amount (0 to Registry::POINTS_MAX).
	 * - Pace: Relaxed halves and Competitive doubles each plugin's own daily and weekly limits;
	 *   Balanced restores the plugin's limits; Keep leaves the owner's current limits alone.
	 *   Other override keys (cooldown, submittable) are never touched.
	 *
	 * @param array<string, array>                    $actions   Registry::get_actions().
	 * @param array                                   $post      The submitted form.
	 * @param array                                   $overrides Current wb_gam_action_overrides.
	 * @param array{jetonomy:bool, host_privacy:bool} $site What is on the site.
	 * @return array<string, mixed> option name => value.
	 */
	public static function plan( array $actions, array $post, array $overrides, array $site ): array {
		$kept  = array_map( 'strval', (array) ( $post['plugins'] ?? array() ) );
		$on    = (array) ( $post['on'] ?? array() );
		$pts   = (array) ( $post['points'] ?? array() );
		$pace  = in_array( $post['pace'] ?? '', array( 'relaxed', 'balanced', 'competitive', 'keep' ), true ) ? $post['pace'] : 'balanced';
		$scale = array(
			'relaxed'     => 0.5,
			'competitive' => 2.0,
		);
		$out   = array();

		foreach ( $actions as $id => $action ) {
			$id     = (string) $id;
			$plugin = self::plugin_of( $action );
			$active = 'WordPress' === $plugin || in_array( $plugin, $kept, true );

			$out[ 'wb_gam_enabled_' . $id ] = $active && ! empty( $on[ $id ] ) ? 1 : 0;

			$amount = isset( $pts[ $id ] ) ? trim( (string) $pts[ $id ] ) : '';
			if ( ctype_digit( $amount ) ) {
				$out[ 'wb_gam_points_' . $id ] = min( Registry::POINTS_MAX, (int) $amount );
			}

			if ( 'keep' === $pace || ! $active ) {
				continue;
			}
			$row = is_array( $overrides[ $id ] ?? null ) ? $overrides[ $id ] : array();
			foreach ( Registry::manifest_limits( $id ) as $key => $limit ) {
				if ( 'balanced' === $pace || 0 === $limit ) {
					unset( $row[ $key ] );
				} else {
					$row[ $key ] = max( 1, (int) ceil( $limit * $scale[ $pace ] ) );
				}
			}
			if ( $row ) {
				$overrides[ $id ] = $row;
			} else {
				unset( $overrides[ $id ] );
			}
		}
		if ( 'keep' !== $pace ) {
			$out['wb_gam_action_overrides'] = $overrides;
		}

		if ( $site['jetonomy'] && isset( $post['leaderboard'] ) ) {
			$out['wb_gam_leaderboard_authority'] = 'jetonomy' === $post['leaderboard'] ? 'jetonomy' : 'gamification';
		}
		if ( ! $site['host_privacy'] ) {
			$out['wb_gam_profile_public_enabled'] = empty( $post['profile_public'] ) ? '0' : '1';
		}
		// The engine's storage shape for these switches: '1' or '0' (see EmailSettingsController).
		$out['wb_gam_email_level_up']       = empty( $post['email_level_up'] ) ? '0' : '1';
		$out['wb_gam_email_badge_earned']   = empty( $post['email_badge_earned'] ) ? '0' : '1';
		$out['wb_gam_weekly_email_enabled'] = empty( $post['email_weekly'] ) ? 0 : 1;

		return $out;
	}

	/**
	 * The plugin an action belongs to, as the Earning screen groups it.
	 *
	 * @param array $action Registered action.
	 */
	private static function plugin_of( array $action ): string {
		$plugin = trim( (string) ( $action['plugin'] ?? '' ) );
		if ( '' === $plugin ) {
			return __( 'Other', 'wb-gamification' );
		}
		// A Pro add-on is the same choice as its plugin: "Jetonomy + Pro" is one card.
		$plugin = (string) preg_replace( '/ Pro$/', '', $plugin );
		return 'WPMediaVerse' === $plugin ? 'MediaVerse' : $plugin;
	}

	/**
	 * Registered actions by plugin, WordPress basics last.
	 *
	 * @return array<string, array<string, array>>
	 */
	private static function groups(): array {
		$groups = array();
		foreach ( Registry::get_actions() as $id => $action ) {
			$groups[ self::plugin_of( $action ) ][ (string) $id ] = $action;
		}
		ksort( $groups );
		if ( isset( $groups['WordPress'] ) ) {
			$wp = $groups['WordPress'];
			unset( $groups['WordPress'] );
			$groups['WordPress'] = $wp;
		}
		return $groups;
	}

	/**
	 * Plugins this one works with that are not on the site, read from the bundled integration
	 * manifests (no list to keep in sync). Pro add-ons are named by their free plugin.
	 *
	 * @param string[] $present Plugin names with actions on this site.
	 * @return string[]
	 */
	private static function also_works_with( array $present ): array {
		$names = array();
		foreach ( (array) glob( WB_GAM_PATH . 'integrations/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			if ( preg_match( "/'plugin'\\s*=>\\s*'([^']+)'/", $source, $m ) ) {
				$names[] = preg_replace( '/ Pro$/', '', $m[1] );
			}
		}
		$names = array_map( static fn( $n ) => 'WPMediaVerse' === $n ? 'MediaVerse' : $n, $names );
		return array_values( array_diff( array_unique( $names ), $present, array( 'WordPress' ) ) );
	}

	/**
	 * Limits in words, as the Earning screen shows them.
	 *
	 * @param array $action Registered action (with the owner's overrides).
	 */
	private static function limit_words( array $action ): string {
		$parts = array();
		if ( ! empty( $action['daily_cap'] ) ) {
			/* translators: %d: most awards per day. */
			$parts[] = sprintf( __( '%d a day', 'wb-gamification' ), (int) $action['daily_cap'] );
		}
		if ( ! empty( $action['weekly_cap'] ) ) {
			/* translators: %d: most awards per week. */
			$parts[] = sprintf( __( '%d a week', 'wb-gamification' ), (int) $action['weekly_cap'] );
		}
		if ( ! empty( $action['cooldown'] ) ) {
			/* translators: %d: seconds between awards. */
			$parts[] = sprintf( __( '%ds apart', 'wb-gamification' ), (int) $action['cooldown'] );
		}
		return $parts ? implode( ', ', $parts ) : __( 'No limit', 'wb-gamification' );
	}

	/**
	 * Render Setup: four steps in one form. Each step reads the site's current values, so
	 * running it again on a configured site starts from what the owner has.
	 */
	public static function render(): void {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		$is_re_run = (bool) get_option( self::COMPLETED_OPTION );
		$groups    = self::groups();
		$plugins   = array_diff( array_keys( $groups ), array( 'WordPress' ) );
		$also      = self::also_works_with( array_keys( $groups ) );
		$hub_id    = (int) get_option( 'wb_gam_hub_page_id', 0 );
		$hub_url   = $hub_id ? get_permalink( $hub_id ) : '';
		$jetonomy  = defined( 'JETONOMY_VERSION' );
		$authority = (string) get_option( 'wb_gam_leaderboard_authority', 'gamification' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the level ladder (a handful of rows), Setup only.
		$levels = (array) $wpdb->get_results( "SELECT name, min_points FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC LIMIT 12", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one COUNT on the badge definitions, Setup only.
		$badges = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_badge_defs" );

		$enabled = static fn( string $id ): bool => (bool) get_option( 'wb_gam_enabled_' . $id, true );
		$steps   = array(
			1 => __( 'Your community', 'wb-gamification' ),
			2 => __( 'How members earn', 'wb-gamification' ),
			3 => __( 'What members get', 'wb-gamification' ),
			4 => __( 'Review and launch', 'wb-gamification' ),
		);
		?>
		<div class="wrap wbgam-setup" data-wbgam-setup>
			<form method="post" class="wbgam-setup__form" data-wbgam-setup-form>
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<header class="wbgam-setup__head">
					<h1 class="wbgam-setup__title"><?php echo $is_re_run ? esc_html__( 'Setup', 'wb-gamification' ) : esc_html__( 'Set up gamification', 'wb-gamification' ); ?></h1>
					<ol class="wbgam-setup__steps">
						<?php foreach ( $steps as $n => $label ) : ?>
							<li><button type="button" class="wbgam-setup__step" data-wbgam-goto="<?php echo esc_attr( (string) $n ); ?>"><span class="wbgam-setup__step-num"><?php echo esc_html( (string) $n ); ?></span> <?php echo esc_html( $label ); ?></button></li>
						<?php endforeach; ?>
					</ol>
					<button type="submit" name="wbgam_setup" value="skip" class="wbgam-setup__skip" formnovalidate>
						<?php echo $is_re_run ? esc_html__( 'Leave without changes', 'wb-gamification' ) : esc_html__( 'Skip setup, I\'ll configure it myself', 'wb-gamification' ); ?>
					</button>
				</header>
				<?php if ( ! $is_re_run ) : ?>
					<p class="wbgam-setup__skip-note"><?php esc_html_e( 'Skipping keeps the defaults: members already earn with each plugin\'s own values. Change them any time under Earning.', 'wb-gamification' ); ?></p>
				<?php endif; ?>

				<!-- 1. Your community -->
				<section class="wbgam-setup__panel" data-wbgam-step="1" aria-labelledby="wbgam-setup-s1">
					<h2 id="wbgam-setup-s1">
						<?php
						echo esc_html(
							$plugins
								/* translators: %d: number of plugins found. */
								? sprintf( _n( 'We found %d plugin to reward', 'We found %d plugins to reward', count( $plugins ), 'wb-gamification' ), count( $plugins ) )
								: __( 'Members earn for the WordPress basics', 'wb-gamification' )
						);
						?>
					</h2>
					<p class="wbgam-setup__lede"><?php esc_html_e( 'Members earn points for what they already do in them. Switch off any plugin you don\'t want to reward. Every amount and limit stays yours to change later under Earning.', 'wb-gamification' ); ?></p>
					<div class="wbgam-setup__plugins">
						<?php
						foreach ( $groups as $plugin => $actions ) :
							$is_wp     = 'WordPress' === $plugin;
							$penalties = count( array_filter( $actions, static fn( $a ) => ! empty( $a['penalty'] ) ) );
							$kept      = $is_wp || array_filter( array_keys( $actions ), $enabled );
							$examples  = array_slice( array_filter( $actions, static fn( $a ) => empty( $a['penalty'] ) ), 0, 3 );
							$with_pro  = (bool) array_filter( $actions, static fn( $a ) => (bool) preg_match( '/ Pro$/', (string) ( $a['plugin'] ?? '' ) ) );
							?>
							<label class="wbgam-setup__plugin<?php echo $is_wp ? ' wbgam-setup__plugin--always' : ''; ?>">
								<?php if ( $is_wp ) : ?>
									<input type="hidden" name="plugins[]" value="WordPress">
								<?php else : ?>
									<input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $plugin ); ?>" data-wbgam-plugin-toggle <?php checked( (bool) $kept ); ?>>
								<?php endif; ?>
								<span class="wbgam-setup__plugin-head">
									<strong>
										<?php echo esc_html( $is_wp ? __( 'WordPress basics are always included', 'wb-gamification' ) : $plugin ); ?>
										<?php if ( $with_pro ) : ?>
											<span class="wbgam-pill wbgam-pill--info"><?php esc_html_e( '+ Pro', 'wb-gamification' ); ?></span>
										<?php endif; ?>
									</strong>
									<span class="wbgam-setup__plugin-meta">
										<?php
										/* translators: %d: number of actions. */
										echo esc_html( sprintf( _n( '%d action', '%d actions', count( $actions ), 'wb-gamification' ), count( $actions ) ) );
										if ( $penalties ) {
											/* translators: %d: number of actions that take points back. */
											echo esc_html( ' · ' . sprintf( _n( '%d takes points back', '%d take points back', $penalties, 'wb-gamification' ), $penalties ) );
										}
										?>
									</span>
								</span>
								<span class="wbgam-setup__examples">
									<?php foreach ( $examples as $id => $a ) : ?>
										<span><b><?php echo esc_html( number_format_i18n( (int) get_option( 'wb_gam_points_' . $id, $a['default_points'] ?? 0 ) ) ); ?></b> <?php echo esc_html( (string) ( $a['label'] ?? $id ) ); ?></span>
									<?php endforeach; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<?php if ( $also ) : ?>
						<p class="wbgam-setup__also">
							<strong><?php esc_html_e( 'Also works with', 'wb-gamification' ); ?></strong>
							<?php
							/* translators: %s: comma-separated plugin names. */
							echo esc_html( sprintf( __( '%s. Install one later and its actions appear under Earning.', 'wb-gamification' ), implode( ', ', $also ) ) );
							?>
						</p>
					<?php endif; ?>
					<p class="wbgam-setup__nav"><span><?php esc_html_e( 'Nothing is saved until you launch in step 4.', 'wb-gamification' ); ?></span> <button type="button" class="button button-primary" data-wbgam-goto="2"><?php esc_html_e( 'Continue', 'wb-gamification' ); ?></button></p>
				</section>

				<!-- 2. How members earn -->
				<section class="wbgam-setup__panel" data-wbgam-step="2" aria-labelledby="wbgam-setup-s2">
					<h2 id="wbgam-setup-s2"><?php esc_html_e( 'How generous should it be?', 'wb-gamification' ); ?></h2>
					<p class="wbgam-setup__lede"><?php esc_html_e( 'One choice sets the daily and weekly limits for the actions you kept. Amounts follow each plugin\'s own values; open a plugin to change them.', 'wb-gamification' ); ?></p>
					<div class="wbgam-setup__paces" role="radiogroup" aria-label="<?php esc_attr_e( 'Pace', 'wb-gamification' ); ?>">
						<?php
						$paces = array(
							'relaxed'     => array( __( 'Relaxed', 'wb-gamification' ), __( 'Half the plugins\' daily and weekly limits. For a quiet community or one just starting.', 'wb-gamification' ) ),
							'balanced'    => array( __( 'Balanced', 'wb-gamification' ), __( 'Each plugin\'s own limits. Steady rewards; the limits stop anyone farming points with throwaway posts.', 'wb-gamification' ) ),
							'competitive' => array( __( 'Competitive', 'wb-gamification' ), __( 'Double the limits. For active, game-minded communities.', 'wb-gamification' ) ),
						);
						if ( $is_re_run ) {
							$paces = array( 'keep' => array( __( 'Keep my limits', 'wb-gamification' ), __( 'Leave the limits you set under Earning as they are.', 'wb-gamification' ) ) ) + $paces;
						}
						$default_pace = $is_re_run ? 'keep' : 'balanced';
						foreach ( $paces as $key => $pace ) :
							?>
							<label class="wbgam-setup__pace">
								<input type="radio" name="pace" value="<?php echo esc_attr( $key ); ?>" <?php checked( $default_pace, $key ); ?>>
								<strong><?php echo esc_html( $pace[0] ); ?><?php echo 'balanced' === $key ? ' · ' . esc_html__( 'recommended', 'wb-gamification' ) : ''; ?></strong>
								<span><?php echo esc_html( $pace[1] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<?php foreach ( $groups as $plugin => $actions ) : ?>
						<details class="wbgam-setup__group" data-wbgam-plugin="<?php echo esc_attr( $plugin ); ?>">
							<summary>
								<strong><?php echo esc_html( 'WordPress' === $plugin ? __( 'WordPress basics', 'wb-gamification' ) : $plugin ); ?></strong>
								<span class="wbgam-setup__plugin-meta" data-wbgam-group-count></span>
							</summary>
							<table class="wbgam-setup__actions">
								<thead><tr><th scope="col"><?php esc_html_e( 'On', 'wb-gamification' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th><th scope="col"><?php esc_html_e( 'Points', 'wb-gamification' ); ?></th><th scope="col"><?php esc_html_e( 'Limit', 'wb-gamification' ); ?></th></tr></thead>
								<tbody>
									<?php foreach ( $actions as $id => $a ) : ?>
										<tr>
											<td><input type="checkbox" name="on[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $enabled( $id ) ); ?> aria-label="<?php /* translators: %s: action label */ echo esc_attr( sprintf( __( 'Pay for %s', 'wb-gamification' ), $a['label'] ?? $id ) ); ?>"></td>
											<td>
												<?php echo esc_html( (string) ( $a['label'] ?? $id ) ); ?>
												<?php if ( ! empty( $a['penalty'] ) ) : ?>
													<span class="wbgam-pill wbgam-pill--warning"><?php esc_html_e( 'Takes points', 'wb-gamification' ); ?></span>
												<?php endif; ?>
											</td>
											<td><input type="number" class="wbgam-input wbgam-input--xs" name="points[<?php echo esc_attr( $id ); ?>]" min="0" max="<?php echo esc_attr( (string) Registry::POINTS_MAX ); ?>" value="<?php echo esc_attr( (string) (int) get_option( 'wb_gam_points_' . $id, $a['default_points'] ?? 0 ) ); ?>" aria-label="<?php /* translators: %s: action label */ echo esc_attr( sprintf( __( 'Points for %s', 'wb-gamification' ), $a['label'] ?? $id ) ); ?>"></td>
											<td class="wbgam-setup__limit"><?php echo esc_html( self::limit_words( $a ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endforeach; ?>
					<p class="wbgam-setup__nav"><button type="button" class="button" data-wbgam-goto="1"><?php esc_html_e( 'Back', 'wb-gamification' ); ?></button> <button type="button" class="button button-primary" data-wbgam-goto="3"><?php esc_html_e( 'Continue', 'wb-gamification' ); ?></button></p>
				</section>

				<!-- 3. What members get -->
				<section class="wbgam-setup__panel" data-wbgam-step="3" aria-labelledby="wbgam-setup-s3">
					<h2 id="wbgam-setup-s3"><?php esc_html_e( 'What members get for their points', 'wb-gamification' ); ?></h2>
					<p class="wbgam-setup__lede"><?php esc_html_e( 'Levels to climb, badges to collect and a leaderboard. Rename or change any of them later under Rewards.', 'wb-gamification' ); ?></p>
					<div class="wbgam-setup__card">
						<h3><?php esc_html_e( 'Levels', 'wb-gamification' ); ?></h3>
						<?php if ( $levels ) : ?>
							<ol class="wbgam-setup__levels">
								<?php foreach ( $levels as $level ) : ?>
									<li><strong><?php echo esc_html( (string) $level['name'] ); ?></strong> <span><?php echo esc_html( number_format_i18n( (int) $level['min_points'] ) ); ?></span></li>
								<?php endforeach; ?>
							</ol>
						<?php else : ?>
							<p><?php esc_html_e( 'No levels yet.', 'wb-gamification' ); ?></p>
						<?php endif; ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-levels' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit level names and points', 'wb-gamification' ); ?></a>
					</div>
					<div class="wbgam-setup__card">
						<h3><?php esc_html_e( 'Badges', 'wb-gamification' ); ?></h3>
						<p>
							<?php
							/* translators: %d: number of badges. */
							echo esc_html( sprintf( _n( '%d badge in your library. Members earn them as they reach milestones.', '%d badges in your library. Members earn them as they reach milestones.', $badges, 'wb-gamification' ), $badges ) );
							?>
						</p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the badge library', 'wb-gamification' ); ?></a>
					</div>
					<div class="wbgam-setup__card">
						<h3><?php esc_html_e( 'Leaderboard', 'wb-gamification' ); ?></h3>
						<?php if ( $jetonomy ) : ?>
							<label class="wbgam-setup__choice"><input type="radio" name="leaderboard" value="gamification" <?php checked( 'jetonomy' !== $authority ); ?>> <strong><?php esc_html_e( 'Gamification leaderboard', 'wb-gamification' ); ?></strong> <span><?php esc_html_e( 'One board for the whole community, all plugins counted.', 'wb-gamification' ); ?></span></label>
							<label class="wbgam-setup__choice"><input type="radio" name="leaderboard" value="jetonomy" <?php checked( 'jetonomy', $authority ); ?>> <strong><?php esc_html_e( 'Let Jetonomy run it', 'wb-gamification' ); ?></strong> <span><?php esc_html_e( 'Your forums already rank members. Ours is hidden so there is one board.', 'wb-gamification' ); ?></span></label>
						<?php else : ?>
							<p><?php esc_html_e( 'One board for the whole community, all plugins counted.', 'wb-gamification' ); ?></p>
						<?php endif; ?>
					</div>
					<div class="wbgam-setup__card">
						<h3><?php esc_html_e( 'Where members see it', 'wb-gamification' ); ?></h3>
						<ul class="wbgam-setup__where">
							<li><?php esc_html_e( 'A short toast when they earn something.', 'wb-gamification' ); ?></li>
							<?php if ( $hub_url ) : ?>
								<li>
									<?php
									/* translators: %s: path of the Gamification page. */
									echo esc_html( sprintf( __( 'The Gamification page at %s.', 'wb-gamification' ), wp_make_link_relative( $hub_url ) ) );
									?>
								</li>
							<?php endif; ?>
						</ul>
						<?php if ( ! Privacy::host_decides() ) : ?>
							<label class="wbgam-setup__choice"><input type="checkbox" name="profile_public" value="1" <?php checked( (bool) get_option( 'wb_gam_profile_public_enabled', true ) ); ?>> <strong><?php esc_html_e( 'Public profile pages', 'wb-gamification' ); ?></strong> <span><?php esc_html_e( 'Show members\' points, badges and level to others. Each member can still hide their own.', 'wb-gamification' ); ?></span></label>
						<?php endif; ?>
					</div>
					<p class="wbgam-setup__nav"><button type="button" class="button" data-wbgam-goto="2"><?php esc_html_e( 'Back', 'wb-gamification' ); ?></button> <button type="button" class="button button-primary" data-wbgam-goto="4"><?php esc_html_e( 'Continue', 'wb-gamification' ); ?></button></p>
				</section>

				<!-- 4. Review and launch -->
				<section class="wbgam-setup__panel" data-wbgam-step="4" aria-labelledby="wbgam-setup-s4">
					<h2 id="wbgam-setup-s4"><?php esc_html_e( 'Ready to launch', 'wb-gamification' ); ?></h2>
					<p class="wbgam-setup__lede"><?php esc_html_e( 'This is everything that will be saved. Members start earning from their next action; nothing is awarded for past activity.', 'wb-gamification' ); ?></p>
					<dl class="wbgam-setup__summary">
						<div><dt><?php esc_html_e( 'Plugins rewarded', 'wb-gamification' ); ?></dt><dd data-wbgam-sum="plugins"></dd><dd><button type="button" class="button-link" data-wbgam-goto="1"><?php esc_html_e( 'Change', 'wb-gamification' ); ?></button></dd></div>
						<div><dt><?php esc_html_e( 'Earning', 'wb-gamification' ); ?></dt><dd data-wbgam-sum="earning"></dd><dd><button type="button" class="button-link" data-wbgam-goto="2"><?php esc_html_e( 'Change', 'wb-gamification' ); ?></button></dd></div>
						<div><dt><?php esc_html_e( 'Rewards', 'wb-gamification' ); ?></dt><dd data-wbgam-sum="rewards"></dd><dd><button type="button" class="button-link" data-wbgam-goto="3"><?php esc_html_e( 'Change', 'wb-gamification' ); ?></button></dd></div>
					</dl>
					<div class="wbgam-setup__card">
						<h3><?php esc_html_e( 'Emails', 'wb-gamification' ); ?></h3>
						<?php
						$emails = array(
							'email_level_up'     => array( __( 'Level-up email', 'wb-gamification' ), __( 'Members already see a toast.', 'wb-gamification' ), $is_re_run ? (bool) get_option( 'wb_gam_email_level_up', false ) : false ),
							'email_badge_earned' => array( __( 'Badge email', 'wb-gamification' ), __( 'Members already see a toast.', 'wb-gamification' ), $is_re_run ? (bool) get_option( 'wb_gam_email_badge_earned', false ) : false ),
							'email_weekly'       => array( __( 'Weekly recap', 'wb-gamification' ), __( 'One email a week with their points and rank.', 'wb-gamification' ), $is_re_run ? (bool) (int) get_option( 'wb_gam_weekly_email_enabled', 1 ) : true ),
						);
						foreach ( $emails as $name => $email ) :
							?>
							<label class="wbgam-setup__choice"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $email[2] ); ?>> <strong><?php echo esc_html( $email[0] ); ?></strong> <span><?php echo esc_html( $email[1] ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<div class="wbgam-setup__card">
						<h3><?php esc_html_e( 'See it as a member would', 'wb-gamification' ); ?></h3>
						<p><?php esc_html_e( 'Gives your own account 10 points, so you see the toast on the front of the site.', 'wb-gamification' ); ?></p>
						<button type="button" class="button" data-wb-gam-test-event data-points="10"><?php esc_html_e( 'Send me a test toast', 'wb-gamification' ); ?></button>
						<span class="wbgam-test-event-status" data-wb-gam-test-event-status role="status" aria-live="polite"></span>
					</div>
					<p class="wbgam-setup__nav">
						<button type="button" class="button" data-wbgam-goto="3"><?php esc_html_e( 'Back', 'wb-gamification' ); ?></button>
						<span><?php esc_html_e( 'Next you land on Overview, with a short checklist.', 'wb-gamification' ); ?></span>
						<button type="submit" name="wbgam_setup" value="launch" class="button button-primary button-hero"><?php esc_html_e( 'Launch gamification', 'wb-gamification' ); ?></button>
					</p>
				</section>
			</form>
		</div>
		<?php
	}
}
