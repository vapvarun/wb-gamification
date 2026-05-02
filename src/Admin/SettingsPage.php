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
		add_action( 'admin_post_wb_gam_save_levels', array( __CLASS__, 'handle_save_levels' ) );
		add_action( 'admin_post_wb_gam_delete_level', array( __CLASS__, 'handle_delete_level' ) );
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
	 * Handle the Levels tab form submission (admin-post.php action).
	 * Supports two operations via the wb_gam_level_op field:
	 *   - 'update' (default): save name/min_points for all existing levels
	 *   - 'add': insert a new level row
	 */
	public static function handle_save_levels(): void {
		check_admin_referer( 'wb_gam_save_levels', 'wb_gam_levels_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wb-gamification' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_levels';

		$op = isset( $_POST['wb_gam_level_op'] ) ? sanitize_key( wp_unslash( $_POST['wb_gam_level_op'] ) ) : 'update';

		if ( 'add' === $op ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$new_name   = sanitize_text_field( wp_unslash( $_POST['wb_gam_new_level_name'] ?? '' ) );
			$new_points = max( 1, absint( wp_unslash( $_POST['wb_gam_new_level_points'] ?? 0 ) ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( $new_name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT; caching not applicable.
				$max_sort = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order),0) FROM {$wpdb->prefix}wb_gam_levels" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->insert(
					$table,
					array(
						'name'       => $new_name,
						'min_points' => $new_points,
						'sort_order' => $max_sort + 1,
					),
					array( '%s', '%d', '%d' )
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification&tab=levels&saved=1' ) );
			exit;
		}

		// Default: process updates for existing levels.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized individually inside the loop.
		if ( ! empty( $_POST['wb_gam_level'] ) && is_array( $_POST['wb_gam_level'] ) ) {
			foreach ( (array) wp_unslash( $_POST['wb_gam_level'] ) as $id => $data ) {
				$id         = (int) $id;
				$name       = sanitize_text_field( wp_unslash( $data['name'] ?? '' ) );
				$min_points = max( 0, (int) ( $data['min_points'] ?? 0 ) );

				if ( ! $id || ! $name ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE statement; caching not applicable.
				$wpdb->update(
					$table,
					array(
						'name'       => $name,
						'min_points' => $min_points,
					),
					array( 'id' => $id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification&tab=levels&saved=1' ) );
		exit;
	}

	/**
	 * Handle level deletion via admin-post.php (GET link with nonce).
	 */
	public static function handle_delete_level(): void {
		$level_id = isset( $_GET['level_id'] ) ? absint( wp_unslash( $_GET['level_id'] ) ) : 0;
		check_admin_referer( 'wb_gam_delete_level_' . $level_id );

		if ( ! current_user_can( 'manage_options' ) || $level_id <= 0 ) {
			wp_die( esc_html__( 'Unauthorized.', 'wb-gamification' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_levels';

		// Protect the starting level (min_points = 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row check before delete.
		$min_pts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT min_points FROM {$table} WHERE id = %d", $level_id ) );
		if ( $min_pts > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE; caching not applicable.
			$wpdb->delete( $table, array( 'id' => $level_id ), array( '%d' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification&tab=levels&saved=1' ) );
		exit;
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

		// Dashboard tab redirects to the dedicated analytics page.
		if ( 'dashboard' === $tab ) {
			self::render_dashboard_page();
			return;
		}

		wp_enqueue_script(
			'wbgam-settings-nav',
			WB_GAM_URL . 'assets/js/settings-nav.js',
			array(),
			WB_GAM_VERSION,
			true
		);

		$bp_active = function_exists( 'buddypress' );
		?>
		<div class="wbgam-settings-wrap">

			<!-- Sidebar -->
			<div class="wbgam-settings-sidebar">
				<div class="wbgam-settings-sidebar__brand">
					<span class="wbgam-settings-sidebar__logo dashicons dashicons-awards"></span>
					<div>
						<strong><?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?></strong>
						<span><?php esc_html_e( 'SETTINGS', 'wb-gamification' ); ?></span>
					</div>
				</div>

				<!-- CORE -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Core', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=dashboard' ) ); ?>">
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
					<div class="notice notice-success is-dismissible wb-gam-notice"><p><?php esc_html_e( 'Settings saved.', 'wb-gamification' ); ?></p></div>
				<?php endif; ?>
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
				<?php if ( isset( $_GET['setup'] ) && 'complete' === $_GET['setup'] ) : ?>
					<div class="notice notice-success is-dismissible wb-gam-notice"><p><?php esc_html_e( 'Setup complete! Review your point values below.', 'wb-gamification' ); ?></p></div>
				<?php endif; ?>

				<?php settings_errors( 'wb_gamification' ); ?>

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
		<div class="wrap" id="wb-gam-settings">
			<div class="wb-gam-admin-header">
				<h1><?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?></h1>
				<?php self::render_mode_badge(); ?>
			</div>
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
					<div class="wbgam-settings-card__body" style="padding: 24px 20px;">
						<p><?php esc_html_e( 'No gamification actions are registered yet. Triggers load automatically once BuddyPress or other integrations are active.', 'wb-gamification' ); ?></p>
					</div>
				</div>
			<?php else : ?>

				<?php foreach ( $by_cat as $cat => $cat_actions ) : ?>
					<div class="wbgam-settings-card">
						<div class="wbgam-settings-card__head">
							<p class="wbgam-settings-card__title"><?php echo esc_html( strtoupper( $cat_labels[ $cat ] ?? ucfirst( $cat ) ) ); ?></p>
							<p class="wbgam-settings-card__desc">
								<?php
								printf(
									/* translators: %d = number of actions in category */
									esc_html__( '%d actions in this category.', 'wb-gamification' ),
									count( $cat_actions )
								);
								?>
							</p>
						</div>
						<div class="wbgam-settings-card__body">
							<table class="widefat striped wb-gam-settings-table" style="border:none;box-shadow:none;">
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
											<input
												type="checkbox"
												name="<?php echo esc_attr( 'wb_gam_enabled_' . $action_id ); ?>"
												aria-label="<?php /* translators: %s: gamification action label */ echo esc_attr( sprintf( __( 'Enable %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
												<?php checked( $enabled ); ?>
											>
										</td>
										<td>
											<strong><?php echo esc_html( $action['label'] ?? $action_id ); ?></strong>
											<?php if ( ! empty( $action['description'] ) ) : ?>
												<br><span class="wb-gam-action-desc"><?php echo esc_html( $action['description'] ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<input
												type="number"
												name="<?php echo esc_attr( 'wb_gam_points_' . $action_id ); ?>"
												aria-label="<?php /* translators: %s: gamification action label */ echo esc_attr( sprintf( __( 'Points for %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
												value="<?php echo esc_attr( $pts ); ?>"
												min="0"
												max="9999"
												class="wb-gam-input-narrow"
											>
										</td>
										<td class="wb-gam-action-desc">
											<?php echo $repeatable ? esc_html__( 'Yes', 'wb-gamification' ) : esc_html__( 'Once', 'wb-gamification' ); ?>
										</td>
										<td class="wb-gam-action-desc">
											<?php echo $daily_cap > 0 ? esc_html( $daily_cap ) : '&infin;'; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
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
			<div class="wbgam-settings-card" style="margin-bottom:24px;border-left:4px solid var(--wbgam-primary, #2563eb);">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Getting Started', 'wb-gamification' ); ?></h3>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wb-gamification&dismiss_welcome=1' ), 'wb_gam_dismiss_welcome' ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary"><?php esc_html_e( 'Dismiss', 'wb-gamification' ); ?></a>
				</div>
				<div class="wbgam-card-body">
					<p><?php esc_html_e( 'Your gamification system is active! Points, badges, and levels will appear here as members interact with your site. Here are some next steps:', 'wb-gamification' ); ?></p>
					<p style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
						<a href="#points" class="wbgam-settings-nav-item" data-section="points" style="border:1px solid var(--wbgam-border, #e5e7eb);border-radius:6px;padding:8px 16px;text-decoration:none;">
							<span class="dashicons dashicons-star-filled"></span>
							<?php esc_html_e( 'Configure point values', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>" style="border:1px solid var(--wbgam-border, #e5e7eb);border-radius:6px;padding:8px 16px;text-decoration:none;display:flex;align-items:center;gap:8px;color:var(--wbgam-text, #1e1e1e);">
							<span class="dashicons dashicons-flag"></span>
							<?php esc_html_e( 'Create a challenge', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>" style="border:1px solid var(--wbgam-border, #e5e7eb);border-radius:6px;padding:8px 16px;text-decoration:none;display:flex;align-items:center;gap:8px;color:var(--wbgam-text, #1e1e1e);">
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
		<div class="wbgam-settings-card">
			<div class="wbgam-settings-card__head">
				<p class="wbgam-settings-card__title"><?php esc_html_e( 'LEVELS', 'wb-gamification' ); ?></p>
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Edit level names and minimum point thresholds. Members move up automatically when they cross a threshold.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wb_gam_save_levels">
					<?php wp_nonce_field( 'wb_gam_save_levels', 'wb_gam_levels_nonce' ); ?>

					<table class="widefat striped wb-gam-levels-table" style="border:none;box-shadow:none;max-width:100%;">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Level Name', 'wb-gamification' ); ?></th>
							<th class="wb-gam-col-pts-min"><?php esc_html_e( 'Min Points Required', 'wb-gamification' ); ?></th>
							<th style="width:80px"></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $levels as $level ) : ?>
							<tr>
								<td>
									<input
										type="text"
										name="wb_gam_level[<?php echo esc_attr( $level['id'] ); ?>][name]"
										aria-label="<?php esc_attr_e( 'Level name', 'wb-gamification' ); ?>"
										value="<?php echo esc_attr( $level['name'] ); ?>"
										class="wb-gam-input-full"
									>
								</td>
								<td>
									<input
										type="number"
										name="wb_gam_level[<?php echo esc_attr( $level['id'] ); ?>][min_points]"
										aria-label="<?php esc_attr_e( 'Level minimum points', 'wb-gamification' ); ?>"
										value="<?php echo esc_attr( $level['min_points'] ); ?>"
										min="0"
										class="wb-gam-input-medium"
										<?php echo 0 === (int) $level['min_points'] ? 'readonly title="' . esc_attr__( 'Starting level is always 0', 'wb-gamification' ) . '"' : ''; ?>
									>
								</td>
								<td>
									<?php if ( (int) $level['min_points'] > 0 ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wb_gam_delete_level&level_id=' . $level['id'] ), 'wb_gam_delete_level_' . $level['id'] ) ); ?>"
											class="button button-small button-link-delete"
											onclick="return confirm('<?php esc_attr_e( 'Delete this level?', 'wb-gamification' ); ?>')">
											<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
										</a>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Starting level', 'wb-gamification' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<div class="wbgam-settings-section__footer" style="border:none;margin-top:0;">
						<?php submit_button( __( 'Save Levels', 'wb-gamification' ), 'primary', 'submit', false ); ?>
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wb_gam_save_levels">
					<input type="hidden" name="wb_gam_level_op" value="add">
					<?php wp_nonce_field( 'wb_gam_save_levels', 'wb_gam_levels_nonce' ); ?>

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

					<div class="wbgam-settings-section__footer" style="border:none;margin-top:0;">
						<?php submit_button( __( 'Add Level', 'wb-gamification' ), 'secondary', 'submit', false ); ?>
					</div>
				</form>
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
					<table class="widefat striped wb-gam-automation-table" style="border:none;box-shadow:none;">
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
										<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="wb-gam-form-inline">
											<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>
											<input type="hidden" name="wb_gam_automation_action" value="delete" />
											<input type="hidden" name="wb_gam_rule_index" value="<?php echo esc_attr( $i ); ?>" />
											<button type="submit" class="button button-small button-link-delete"
												onclick="return confirm('<?php esc_attr_e( 'Delete this rule?', 'wb-gamification' ); ?>')">
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
					<p style="padding: 20px; color: #757575;"><?php esc_html_e( 'No automation rules configured yet.', 'wb-gamification' ); ?></p>
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

					<div class="wbgam-settings-section__footer" style="border:none;margin-top:0;">
						<?php submit_button( __( 'Add Rule', 'wb-gamification' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</div>
		<script>
		(function () {
			var sel = document.getElementById( 'wb_gam_new_rule_action' );
			if ( ! sel ) { return; }
			function toggle() {
				var val = sel.value;
				document.querySelectorAll( '.wb-gam-auto-field-row' ).forEach( function ( row ) {
					row.style.display = ( row.dataset.for === val ) ? '' : 'none';
				} );
			}
			sel.addEventListener( 'change', toggle );
			toggle();
		}());
		</script>
		<?php
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
				<table class="widefat striped" style="border:none;box-shadow:none;">
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
