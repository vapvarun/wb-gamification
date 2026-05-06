<?php
/**
 * WB Gamification Settings Page
 *
 * Tabs: Points · Levels · Webhooks
 *
 * Points tab: lists all registered actions with editable point values and
 * per-action enable/disable toggles. Shows current mode (Standalone /
 * Community / Full Reign) in the page header.
 *
 * Levels tab: editable level name and min_points thresholds.
 *
 * All form processing uses Settings API + nonces. No AJAX in this phase —
 * page reloads on save. Interactivity API enhancements are Phase 2.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Admin;

use WBGam\Admin\AnalyticsDashboard;
use WBGam\Admin\CohortSettingsPage;
use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the WB Gamification settings page with
 * Points, Levels, and Automation tabs.
 */
final class SettingsPage {

	/**
	 * Register admin_menu and form-handler hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss_welcome' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_levels_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_settings_toggles' ) );
		// admin_post_wb_gam_save_levels + admin_post_wb_gam_delete_level removed in 1.0.0:
		// the Levels tab now consumes /wb-gamification/v1/levels (POST/PATCH/DELETE)
		// directly via assets/js/admin-levels.js. See Tier 0.C migration.
	}

	/**
	 * Register the top-level gamification admin menu page.
	 */
	public static function register_page(): void {
		add_menu_page(
			__( 'WB Gamification', 'wb-gamification' ),
			__( 'Gamification', 'wb-gamification' ),
			'manage_options',
			'wb-gamification',
			array( __CLASS__, 'render' ),
			'dashicons-awards',
			56
		);
	}

	// ── Form handlers ─────────────────────────────────────────────────────────

	/**
	 * Handle points/automation settings form submissions (admin_init).
	 */
	public static function handle_save(): void {
		if ( ! isset( $_POST['wb_gam_settings_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'wb_gam_save_settings', 'wb_gam_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'points';

		if ( 'points' === $tab ) {
			self::save_points_settings();
		} elseif ( 'kudos' === $tab ) {
			self::save_kudos_settings();
		} elseif ( 'automation' === $tab ) {
			self::save_automation_settings();
		}
	}

	/**
	 * Dismiss the first-run welcome card for the current admin.
	 */
	public static function handle_dismiss_welcome(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		if ( empty( $_GET['dismiss_welcome'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wb_gam_dismiss_welcome' ) ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), 'wb_gam_dismissed_welcome', 1 );
			wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification' ) );
			exit;
		}
	}

	/**
	 * Persist rank automation rules from the Automation tab form.
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	private static function save_automation_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
		$existing_rules = array();
		$stored         = get_option( 'wb_gam_rank_automation_rules', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$existing_rules = $decoded;
			}
		}

		$action = sanitize_key( $_POST['wb_gam_automation_action'] ?? 'add' );

		if ( 'delete' === $action ) {
			$index = (int) wp_unslash( $_POST['wb_gam_rule_index'] ?? -1 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int sanitizes.
			if ( isset( $existing_rules[ $index ] ) ) {
				array_splice( $existing_rules, $index, 1 );
				update_option( 'wb_gam_rank_automation_rules', wp_json_encode( array_values( $existing_rules ) ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_automation_rule sanitizes each field.
		$raw = (array) wp_unslash( $_POST['wb_gam_new_rule'] ?? array() );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$rule = self::normalize_automation_rule( $raw );
		if ( $rule ) {
			$existing_rules[] = $rule;
			update_option( 'wb_gam_rank_automation_rules', wp_json_encode( array_values( $existing_rules ) ) );
		}
	}

	/**
	 * Persist per-action point values and enable/disable toggles from the Points tab.
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	private static function save_points_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
		$actions = Registry::get_actions();

		foreach ( $actions as $action_id => $action ) {
			$key    = 'wb_gam_points_' . sanitize_key( $action_id );
			$points = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( null !== $points && $points >= 0 ) {
				update_option( 'wb_gam_points_' . $action_id, $points );
			}

			$enabled_key = 'wb_gam_enabled_' . sanitize_key( $action_id );
			update_option( 'wb_gam_enabled_' . $action_id, isset( $_POST[ $enabled_key ] ) ? true : false );
		}

		// Also save log retention.
		if ( isset( $_POST['wb_gam_log_retention_months'] ) ) {
			$months = max( 1, min( 24, absint( wp_unslash( $_POST['wb_gam_log_retention_months'] ) ) ) );
			update_option( 'wb_gam_log_retention_months', $months );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_settings_error( 'wb_gamification', 'saved', __( 'Settings saved.', 'wb-gamification' ), 'success' );
	}

	/**
	 * Persist kudos engine settings.
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	private static function save_kudos_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
		if ( isset( $_POST['wb_gam_kudos_daily_limit'] ) ) {
			update_option( 'wb_gam_kudos_daily_limit', max( 1, min( 999, absint( wp_unslash( $_POST['wb_gam_kudos_daily_limit'] ) ) ) ) );
		}
		if ( isset( $_POST['wb_gam_kudos_receiver_points'] ) ) {
			update_option( 'wb_gam_kudos_receiver_points', max( 0, min( 9999, absint( wp_unslash( $_POST['wb_gam_kudos_receiver_points'] ) ) ) ) );
		}
		if ( isset( $_POST['wb_gam_kudos_giver_points'] ) ) {
			update_option( 'wb_gam_kudos_giver_points', max( 0, min( 9999, absint( wp_unslash( $_POST['wb_gam_kudos_giver_points'] ) ) ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_settings_error( 'wb_gamification', 'saved', __( 'Kudos settings saved.', 'wb-gamification' ), 'success' );
	}

	/**
	 * Normalize and validate a single automation rule from POST data.
	 *
	 * @param array $raw Raw POST fields for this rule.
	 * @return array|null Normalized rule array, or null if invalid.
	 */
	public static function normalize_automation_rule( array $raw ): ?array {
		$level_id    = (int) ( $raw['trigger_level_id'] ?? 0 );
		$action_type = sanitize_key( $raw['action_type'] ?? '' );

		if ( $level_id <= 0 ) {
			return null;
		}

		$allowed_types = array( 'add_bp_group', 'send_bp_message', 'change_wp_role' );
		if ( ! in_array( $action_type, $allowed_types, true ) ) {
			return null;
		}

		$action = array( 'type' => $action_type );

		switch ( $action_type ) {
			case 'add_bp_group':
				$action['group_id'] = absint( $raw['group_id'] ?? 0 );
				if ( ! $action['group_id'] ) {
					return null;
				}
				break;

			case 'change_wp_role':
				$action['role'] = sanitize_key( $raw['role'] ?? '' );
				if ( ! $action['role'] ) {
					return null;
				}
				break;

			case 'send_bp_message':
				$action['sender_id'] = absint( $raw['sender_id'] ?? 1 ) ?: 1;
				$action['subject']   = sanitize_text_field( wp_unslash( $raw['subject'] ?? '' ) );
				$action['content']   = sanitize_textarea_field( wp_unslash( $raw['content'] ?? '' ) );
				if ( ! $action['subject'] || ! $action['content'] ) {
					return null;
				}
				break;
		}

		return array(
			'trigger_level_id' => $level_id,
			'actions'          => array( $action ),
		);
	}

	/**
	 * Enqueue the REST-driven Levels tab JS bundle on this admin page only.
	 *
	 * Replaces the deprecated `admin_post_wb_gam_save_levels` and
	 * `admin_post_wb_gam_delete_level` form-post handlers (1.0.0 Tier 0.C).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_levels_assets( string $hook_suffix ): void {
		// `toplevel_page_wb-gamification` is the hook for the top-level menu page
		// registered in self::register_page().
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
			return;
		}
		// Only enqueue on the Levels tab to keep other tabs lean.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'points'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-mutating tab dispatch.
		if ( 'levels' !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'wb-gam-admin-rest-utils',
			plugins_url( 'assets/js/admin-rest-utils.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		wp_enqueue_script(
			'wb-gam-admin-levels',
			plugins_url( 'assets/js/admin-levels.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);

		wp_localize_script(
			'wb-gam-admin-levels',
			'wbGamLevelsSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'aria_name'        => __( 'Level name', 'wb-gamification' ),
					'aria_points'      => __( 'Level minimum points', 'wb-gamification' ),
					'starting_locked'  => __( 'Starting level is always 0', 'wb-gamification' ),
					'starting_level'   => __( 'Starting level', 'wb-gamification' ),
					'delete'           => __( 'Delete', 'wb-gamification' ),
					'saved'            => __( 'Levels saved.', 'wb-gamification' ),
					'save_failed'      => __( 'Some levels failed to save.', 'wb-gamification' ),
					'added'            => __( 'Level added.', 'wb-gamification' ),
					'add_failed'       => __( 'Failed to add level.', 'wb-gamification' ),
					'add_invalid'      => __( 'Provide a name and points value.', 'wb-gamification' ),
					'deleted'          => __( 'Level deleted.', 'wb-gamification' ),
					'delete_failed'    => __( 'Failed to delete level.', 'wb-gamification' ),
					'confirm_delete'   => __( 'Delete this level?', 'wb-gamification' ),
					'refresh_failed'   => __( 'Failed to load levels.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Enqueue the rule-action toggle script on the Automation tab.
	 *
	 * Replaces the legacy inline <script> that lived in render_automation_section()
	 * — keeps Settings page free of inline JS per coding-rules-check.sh Rule 4.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_settings_toggles( string $hook_suffix ): void {
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-mutating tab dispatch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'points';
		if ( 'automation' !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'wb-gam-admin-rule-action-toggle',
			plugins_url( 'assets/js/admin-rule-action-toggle.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the settings page HTML with sidebar + card layout.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/URL parameter, no form data processed here.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		wp_enqueue_script(
			'wbgam-settings-nav',
			WB_GAM_URL . 'assets/js/settings-nav.js',
			array(),
			WB_GAM_VERSION,
			true
		);

		$bp_active = function_exists( 'buddypress' );
		?>
		<header class="wbgam-settings-topbar">
			<div class="wbgam-settings-topbar__brand">
				<span class="wbgam-settings-topbar__logo dashicons dashicons-awards" aria-hidden="true"></span>
				<div class="wbgam-settings-topbar__text">
					<h1 class="wbgam-settings-topbar__title">
						<?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?>
						<span class="wbgam-settings-topbar__version">v<?php echo esc_html( WB_GAM_VERSION ); ?></span>
					</h1>
					<p class="wbgam-settings-topbar__desc">
						<?php esc_html_e( 'Points, badges, levels, leaderboards, challenges and streaks — configure your community gamification engine.', 'wb-gamification' ); ?>
					</p>
				</div>
			</div>
			<div class="wbgam-settings-topbar__actions">
				<a class="wbgam-btn wbgam-btn--secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-setup' ) ); ?>">
					<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
					<?php esc_html_e( 'Run Setup Wizard', 'wb-gamification' ); ?>
				</a>
			</div>
		</header>
		<div class="wbgam-settings-wrap">

			<!-- Sidebar -->
			<div class="wbgam-settings-sidebar">

				<!-- CORE -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Core', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="#dashboard" data-section="dashboard">
						<span class="dashicons dashicons-dashboard"></span>
						<?php esc_html_e( 'Dashboard', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#points" data-section="points">
						<span class="dashicons dashicons-star-filled"></span>
						<?php esc_html_e( 'Points', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#levels" data-section="levels">
						<span class="dashicons dashicons-chart-bar"></span>
						<?php esc_html_e( 'Levels', 'wb-gamification' ); ?>
					</a>
				</div>

				<!-- ENGAGEMENT -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Engagement', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>">
						<span class="dashicons dashicons-flag"></span>
						<?php esc_html_e( 'Challenges', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>">
						<span class="dashicons dashicons-shield"></span>
						<?php esc_html_e( 'Badges', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#kudos" data-section="kudos">
						<span class="dashicons dashicons-thumbs-up"></span>
						<?php esc_html_e( 'Kudos', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#cohort" data-section="cohort">
						<span class="dashicons dashicons-groups"></span>
						<?php esc_html_e( 'Cohort Leagues', 'wb-gamification' ); ?>
					</a>
				</div>

				<!-- AUTOMATION -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Automation', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="#rules" data-section="rules">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Rules', 'wb-gamification' ); ?>
					</a>
				</div>

				<!-- ADVANCED -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Advanced', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-api-keys' ) ); ?>">
						<span class="dashicons dashicons-admin-network"></span>
						<?php esc_html_e( 'API Keys', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#integrations" data-section="integrations">
						<span class="dashicons dashicons-admin-links"></span>
						<?php esc_html_e( 'Integrations', 'wb-gamification' ); ?>
					</a>
				</div>
			</div>

			<!-- Content -->
			<div class="wbgam-settings-content">
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
				<?php if ( isset( $_GET['saved'] ) ) : ?>
					<div class="wbgam-banner wbgam-banner--success wbgam-stack-block" role="status" aria-live="polite">
						<span class="wbgam-banner__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php esc_html_e( 'Settings saved.', 'wb-gamification' ); ?></p></div>
					</div>
				<?php endif; ?>
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
				<?php if ( isset( $_GET['setup'] ) && 'complete' === $_GET['setup'] ) : ?>
					<div class="wbgam-banner wbgam-banner--success wbgam-stack-block" role="status" aria-live="polite">
						<span class="wbgam-banner__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php esc_html_e( 'Setup complete! Review your point values below.', 'wb-gamification' ); ?></p></div>
					</div>
				<?php endif; ?>

				<?php settings_errors( 'wb_gamification' ); ?>

				<!-- Dashboard section -->
				<div class="wbgam-settings-section" id="section-dashboard">
					<?php self::render_dashboard_tab(); ?>
				</div>

				<!-- Points section -->
				<div class="wbgam-settings-section" id="section-points">
					<?php self::render_points_tab(); ?>
				</div>

				<!-- Levels section -->
				<div class="wbgam-settings-section" id="section-levels">
					<?php self::render_levels_tab(); ?>
				</div>

				<!-- Kudos section -->
				<div class="wbgam-settings-section" id="section-kudos">
					<?php self::render_kudos_section(); ?>
				</div>

				<!-- Cohort Leagues section -->
				<div class="wbgam-settings-section" id="section-cohort">
					<?php CohortSettingsPage::render_inline(); ?>
				</div>

				<!-- Rules (Automation) section -->
				<div class="wbgam-settings-section" id="section-rules">
					<?php self::render_automation_tab(); ?>
				</div>

				<!-- Integrations section -->
				<div class="wbgam-settings-section" id="section-integrations">
					<?php self::render_integrations_section( $bp_active ); ?>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the standalone Dashboard tab (old tab=dashboard).
	 *
	 * Uses the original wrap + KPI layout since it's a full page, not a
	 * sidebar section.
	 */
	private static function render_dashboard_page(): void {
		settings_errors( 'wb_gamification' );
		?>
		<div class="wrap wbgam-wrap" id="wb-gam-settings">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title">
						<?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?>
						<span class="wbgam-settings-topbar__version">v<?php echo esc_html( WB_GAM_VERSION ); ?></span>
					</h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'At-a-glance view of your community gamification — points, members, badges, challenges, streaks and kudos over the last 30 days.', 'wb-gamification' ); ?></p>
				</div>
				<div class="wbgam-page-header__actions">
					<?php self::render_mode_badge(); ?>
					<a class="wbgam-btn wbgam-btn--secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=points' ) ); ?>">
						<?php esc_html_e( 'Configure', 'wb-gamification' ); ?>
					</a>
				</div>
			</header>
			<?php self::render_dashboard_tab(); ?>
		</div>
		<?php
	}

	// ── Points tab ────────────────────────────────────────────────────────────

	/**
	 * Render the Points settings section (card layout).
	 */
	private static function render_points_tab(): void {
		$actions = Registry::get_actions();
		$by_cat  = array();
		foreach ( $actions as $action ) {
			$by_cat[ $action['category'] ?? 'general' ][] = $action;
		}
		ksort( $by_cat );

		$cat_labels = array(
			'wordpress'  => __( 'WordPress', 'wb-gamification' ),
			'buddypress' => __( 'BuddyPress', 'wb-gamification' ),
			'commerce'   => __( 'Commerce', 'wb-gamification' ),
			'learning'   => __( 'Learning', 'wb-gamification' ),
			'social'     => __( 'Social', 'wb-gamification' ),
			'general'    => __( 'General', 'wb-gamification' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=points' ) ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

			<?php if ( empty( $actions ) ) : ?>
				<div class="wbgam-settings-card">
					<div class="wbgam-settings-card__head">
						<p class="wbgam-settings-card__title"><?php esc_html_e( 'POINTS', 'wb-gamification' ); ?></p>
						<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Configure point values for each action.', 'wb-gamification' ); ?></p>
					</div>
					<div class="wbgam-settings-card__body wbgam-settings-card__body--cozy">
						<p><?php esc_html_e( 'No gamification actions are registered yet. Triggers load automatically once BuddyPress or other integrations are active.', 'wb-gamification' ); ?></p>
					</div>
				</div>
			<?php else : ?>

				<?php
				// Sort so 'wordpress' renders first (and is open by default), other
				// integrations follow alphabetically.
				uksort(
					$by_cat,
					static function ( $a, $b ) {
						if ( 'wordpress' === $a ) {
							return -1;
						}
						if ( 'wordpress' === $b ) {
							return 1;
						}
						return strcmp( (string) $a, (string) $b );
					}
				);
				?>

				<?php foreach ( $by_cat as $cat => $cat_actions ) : ?>
					<details class="wbgam-settings-card wbgam-stack-block wbgam-accordion"<?php echo 'wordpress' === $cat ? ' open' : ''; ?>>
						<summary class="wbgam-settings-card__head wbgam-accordion__head">
							<span class="wbgam-accordion__chevron dashicons dashicons-arrow-right" aria-hidden="true"></span>
							<span class="wbgam-accordion__head-text">
								<span class="wbgam-settings-card__title"><?php echo esc_html( strtoupper( $cat_labels[ $cat ] ?? ucfirst( $cat ) ) ); ?></span>
								<span class="wbgam-settings-card__desc">
									<?php
									printf(
										/* translators: %d = number of actions in category */
										esc_html__( '%d actions in this category.', 'wb-gamification' ),
										count( $cat_actions )
									);
									?>
								</span>
							</span>
						</summary>
						<div class="wbgam-settings-card__body">
							<div class="wbgam-table-scroll">
								<table class="wbgam-table wbgam-table-reset wb-gam-settings-table">
									<thead>
									<tr>
										<th class="wb-gam-col-toggle"><?php esc_html_e( 'On', 'wb-gamification' ); ?></th>
										<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
										<th class="wb-gam-col-points"><?php esc_html_e( 'Points', 'wb-gamification' ); ?></th>
										<th class="wb-gam-col-flag"><?php esc_html_e( 'Repeat', 'wb-gamification' ); ?></th>
										<th class="wb-gam-col-flag"><?php esc_html_e( 'Daily cap', 'wb-gamification' ); ?></th>
									</tr>
									</thead>
									<tbody>
									<?php
									foreach ( $cat_actions as $action ) :
										$action_id  = $action['id'];
										$pts        = (int) get_option( 'wb_gam_points_' . $action_id, $action['default_points'] );
										$enabled    = (bool) get_option( 'wb_gam_enabled_' . $action_id, true );
										$repeatable = (bool) ( $action['repeatable'] ?? true );
										$daily_cap  = (int) ( $action['daily_cap'] ?? 0 );
										?>
										<tr>
											<td>
												<label class="wbgam-switch">
													<input
														type="checkbox"
														name="<?php echo esc_attr( 'wb_gam_enabled_' . $action_id ); ?>"
														aria-label="<?php /* translators: %s: gamification action label */ echo esc_attr( sprintf( __( 'Enable %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
														<?php checked( $enabled ); ?>
													>
													<span class="wbgam-switch__track" aria-hidden="true"></span>
												</label>
											</td>
											<td>
												<div class="wbgam-action-cell">
													<strong class="wbgam-action-cell__title"><?php echo esc_html( $action['label'] ?? $action_id ); ?></strong>
													<?php if ( ! empty( $action['description'] ) ) : ?>
														<span class="wbgam-action-cell__desc"><?php echo esc_html( $action['description'] ); ?></span>
													<?php endif; ?>
												</div>
											</td>
											<td>
												<input
													type="number"
													name="<?php echo esc_attr( 'wb_gam_points_' . $action_id ); ?>"
													aria-label="<?php /* translators: %s: gamification action label */ echo esc_attr( sprintf( __( 'Points for %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
													value="<?php echo esc_attr( $pts ); ?>"
													min="0"
													max="9999"
													class="wbgam-input wbgam-input--xs"
												>
											</td>
											<td>
												<?php if ( $repeatable ) : ?>
													<span class="wbgam-pill wbgam-pill--info"><?php esc_html_e( 'Yes', 'wb-gamification' ); ?></span>
												<?php else : ?>
													<span class="wbgam-pill wbgam-pill--inactive"><?php esc_html_e( 'Once', 'wb-gamification' ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $daily_cap > 0 ) : ?>
													<span class="wbgam-pill wbgam-pill--warning"><?php echo esc_html( $daily_cap ); ?></span>
												<?php else : ?>
													<span class="wbgam-pill wbgam-pill--inactive" aria-label="<?php esc_attr_e( 'No daily cap', 'wb-gamification' ); ?>">&infin;</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</details>
				<?php endforeach; ?>

				<div class="wbgam-settings-card">
					<div class="wbgam-settings-card__head">
						<p class="wbgam-settings-card__title"><?php esc_html_e( 'LOG RETENTION', 'wb-gamification' ); ?></p>
						<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Control how long points history is stored.', 'wb-gamification' ); ?></p>
					</div>
					<div class="wbgam-settings-card__body">
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="wb-gam-log-retention-months"><?php esc_html_e( 'Keep points history for', 'wb-gamification' ); ?></label></th>
								<td>
									<input
										type="number"
										name="wb_gam_log_retention_months"
										id="wb-gam-log-retention-months"
										value="<?php echo esc_attr( (int) get_option( 'wb_gam_log_retention_months', 6 ) ); ?>"
										min="1"
										max="24"
										class="wb-gam-input-narrow"
									>
									<?php esc_html_e( 'months', 'wb-gamification' ); ?>
									<p class="description">
										<?php esc_html_e( 'Older rows are pruned daily by WP-Cron. Events table is never pruned (source of truth).', 'wb-gamification' ); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div class="wbgam-settings-section__footer">
					<?php submit_button( __( 'Save Changes', 'wb-gamification' ), 'primary', 'submit', false ); ?>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	// ── Dashboard tab ───────────────────────────────────────────────────────────

	/**
	 * Render the Dashboard overview tab.
	 *
	 * Shows the last-30-day KPI cards from AnalyticsDashboard plus quick-action links.
	 */
	private static function render_dashboard_tab(): void {
		$stats = AnalyticsDashboard::get_stats( 30 );

		// Show first-run welcome card if no points awarded yet and admin hasn't dismissed it.
		$dismissed = get_user_meta( get_current_user_id(), 'wb_gam_dismissed_welcome', true );
		if ( ! $dismissed && 0 === (int) $stats['points_total'] && 0 === (int) $stats['active_members'] ) :
			?>
			<div class="wbgam-settings-card wbgam-stack-block wbgam-card--accent">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Getting Started', 'wb-gamification' ); ?></h3>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wb-gamification&dismiss_welcome=1' ), 'wb_gam_dismiss_welcome' ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary"><?php esc_html_e( 'Dismiss', 'wb-gamification' ); ?></a>
				</div>
				<div class="wbgam-card-body">
					<p><?php esc_html_e( 'Your gamification system is active! Points, badges, and levels will appear here as members interact with your site. Here are some next steps:', 'wb-gamification' ); ?></p>
					<p class="wbgam-quick-nav">
						<a href="#points" class="wbgam-quick-nav__item" data-section="points">
							<span class="dashicons dashicons-star-filled"></span>
							<?php esc_html_e( 'Configure point values', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-point-types' ) ); ?>" class="wbgam-quick-nav__item">
							<span class="dashicons dashicons-tag"></span>
							<?php esc_html_e( 'Add a currency (XP, Coins…)', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>" class="wbgam-quick-nav__item">
							<span class="dashicons dashicons-flag"></span>
							<?php esc_html_e( 'Create a challenge', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>" class="wbgam-quick-nav__item">
							<span class="dashicons dashicons-awards"></span>
							<?php esc_html_e( 'View badge library', 'wb-gamification' ); ?>
						</a>
					</p>
				</div>
			</div>
			<?php
		endif;
		?>
		<div class="wb-gam-admin-kpi-strip">
			<?php
			AnalyticsDashboard::kpi_card(
				__( 'Points Awarded', 'wb-gamification' ),
				number_format_i18n( $stats['points_total'] ),
				__( 'Last 30 days', 'wb-gamification' ),
				'⭐'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Active Members', 'wb-gamification' ),
				number_format_i18n( $stats['active_members'] ),
				sprintf(
					/* translators: %d = total member count */
					__( '%d total members', 'wb-gamification' ),
					$stats['total_members']
				),
				'👥'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Badges Earned', 'wb-gamification' ),
				number_format_i18n( $stats['badges_earned'] ),
				sprintf(
					/* translators: %s = badge earner percentage */
					__( '%s%% of active members', 'wb-gamification' ),
					$stats['badge_earner_pct']
				),
				'🏅'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Challenges Completed', 'wb-gamification' ),
				number_format_i18n( $stats['challenges_completed'] ),
				sprintf(
					/* translators: %s = completion rate percentage */
					__( '%s%% completion rate', 'wb-gamification' ),
					$stats['challenge_completion_pct']
				),
				'🎯'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Active Streaks', 'wb-gamification' ),
				number_format_i18n( $stats['active_streaks'] ),
				sprintf(
					/* translators: %s = streak health percentage */
					__( '%s%% streak health', 'wb-gamification' ),
					$stats['streak_health_pct']
				),
				'🔥'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Kudos Given', 'wb-gamification' ),
				number_format_i18n( $stats['kudos_given'] ),
				__( 'Last 30 days', 'wb-gamification' ),
				'👏'
			);
			?>
		</div>

		<div class="wb-gam-admin-quick-links">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-analytics' ) ); ?>"
				class="button button-primary">
				<?php esc_html_e( 'Full Analytics', 'wb-gamification' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-award' ) ); ?>"
				class="button">
				<?php esc_html_e( 'Award Points', 'wb-gamification' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=points' ) ); ?>"
				class="button">
				<?php esc_html_e( 'Configure Points', 'wb-gamification' ); ?>
			</a>
		</div>
		<?php
	}

	// ── Levels tab ────────────────────────────────────────────────────────────

	/**
	 * Render the Levels settings section (card layout).
	 */
	private static function render_levels_tab(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- settings page, infrequent, small table.
		$levels = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix . literal string.
			"SELECT id, name, min_points, sort_order FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		);
		?>
		<div data-wb-gam-levels-root>
		<div class="wbgam-settings-card">
			<div class="wbgam-settings-card__head">
				<p class="wbgam-settings-card__title"><?php esc_html_e( 'LEVELS', 'wb-gamification' ); ?></p>
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Edit level names and minimum point thresholds. Members move up automatically when they cross a threshold.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<form data-wb-gam-levels-bulk-form>
					<table class="widefat striped wb-gam-levels-table wbgam-table-reset wbgam-table-reset--full">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Level Name', 'wb-gamification' ); ?></th>
							<th class="wb-gam-col-pts-min"><?php esc_html_e( 'Min Points Required', 'wb-gamification' ); ?></th>
							<th class="wbgam-col-actions"></th>
						</tr>
						</thead>
						<tbody data-wb-gam-levels-tbody>
						<?php foreach ( $levels as $level ) : ?>
							<tr data-id="<?php echo (int) $level['id']; ?>">
								<td>
									<input
										type="text"
										data-wb-gam-level-field="name"
										aria-label="<?php esc_attr_e( 'Level name', 'wb-gamification' ); ?>"
										value="<?php echo esc_attr( $level['name'] ); ?>"
										class="wb-gam-input-full"
									>
								</td>
								<td>
									<input
										type="number"
										data-wb-gam-level-field="min_points"
										aria-label="<?php esc_attr_e( 'Level minimum points', 'wb-gamification' ); ?>"
										value="<?php echo esc_attr( $level['min_points'] ); ?>"
										min="0"
										class="wb-gam-input-medium"
										<?php echo 0 === (int) $level['min_points'] ? 'readonly title="' . esc_attr__( 'Starting level is always 0', 'wb-gamification' ) . '"' : ''; ?>
									>
								</td>
								<td>
									<?php if ( (int) $level['min_points'] > 0 ) : ?>
										<button
											type="button"
											class="button button-small button-link-delete"
											data-wb-gam-level-delete="<?php echo (int) $level['id']; ?>"
										>
											<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
										</button>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Starting level', 'wb-gamification' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<div class="wbgam-settings-section__footer wbgam-section__footer--flat">
						<button type="submit" class="button button-primary" data-wb-gam-levels-save>
							<?php esc_html_e( 'Save Levels', 'wb-gamification' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>

		<div class="wbgam-settings-card">
			<div class="wbgam-settings-card__head">
				<p class="wbgam-settings-card__title"><?php esc_html_e( 'ADD NEW LEVEL', 'wb-gamification' ); ?></p>
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Create a new level threshold.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<form data-wb-gam-levels-add-form>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wb-gam-new-level-name"><?php esc_html_e( 'Level Name', 'wb-gamification' ); ?></label></th>
							<td><input type="text" id="wb-gam-new-level-name" name="wb_gam_new_level_name" value="" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Gold', 'wb-gamification' ); ?>" required></td>
						</tr>
						<tr>
							<th scope="row"><label for="wb-gam-new-level-points"><?php esc_html_e( 'Min Points Required', 'wb-gamification' ); ?></label></th>
							<td><input type="number" id="wb-gam-new-level-points" name="wb_gam_new_level_points" value="" min="1" class="wb-gam-input-medium" required>
							<p class="description"><?php esc_html_e( 'Members reach this level when their cumulative points cross this threshold.', 'wb-gamification' ); ?></p></td>
						</tr>
					</table>

					<div class="wbgam-settings-section__footer wbgam-section__footer--flat">
						<button type="submit" class="button button-secondary" data-wb-gam-levels-add>
							<?php esc_html_e( 'Add Level', 'wb-gamification' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		</div>
		<?php
	}

	// ── Automation tab ────────────────────────────────────────────────────────

	/**
	 * Render the Automation settings section (card layout).
	 */
	private static function render_automation_tab(): void {
		global $wpdb;

		$rules  = array();
		$stored = get_option( 'wb_gam_rank_automation_rules', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$rules = $decoded;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- settings page, infrequent, small table.
		$levels = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix . literal string.
			'SELECT id, name FROM ' . $wpdb->prefix . 'wb_gam_levels ORDER BY min_points ASC',
			ARRAY_A
		);

		$action_labels = array(
			'add_bp_group'    => __( 'Add to BuddyPress group', 'wb-gamification' ),
			'send_bp_message' => __( 'Send BuddyPress message', 'wb-gamification' ),
			'change_wp_role'  => __( 'Add WordPress role', 'wb-gamification' ),
		);

		$form_url = admin_url( 'admin.php?page=wb-gamification&tab=automation' );
		?>
		<div class="wbgam-settings-card">
			<div class="wbgam-settings-card__head">
				<p class="wbgam-settings-card__title"><?php esc_html_e( 'RANK AUTOMATION RULES', 'wb-gamification' ); ?></p>
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Automatically trigger actions when a member reaches a level. One action per rule.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<?php if ( $rules ) : ?>
					<table class="widefat striped wb-gam-automation-table wbgam-table-reset">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When member reaches', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Parameters', 'wb-gamification' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $rules as $i => $rule ) :
							$trigger    = (int) ( $rule['trigger_level_id'] ?? 0 );
							$level_name = '';
							foreach ( (array) $levels as $lv ) {
								if ( (int) $lv['id'] === $trigger ) {
									$level_name = $lv['name'];
									break;
								}
							}
							foreach ( (array) ( $rule['actions'] ?? array() ) as $action ) :
								$action_type  = $action['type'] ?? '';
								$action_label = $action_labels[ $action_type ] ?? $action_type;
								$params       = $action;
								unset( $params['type'] );
								?>
								<tr>
									<td><?php echo esc_html( $level_name ?: '#' . $trigger ); ?></td>
									<td><?php echo esc_html( $action_label ); ?></td>
									<td><code><?php echo esc_html( wp_json_encode( $params ) ); ?></code></td>
									<td>
										<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="wb-gam-form-inline" data-wb-gam-confirm="<?php esc_attr_e( 'Delete this rule?', 'wb-gamification' ); ?>">
											<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>
											<input type="hidden" name="wb_gam_automation_action" value="delete" />
											<input type="hidden" name="wb_gam_rule_index" value="<?php echo esc_attr( $i ); ?>" />
											<button type="submit" class="button button-small button-link-delete">
												<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="wbgam-empty-row"><?php esc_html_e( 'No automation rules configured yet.', 'wb-gamification' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="wbgam-settings-card">
			<div class="wbgam-settings-card__head">
				<p class="wbgam-settings-card__title"><?php esc_html_e( 'ADD NEW RULE', 'wb-gamification' ); ?></p>
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Add multiple rules for the same level to stack actions.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<form method="post" action="<?php echo esc_url( $form_url ); ?>">
					<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>
					<input type="hidden" name="wb_gam_automation_action" value="add" />

					<table class="form-table">
						<tr>
							<th scope="row"><label for="wb_gam_new_rule_level"><?php esc_html_e( 'When member reaches level', 'wb-gamification' ); ?></label></th>
							<td>
								<select name="wb_gam_new_rule[trigger_level_id]" id="wb_gam_new_rule_level" required>
									<option value=""><?php esc_html_e( '-- select level --', 'wb-gamification' ); ?></option>
									<?php foreach ( (array) $levels as $lv ) : ?>
										<option value="<?php echo esc_attr( $lv['id'] ); ?>"><?php echo esc_html( $lv['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wb_gam_new_rule_action"><?php esc_html_e( 'Perform action', 'wb-gamification' ); ?></label></th>
							<td>
								<select name="wb_gam_new_rule[action_type]" id="wb_gam_new_rule_action">
									<?php foreach ( $action_labels as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="wb-gam-auto-field-row" data-for="add_bp_group">
							<th scope="row">
								<label for="wb-gam-new-rule-group-id"><?php esc_html_e( 'BuddyPress Group ID', 'wb-gamification' ); ?></label>
							</th>
							<td><input type="number" name="wb_gam_new_rule[group_id]" id="wb-gam-new-rule-group-id" class="small-text" min="0" value="" placeholder="0" />
							<p class="description"><?php esc_html_e( 'The numeric ID of the BP group to add the member to.', 'wb-gamification' ); ?></p></td>
						</tr>
						<tr class="wb-gam-auto-field-row" data-for="change_wp_role">
							<th scope="row">
								<label for="wb-gam-new-rule-role"><?php esc_html_e( 'Role slug', 'wb-gamification' ); ?></label>
							</th>
							<td><input type="text" name="wb_gam_new_rule[role]" id="wb-gam-new-rule-role" class="regular-text" value="" placeholder="contributor" />
							<p class="description"><?php esc_html_e( 'WordPress role slug to add, e.g. "contributor" or "editor".', 'wb-gamification' ); ?></p></td>
						</tr>
						<tr class="wb-gam-auto-field-row" data-for="send_bp_message">
							<th scope="row">
								<label for="wb-gam-new-rule-sender-id"><?php esc_html_e( 'Message sender user ID', 'wb-gamification' ); ?></label>
							</th>
							<td><input type="number" name="wb_gam_new_rule[sender_id]" id="wb-gam-new-rule-sender-id" class="small-text" min="1" value="1" />
							<p class="description"><?php esc_html_e( 'User ID of the sender (usually the site admin, ID 1).', 'wb-gamification' ); ?></p></td>
						</tr>
						<tr class="wb-gam-auto-field-row" data-for="send_bp_message">
							<th scope="row"><label for="wb-gam-new-rule-subject"><?php esc_html_e( 'Message subject', 'wb-gamification' ); ?></label></th>
							<td><input type="text" name="wb_gam_new_rule[subject]" id="wb-gam-new-rule-subject" class="regular-text" value="" /></td>
						</tr>
						<tr class="wb-gam-auto-field-row" data-for="send_bp_message">
							<th scope="row"><label for="wb-gam-new-rule-content"><?php esc_html_e( 'Message content', 'wb-gamification' ); ?></label></th>
							<td><textarea name="wb_gam_new_rule[content]" id="wb-gam-new-rule-content" rows="4" class="large-text"></textarea></td>
						</tr>
					</table>

					<div class="wbgam-settings-section__footer wbgam-section__footer--flat">
						<?php submit_button( __( 'Add Rule', 'wb-gamification' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</div>
		<?php
		// Action-row toggle JS lives at assets/js/admin-rule-action-toggle.js
		// and is enqueued via enqueue_assets() — never inline.
	}

	// ── Kudos section ─────────────────────────────────────────────────────────

	/**
	 * Render the Kudos settings section (card layout).
	 */
	private static function render_kudos_section(): void {
		$daily_limit     = (int) get_option( 'wb_gam_kudos_daily_limit', 5 );
		$receiver_points = (int) get_option( 'wb_gam_kudos_receiver_points', 5 );
		$giver_points    = (int) get_option( 'wb_gam_kudos_giver_points', 2 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=kudos' ) ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

			<div class="wbgam-settings-card">
				<div class="wbgam-settings-card__head">
					<p class="wbgam-settings-card__title"><?php esc_html_e( 'KUDOS', 'wb-gamification' ); ?></p>
					<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Configure peer-to-peer kudos recognition settings.', 'wb-gamification' ); ?></p>
				</div>
				<div class="wbgam-settings-card__body">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wb-gam-kudos-daily-limit"><?php esc_html_e( 'Max kudos per day', 'wb-gamification' ); ?></label></th>
							<td>
								<input type="number" name="wb_gam_kudos_daily_limit" id="wb-gam-kudos-daily-limit" value="<?php echo esc_attr( $daily_limit ); ?>" min="1" max="999" class="wb-gam-input-narrow">
								<p class="description"><?php esc_html_e( 'Maximum number of kudos a member can send per day. Prevents spam.', 'wb-gamification' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wb-gam-kudos-receiver-points"><?php esc_html_e( 'Points per kudos received', 'wb-gamification' ); ?></label></th>
							<td>
								<input type="number" name="wb_gam_kudos_receiver_points" id="wb-gam-kudos-receiver-points" value="<?php echo esc_attr( $receiver_points ); ?>" min="0" max="9999" class="wb-gam-input-narrow">
								<p class="description"><?php esc_html_e( 'Points awarded to the member who receives kudos.', 'wb-gamification' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wb-gam-kudos-giver-points"><?php esc_html_e( 'Points per kudos given', 'wb-gamification' ); ?></label></th>
							<td>
								<input type="number" name="wb_gam_kudos_giver_points" id="wb-gam-kudos-giver-points" value="<?php echo esc_attr( $giver_points ); ?>" min="0" max="9999" class="wb-gam-input-narrow">
								<p class="description"><?php esc_html_e( 'Points awarded to the member who sends kudos. Encourages giving recognition.', 'wb-gamification' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<div class="wbgam-settings-section__footer">
				<?php submit_button( __( 'Save Changes', 'wb-gamification' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	// ── Integrations section ──────────────────────────────────────────────────

	/**
	 * Render the Integrations status section (card layout).
	 *
	 * @param bool $bp_active Whether BuddyPress is active.
	 */
	private static function render_integrations_section( bool $bp_active ): void {
		$integrations = array(
			array(
				'name'   => 'BuddyPress',
				'active' => $bp_active,
				'desc'   => __( 'Social points, badge notifications, profile display, and activity triggers.', 'wb-gamification' ),
			),
			array(
				'name'   => 'WooCommerce',
				'active' => class_exists( 'WooCommerce' ),
				'desc'   => __( 'Points for purchases, reviews, and product interactions.', 'wb-gamification' ),
			),
			array(
				'name'   => 'LearnDash',
				'active' => defined( 'LEARNDASH_VERSION' ),
				'desc'   => __( 'Points for course completion, lesson progress, and quiz scores.', 'wb-gamification' ),
			),
			array(
				'name'   => 'bbPress',
				'active' => class_exists( 'bbPress' ),
				'desc'   => __( 'Points for forum topics, replies, and helpful answers.', 'wb-gamification' ),
			),
			array(
				'name'   => 'Elementor',
				'active' => defined( 'ELEMENTOR_VERSION' ),
				'desc'   => __( 'Gamification widgets for Elementor page builder.', 'wb-gamification' ),
			),
		);
		?>
		<div class="wbgam-settings-card">
			<div class="wbgam-settings-card__head">
				<p class="wbgam-settings-card__title"><?php esc_html_e( 'INTEGRATION STATUS', 'wb-gamification' ); ?></p>
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Integrations are auto-detected. Install and activate a plugin to enable its triggers.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<table class="widefat striped wbgam-table-reset">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wb-gamification' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $integrations as $int ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $int['name'] ); ?></strong></td>
								<td>
									<?php if ( $int['active'] ) : ?>
										<span class="wbgam-pill wbgam-pill--active"><span class="wbgam-pill-dot"></span><?php esc_html_e( 'Active', 'wb-gamification' ); ?></span>
									<?php else : ?>
										<span class="wbgam-pill wbgam-pill--neutral"><span class="wbgam-pill-dot"></span><?php esc_html_e( 'Inactive', 'wb-gamification' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="wb-gam-action-desc"><?php echo esc_html( $int['desc'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Mode badge ────────────────────────────────────────────────────────────

	/**
	 * Render the standalone/community mode badge in the page header.
	 */
	private static function render_mode_badge(): void {
		$bp_active = function_exists( 'buddypress' );

		if ( $bp_active ) {
			$mode    = __( 'Community Mode', 'wb-gamification' );
			$tooltip = __( 'BuddyPress is active — social points, badge notifications, and activity triggers are enabled.', 'wb-gamification' );
		} else {
			$mode    = __( 'Standalone Mode', 'wb-gamification' );
			$tooltip = __( 'Running without BuddyPress — points and badges work normally; social features require BuddyPress to be installed and active.', 'wb-gamification' );
		}

		$modifier = $bp_active ? 'community' : 'standalone';
		printf(
			'<span class="wb-gam-mode-badge wb-gam-mode-badge--%s" title="%s">%s</span>',
			esc_attr( $modifier ),
			esc_attr( $tooltip ),
			esc_html( $mode )
		);
	}
}
