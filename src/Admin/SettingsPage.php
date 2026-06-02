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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

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
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss_checklist' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_page_css' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_levels_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_settings_toggles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_test_event' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_emails_form' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_tools_assets' ) );
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
		} elseif ( 'realtime' === $tab ) {
			self::save_realtime_settings();
		} elseif ( 'access' === $tab ) {
			self::save_access_settings();
		} elseif ( 'modules' === $tab ) {
			self::save_modules_settings();
		}

		// Preserve the active sidebar section after save (Basecamp 9925119779).
		// Sidebar nav uses URL hash (#points, #kudos, #emails…) but the form
		// action hardcodes ?tab=…; without a hash on the redirect target the
		// JS picks the first sidebar item and the admin lands on Dashboard.
		// The settings-nav.js submit handler stamps `_wp_http_referer` with
		// the current hash, so we redirect back to that referer instead of
		// silently rendering the same URL. Browsers honour the `#section` in
		// Location headers (RFC 7231 §7.1.2).
		$tab_to_hash = array(
			'points'     => 'points',
			'kudos'      => 'kudos',
			'automation' => 'rules',
			'realtime'   => 'realtime',
			'access'     => 'access',
			'modules'    => 'modules',
		);
		$fallback    = admin_url( 'admin.php?page=wb-gamification' );
		if ( isset( $tab_to_hash[ $tab ] ) ) {
			$fallback .= '#' . $tab_to_hash[ $tab ];
		}
		// Nonce verified above via check_admin_referer; the referer is
		// passed through wp_validate_redirect() before any use.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$referer    = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : '';
		$target_url = $fallback;
		if ( is_string( $referer ) && '' !== $referer ) {
			$candidate = wp_validate_redirect( $referer, '' );
			if ( '' !== $candidate ) {
				$target_url = $candidate;
			}
		}
		// settings_errors stash so the success notice survives the redirect.
		set_transient(
			'wb_gam_settings_saved_' . get_current_user_id(),
			array( 'tab' => $tab ),
			60
		);
		wp_safe_redirect( $target_url );
		exit;
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
	 * Dismiss the setup-progress checklist for the current admin.
	 *
	 * Separate from the welcome card — admins may want to keep "Getting
	 * Started" visible while hiding the checklist (or vice versa).
	 */
	public static function handle_dismiss_checklist(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		if ( empty( $_GET['dismiss_checklist'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wb_gam_dismiss_checklist' ) ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), 'wb_gam_dismissed_checklist', 1 );
			wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification' ) );
			exit;
		}
	}

	/**
	 * Compute setup-checklist step state from live site data.
	 *
	 * Each step queries the site (option, meta, table count) so admins
	 * never need to manually mark a step done — it self-checks. Returns
	 * a list of associative arrays consumed by the dashboard render.
	 *
	 * @param int $hub_page_id Current hub page ID (0 if not yet auto-created).
	 * @param int $points_total Last-30-days point total from analytics.
	 * @return array<int, array{title:string, desc:string, done:bool, action_url?:string, action_label?:string, action_target?:string}>
	 */
	private static function compute_setup_checklist( int $hub_page_id, int $points_total ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- One small COUNT for setup-checklist UI; not a hot path.
		$badge_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_badge_defs" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Same.
		$level_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_levels" );

		$wizard_done = (bool) get_option( 'wb_gam_wizard_complete' );
		$template    = (string) get_option( 'wb_gam_template', '' );

		$hub_url  = $hub_page_id ? get_permalink( $hub_page_id ) : '';
		$hub_edit = $hub_page_id ? get_edit_post_link( $hub_page_id ) : '';

		return array(
			array(
				'title'        => __( 'Run the Setup Wizard', 'wb-gamification' ),
				'desc'         => __( 'Pick a starter template so points + leaderboard are pre-configured for your community.', 'wb-gamification' ),
				'done'         => $wizard_done && '' !== $template,
				'action_url'   => admin_url( 'admin.php?page=wb-gamification-setup' ),
				'action_label' => __( 'Run wizard', 'wb-gamification' ),
			),
			array(
				'title'        => __( 'Define your levels', 'wb-gamification' ),
				'desc'         => __( 'Levels turn point thresholds into named milestones (e.g. Newcomer → Regular → Expert).', 'wb-gamification' ),
				'done'         => $level_count > 0,
				'action_url'   => admin_url( 'admin.php?page=wb-gamification#levels' ),
				'action_label' => __( 'Add levels', 'wb-gamification' ),
			),
			array(
				'title'        => __( 'Build your badge library', 'wb-gamification' ),
				'desc'         => __( 'Badges are the visible rewards - earned on milestones, streaks, or specific actions.', 'wb-gamification' ),
				'done'         => $badge_count > 0,
				'action_url'   => admin_url( 'admin.php?page=wb-gamification-badges' ),
				'action_label' => __( 'Add badges', 'wb-gamification' ),
			),
			array(
				'title'        => __( 'Place the Hub block on a page', 'wb-gamification' ),
				'desc'         => $hub_url
					? sprintf(
						/* translators: %s: relative URL to the hub page */
						__( 'Auto-created at %s. Edit the page to customize the layout.', 'wb-gamification' ),
						wp_make_link_relative( $hub_url )
					)
					: __( 'Auto-creates on activation; if you deleted the page, place [wb_gam_hub] on any page.', 'wb-gamification' ),
				'done'         => $hub_page_id > 0,
				'action_url'   => $hub_edit ?: admin_url( 'edit.php?post_type=page' ),
				'action_label' => $hub_edit ? __( 'Edit Hub page', 'wb-gamification' ) : __( 'Create page', 'wb-gamification' ),
			),
			array(
				'title'         => __( 'See points flow end-to-end', 'wb-gamification' ),
				'desc'          => __( 'Click "Send a test event" above (or do a real action like publishing a post) to confirm the engine fires.', 'wb-gamification' ),
				'done'          => $points_total > 0,
				'action_url'    => $hub_url ?: admin_url( 'admin.php?page=wb-gamification' ),
				'action_label'  => __( 'Open Hub', 'wb-gamification' ),
				'action_target' => '_blank',
			),
		);
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
		$actions    = Registry::get_actions();
		$pt_service = new \WBGam\Services\PointTypeService();

		foreach ( $actions as $action_id => $action ) {
			$key    = 'wb_gam_points_' . sanitize_key( $action_id );
			$points = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( null !== $points && $points >= 0 ) {
				update_option( 'wb_gam_points_' . $action_id, $points );
			}

			$enabled_key = 'wb_gam_enabled_' . sanitize_key( $action_id );
			update_option( 'wb_gam_enabled_' . $action_id, isset( $_POST[ $enabled_key ] ) ? true : false );

			// Per-action currency override. Stored only when set and known —
			// PointTypeService::resolve() falls back to primary for any
			// unknown slug, so we don't write garbage. Empty submit clears
			// the override (action falls back to manifest declaration).
			$type_key = 'wb_gam_point_type_' . sanitize_key( $action_id );
			if ( isset( $_POST[ $type_key ] ) ) {
				$raw_type = sanitize_key( wp_unslash( $_POST[ $type_key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( '' === $raw_type ) {
					delete_option( 'wb_gam_point_type_' . $action_id );
				} elseif ( $pt_service->get( $raw_type ) ) {
					update_option( 'wb_gam_point_type_' . $action_id, $raw_type );
				}
			}
		}

		// Also save log retention.
		if ( isset( $_POST['wb_gam_log_retention_months'] ) ) {
			$months = max( 1, min( 24, absint( wp_unslash( $_POST['wb_gam_log_retention_months'] ) ) ) );
			update_option( 'wb_gam_log_retention_months', $months );
		}

		// Point expiry / inactivity decay (opt-in).
		update_option( 'wb_gam_points_decay_enabled', isset( $_POST['wb_gam_points_decay_enabled'] ) ? 1 : 0 );
		if ( isset( $_POST['wb_gam_points_decay_days'] ) ) {
			update_option( 'wb_gam_points_decay_days', max( 1, min( 3650, absint( wp_unslash( $_POST['wb_gam_points_decay_days'] ) ) ) ) );
		}
		if ( isset( $_POST['wb_gam_points_decay_percent'] ) ) {
			update_option( 'wb_gam_points_decay_percent', max( 1, min( 100, absint( wp_unslash( $_POST['wb_gam_points_decay_percent'] ) ) ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_settings_error( 'wb_gamification', 'saved', __( 'Settings saved.', 'wb-gamification' ), 'success' );
	}

	/**
	 * Persist kudos engine settings.
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	/**
	 * Persist the Realtime transport selection.
	 *
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	private static function save_realtime_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$valid = array(
			\WBGam\API\SSEController::TRANSPORT_HEARTBEAT,
			\WBGam\API\SSEController::TRANSPORT_SSE,
			\WBGam\API\SSEController::TRANSPORT_AUTO,
		);
		$raw   = isset( $_POST['wb_gam_realtime_transport'] ) ? sanitize_key( wp_unslash( $_POST['wb_gam_realtime_transport'] ) ) : '';
		if ( in_array( $raw, $valid, true ) ) {
			update_option( \WBGam\API\SSEController::TRANSPORT_OPTION, $raw );
		}

		// Toast stack position — validated against the allowed set so the
		// value can only ever map to a known .wb-gam-toasts--* CSS modifier.
		$position = isset( $_POST['wb_gam_toast_position'] ) ? sanitize_key( wp_unslash( $_POST['wb_gam_toast_position'] ) ) : '';
		if ( in_array( $position, \WBGam\Engine\NotificationBridge::TOAST_POSITIONS, true ) ) {
			update_option( \WBGam\Engine\NotificationBridge::TOAST_POSITION_OPTION, $position );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_settings_error( 'wb_gamification', 'saved', __( 'Realtime settings saved.', 'wb-gamification' ), 'success' );
	}

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
	 * Enqueue the per-page settings.css bundle on the Settings page only.
	 *
	 * The global tokens / components / utilities / suppression sheets are
	 * enqueued by `WB_Gamification::enqueue_admin_assets`; this method adds
	 * the page-specific overrides on top.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_page_css( string $hook_suffix ): void {
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wb-gam-page-settings',
			plugins_url( 'assets/css/admin/pages/settings.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
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
		// Settings page navigates by URL hash (`#levels`), not `?tab=levels`,
		// so PHP cannot tell at render time which sidebar section the admin is
		// looking at. The old gate on $_GET['tab'] === 'levels' meant the
		// Levels JS never loaded — Add/Save/Delete buttons just navigated to
		// `#levels` without doing anything. Always enqueue on the settings
		// page; the script is small (≈ 12 KB) and only binds to its own
		// data-attrs so it is inert when the Levels section is hidden.

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
					'aria_name'       => __( 'Level name', 'wb-gamification' ),
					'aria_points'     => __( 'Level minimum points', 'wb-gamification' ),
					'starting_locked' => __( 'Starting level is always 0', 'wb-gamification' ),
					'starting_level'  => __( 'Starting level', 'wb-gamification' ),
					'delete'          => __( 'Delete', 'wb-gamification' ),
					'saved'           => __( 'Levels saved.', 'wb-gamification' ),
					'save_failed'     => __( 'Some levels failed to save.', 'wb-gamification' ),
					'added'           => __( 'Level added.', 'wb-gamification' ),
					'add_failed'      => __( 'Failed to add level.', 'wb-gamification' ),
					'add_invalid'     => __( 'Provide a name and points value.', 'wb-gamification' ),
					'deleted'         => __( 'Level deleted.', 'wb-gamification' ),
					'delete_failed'   => __( 'Failed to delete level.', 'wb-gamification' ),
					'confirm_delete'  => __( 'Delete this level?', 'wb-gamification' ),
					'refresh_failed'  => __( 'Failed to load levels.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Enqueue the settings import/export script for the Tools section.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_tools_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
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
			'wb-gam-admin-tools',
			plugins_url( 'assets/js/admin-tools.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-tools',
			'wbGamTools',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'exporting'      => __( 'Preparing export...', 'wb-gamification' ),
					'exportError'    => __( 'Export failed.', 'wb-gamification' ),
					'importError'    => __( 'Import failed. Check that the file is a WB Gamification settings export.', 'wb-gamification' ),
					'importConfirm'  => __( 'Import these settings? This overwrites the matching settings on this site.', 'wb-gamification' ),
					/* translators: 1: applied count, 2: skipped count */
					'imported'       => __( 'Imported %1$d settings (%2$d skipped). Reloading...', 'wb-gamification' ),
					'noFile'         => __( 'Choose an export file first.', 'wb-gamification' ),
					'recomputing'    => __( 'Rebuilding leaderboard...', 'wb-gamification' ),
					'recomputed'     => __( 'Leaderboard rebuilt.', 'wb-gamification' ),
					'recomputeError' => __( 'Could not rebuild the leaderboard.', 'wb-gamification' ),
					'resetConfirm'   => __( 'Permanently delete ALL member progress (points, badges, streaks, kudos, leaderboards)? Configuration is kept. This cannot be undone.', 'wb-gamification' ),
					'resetting'      => __( 'Resetting member progress...', 'wb-gamification' ),
					/* translators: %d: number of data tables cleared */
					'resetDone'      => __( 'Member progress reset (%d tables cleared). Reloading...', 'wb-gamification' ),
					'resetError'     => __( 'Could not reset member progress.', 'wb-gamification' ),
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
	/**
	 * Enqueue the "Send a test event" button script on the Dashboard tab.
	 *
	 * Only fires when the first-run welcome card is actually rendered (no
	 * points awarded yet AND admin hasn't dismissed it) — avoids loading the
	 * script on every Settings page view forever.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	/**
	 * Enqueue the generic REST form driver + wbGamSettings localisation for
	 * the Emails section toggles.
	 *
	 * The Emails form uses the data-wb-gam-rest-form attribute pattern shared
	 * with WebhooksAdminPage / ManualAwardPage, but the JS driver was never
	 * enqueued on this page — without it the toggle form fell back to a
	 * native GET submit that wiped the URL and never saved any toggle
	 * (Basecamp 9925227946 / 9925205802 Issue 2).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_emails_form( string $hook_suffix ): void {
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
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
			'wb-gam-admin-rest-form',
			plugins_url( 'assets/js/admin-rest-form.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-rest-form',
			'wbGamSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Settings saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save settings.', 'wb-gamification' ),
				),
			)
		);
	}

	public static function enqueue_test_event( string $hook_suffix ): void {
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
			return;
		}

		$dismissed = get_user_meta( get_current_user_id(), 'wb_gam_dismissed_welcome', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only force flag for dev browser-test.
		$force = isset( $_GET['wb_gam_force_welcome'] ) && '1' === $_GET['wb_gam_force_welcome'];
		if ( $dismissed && ! $force ) {
			return;
		}

		$stats = AnalyticsDashboard::get_stats( 30 );
		if ( ! $force && ( (int) $stats['points_total'] > 0 || (int) $stats['active_members'] > 0 ) ) {
			return;
		}

		wp_enqueue_script(
			'wb-gam-admin-test-event',
			plugins_url( 'assets/js/admin-test-event.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);

		$hub_page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		$hub_url     = $hub_page_id ? get_permalink( $hub_page_id ) : '';

		wp_localize_script(
			'wb-gam-admin-test-event',
			'wbGamTestEvent',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => get_current_user_id(),
				'hubUrl'  => esc_url_raw( $hub_url ),
				'i18n'    => array(
					'sending' => __( 'Awarding…', 'wb-gamification' ),
					'success' => __( 'Test event sent. Visit your Hub to see the welcome toast.', 'wb-gamification' ),
					'viewHub' => __( 'Open Hub', 'wb-gamification' ),
					'error'   => __( 'Could not send test event. Check the error log.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Enqueue the per-action overrides autosave script.
	 *
	 * Inline-rendered next to the Points-tab actions table on settings
	 * render. The script handle is registered once; the localised data
	 * (REST base URL + nonce) is passed via wp_localize_script.
	 *
	 * @return void
	 */
	private static function enqueue_action_overrides_script(): void {
		$handle = 'wb-gam-admin-action-overrides';
		wp_enqueue_script(
			$handle,
			plugins_url( 'assets/js/admin-action-overrides.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'wbGamActionOverrides',
			array(
				'restBase' => rest_url( 'wb-gamification/v1/actions/' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function enqueue_settings_toggles( string $hook_suffix ): void {
		if ( 'toplevel_page_wb-gamification' !== $hook_suffix ) {
			return;
		}
		// Settings page is hash-routed (#rules), so the old `?tab=automation`
		// gate stopped the action-row toggle JS from ever loading and the
		// rule form's per-action context fields (BP group id, role slug,
		// message subject/content) never appeared on dropdown change
		// (Basecamp 9925298656 issue 1). Always enqueue — the script is a
		// no-op if the rules form isn't on the page.

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
		<div class="wrap wbgam-wrap" id="wb-gam-settings">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header wbgam-settings-topbar">
				<div class="wbgam-settings-topbar__brand">
					<span class="wbgam-settings-topbar__logo icon-award" aria-hidden="true"></span>
					<div class="wbgam-settings-topbar__text">
						<h1 class="wbgam-settings-topbar__title">
							<?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?>
							<span class="wbgam-settings-topbar__version">v<?php echo esc_html( WB_GAM_VERSION ); ?></span>
						</h1>
						<p class="wbgam-settings-topbar__desc">
							<?php esc_html_e( 'Points, badges, levels, leaderboards, challenges and streaks - configure your community gamification engine.', 'wb-gamification' ); ?>
						</p>
					</div>
				</div>
				<div class="wbgam-settings-topbar__actions">
					<?php
					// Hide the wizard-launch CTA once setup is completed — site owners
					// who finished onboarding don't need the redundant "Run Setup
					// Wizard" prompt every time they open Settings (Basecamp
					// #9925205802 issue 3). They can still re-run the wizard by
					// visiting the URL directly; we just don't surface it here.
					$wb_gam_wizard_complete = (bool) get_option( SetupWizard::COMPLETED_OPTION );
					if ( ! $wb_gam_wizard_complete ) :
						?>
						<a class="wbgam-btn wbgam-btn--secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-setup' ) ); ?>">
							<span class="icon-settings" aria-hidden="true"></span>
							<?php esc_html_e( 'Run Setup Wizard', 'wb-gamification' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</header>
		<div class="wbgam-settings-wrap">

			<!-- Sidebar -->
			<div class="wbgam-settings-sidebar">

				<!-- CORE -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Core', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="#dashboard" data-section="dashboard">
						<span class="icon-layout-dashboard"></span>
						<?php esc_html_e( 'Dashboard', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#points" data-section="points">
						<span class="icon-star"></span>
						<?php esc_html_e( 'Points', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#levels" data-section="levels">
						<span class="icon-chart-bar"></span>
						<?php esc_html_e( 'Levels', 'wb-gamification' ); ?>
					</a>
				</div>

				<!-- ENGAGEMENT -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Engagement', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>">
						<span class="icon-flag"></span>
						<?php esc_html_e( 'Challenges', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>">
						<span class="icon-shield"></span>
						<?php esc_html_e( 'Badges', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#kudos" data-section="kudos">
						<span class="icon-thumbs-up"></span>
						<?php esc_html_e( 'Kudos', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#cohort" data-section="cohort">
						<span class="icon-users"></span>
						<?php esc_html_e( 'Cohort Leagues', 'wb-gamification' ); ?>
					</a>
				</div>

				<!-- AUTOMATION -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Automation', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="#rules" data-section="rules">
						<span class="icon-refresh-cw"></span>
						<?php esc_html_e( 'Rules', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#emails" data-section="emails">
						<span class="icon-mail"></span>
						<?php esc_html_e( 'Emails', 'wb-gamification' ); ?>
					</a>
				</div>

				<!-- ADVANCED -->
				<div class="wbgam-settings-nav-group">
					<span class="wbgam-settings-nav-group__label"><?php esc_html_e( 'Advanced', 'wb-gamification' ); ?></span>
					<a class="wbgam-settings-nav-item" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-api-keys' ) ); ?>">
						<span class="icon-network"></span>
						<?php esc_html_e( 'API Keys', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#access" data-section="access">
						<span class="icon-user-x"></span>
						<?php esc_html_e( 'Access', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#realtime" data-section="realtime">
						<span class="icon-zap"></span>
						<?php esc_html_e( 'Realtime', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#integrations" data-section="integrations">
						<span class="icon-link"></span>
						<?php esc_html_e( 'Integrations', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#modules" data-section="modules">
						<span class="icon-sliders"></span>
						<?php esc_html_e( 'Modules', 'wb-gamification' ); ?>
					</a>
					<a class="wbgam-settings-nav-item" href="#tools" data-section="tools">
						<span class="icon-wrench"></span>
						<?php esc_html_e( 'Tools', 'wb-gamification' ); ?>
					</a>
				</div>
			</div>

			<!-- Content -->
			<div class="wbgam-settings-content">
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
				<?php if ( isset( $_GET['saved'] ) ) : ?>
					<div class="wbgam-banner wbgam-banner--success wbgam-stack-block" role="status" aria-live="polite">
						<span class="wbgam-banner__icon icon-circle-check" aria-hidden="true"></span>
						<div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php esc_html_e( 'Settings saved.', 'wb-gamification' ); ?></p></div>
					</div>
				<?php endif; ?>
				<?php // The richer post-Setup-Wizard banner with Hub URL + View/Edit buttons is rendered by render_dashboard_tab(). ?>

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

				<!-- Emails section -->
				<div class="wbgam-settings-section" id="section-emails">
					<?php self::render_emails_section(); ?>
				</div>

				<!-- Access section -->
				<div class="wbgam-settings-section" id="section-access">
					<?php self::render_access_section(); ?>
				</div>

				<!-- Realtime section -->
				<div class="wbgam-settings-section" id="section-realtime">
					<?php self::render_realtime_section(); ?>
				</div>

				<!-- Integrations section -->
				<div class="wbgam-settings-section" id="section-integrations">
					<?php self::render_integrations_section( $bp_active ); ?>
				</div>

				<!-- Modules section -->
				<div class="wbgam-settings-section" id="section-modules">
					<?php self::render_modules_section(); ?>
				</div>

				<!-- Tools section -->
				<div class="wbgam-settings-section" id="section-tools">
					<?php self::render_tools_section(); ?>
				</div>
			</div>

		</div>
		</div><!-- /.wrap.wbgam-wrap -->
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

		// Resolve the default currency label once — used for the section title +
		// table column header so the UI never hard-codes "Points" when the site
		// has renamed the primary type or set a different currency as default.
		$wb_gam_pt_service    = new \WBGam\Services\PointTypeService();
		$wb_gam_pt_catalog    = $wb_gam_pt_service->list();
		$wb_gam_default_slug  = $wb_gam_pt_service->default_slug();
		$wb_gam_default_pt    = $wb_gam_pt_service->get( $wb_gam_default_slug );
		$wb_gam_default_label = $wb_gam_default_pt ? (string) $wb_gam_default_pt['label'] : __( 'Points', 'wb-gamification' );
		$wb_gam_section_title = strtoupper( $wb_gam_default_label );

		// Multi-currency mode: when the site has > 1 point type, show a per-action
		// currency picker in the settings table. Otherwise the currency column is
		// hidden — there's nothing to choose between.
		$wb_gam_multi_currency = count( $wb_gam_pt_catalog ) > 1;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=points' ) ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

			<?php if ( empty( $actions ) ) : ?>
				<div class="wbgam-settings-card">
					<div class="wbgam-settings-card__head">
						<p class="wbgam-settings-card__title"><?php echo esc_html( $wb_gam_section_title ); ?></p>
						<p class="wbgam-settings-card__desc">
							<?php
							printf(
								/* translators: %s: default currency label (e.g. Points / XP). */
								esc_html__( 'Configure %s values for each action.', 'wb-gamification' ),
								esc_html( strtolower( $wb_gam_default_label ) )
							);
							?>
						</p>
					</div>
					<div class="wbgam-settings-card__body wbgam-settings-card__body--cozy">
						<p><?php esc_html_e( 'No gamification actions are registered yet. Triggers load automatically once BuddyPress or other integrations are active.', 'wb-gamification' ); ?></p>
					</div>
				</div>
			<?php else : ?>

				<?php
				// Sort so 'WordPress' renders first (and is open by default), other
				// integrations follow alphabetically.
				uksort(
					$by_cat,
					static function ( $a, $b ) {
						if ( 'WordPress' === $a ) {
							return -1;
						}
						if ( 'WordPress' === $b ) {
							return 1;
						}
						return strcmp( (string) $a, (string) $b );
					}
				);
				?>

				<?php foreach ( $by_cat as $cat => $cat_actions ) : ?>
					<details class="wbgam-settings-card wbgam-stack-block wbgam-accordion"<?php echo 'WordPress' === $cat ? ' open' : ''; ?>>
						<summary class="wbgam-settings-card__head wbgam-accordion__head">
							<span class="wbgam-accordion__chevron icon-chevron-right" aria-hidden="true"></span>
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
										<th class="wb-gam-col-points"><?php esc_html_e( 'Amount', 'wb-gamification' ); ?></th>
										<?php if ( $wb_gam_multi_currency ) : ?>
											<th class="wb-gam-col-currency"><?php esc_html_e( 'Currency', 'wb-gamification' ); ?></th>
										<?php endif; ?>
										<th class="wb-gam-col-flag"><?php esc_html_e( 'Repeat', 'wb-gamification' ); ?></th>
										<th class="wb-gam-col-flag"><?php esc_html_e( 'Cooldown (s)', 'wb-gamification' ); ?></th>
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
										// Resolve current currency: admin override > manifest > primary.
										$action_type = \WBGam\Engine\Registry::resolve_action_point_type( $action );
										if ( '' === $action_type ) {
											$action_type = $wb_gam_default_slug;
										}
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
													aria-label="<?php /* translators: %s: gamification action label */ echo esc_attr( sprintf( __( 'Amount for %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
													value="<?php echo esc_attr( (string) $pts ); ?>"
													min="0"
													max="9999"
													class="wbgam-input wbgam-input--xs"
												>
											</td>
											<?php if ( $wb_gam_multi_currency ) : ?>
												<td>
													<select
														name="<?php echo esc_attr( 'wb_gam_point_type_' . $action_id ); ?>"
														class="wbgam-select wbgam-select--sm"
														aria-label="<?php /* translators: %s: gamification action label */ echo esc_attr( sprintf( __( 'Currency for %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
													>
														<?php foreach ( $wb_gam_pt_catalog as $wb_gam_pt_choice ) : ?>
															<option value="<?php echo esc_attr( (string) $wb_gam_pt_choice['slug'] ); ?>" <?php selected( $action_type, (string) $wb_gam_pt_choice['slug'] ); ?>>
																<?php echo esc_html( (string) $wb_gam_pt_choice['label'] ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</td>
											<?php endif; ?>
											<td>
												<?php if ( $repeatable ) : ?>
													<span class="wbgam-pill wbgam-pill--info"><?php esc_html_e( 'Yes', 'wb-gamification' ); ?></span>
												<?php else : ?>
													<span class="wbgam-pill wbgam-pill--inactive"><?php esc_html_e( 'Once', 'wb-gamification' ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php $wb_gam_cooldown = (int) ( $action['cooldown'] ?? 0 ); ?>
												<input
													type="number"
													data-wb-gam-action-override="cooldown"
													data-wb-gam-action-id="<?php echo esc_attr( $action_id ); ?>"
													value="<?php echo esc_attr( (string) $wb_gam_cooldown ); ?>"
													min="0"
													max="86400"
													step="1"
													class="wbgam-input wbgam-input--xs"
													aria-label="<?php /* translators: %s: action label */ echo esc_attr( sprintf( __( 'Cooldown in seconds for %s', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
												>
											</td>
											<td>
												<input
													type="number"
													data-wb-gam-action-override="daily_cap"
													data-wb-gam-action-id="<?php echo esc_attr( $action_id ); ?>"
													value="<?php echo esc_attr( (string) $daily_cap ); ?>"
													min="0"
													max="9999"
													step="1"
													class="wbgam-input wbgam-input--xs"
													aria-label="<?php /* translators: %s: action label */ echo esc_attr( sprintf( __( 'Daily cap for %s (0 = unlimited)', 'wb-gamification' ), $action['label'] ?? $action_id ) ); ?>"
												>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</details>
				<?php endforeach; ?>

				<?php self::enqueue_action_overrides_script(); ?>

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
										value="<?php echo esc_attr( (string) (int) get_option( 'wb_gam_log_retention_months', 6 ) ); ?>"
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

				<div class="wbgam-card wbgam-stack-block">
					<div class="wbgam-card-header">
						<h3 class="wbgam-card-title"><?php esc_html_e( 'Point expiry', 'wb-gamification' ); ?></h3>
						<p class="wbgam-card-desc"><?php esc_html_e( 'Optionally decay the balance of members who stop earning, to nudge re-engagement. Off by default. When on, a daily job reduces the primary-currency balance of any member with no points activity for the chosen number of days - applied once per inactive streak, then they must earn again.', 'wb-gamification' ); ?></p>
					</div>
					<div class="wbgam-card-body">
						<label class="wbgam-checkbox-option wbgam-stack-block">
							<input type="checkbox" name="wb_gam_points_decay_enabled" value="1" <?php checked( (bool) (int) get_option( 'wb_gam_points_decay_enabled', 0 ) ); ?> />
							<span><?php esc_html_e( 'Enable point expiry', 'wb-gamification' ); ?></span>
						</label>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="wb_gam_points_decay_days"><?php esc_html_e( 'Inactive for', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="wb_gam_points_decay_days" id="wb_gam_points_decay_days" class="wb-gam-input-narrow" min="1" max="3650" value="<?php echo esc_attr( (string) (int) get_option( 'wb_gam_points_decay_days', 90 ) ); ?>" />
									<?php esc_html_e( 'days', 'wb-gamification' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wb_gam_points_decay_percent"><?php esc_html_e( 'Decay amount', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="wb_gam_points_decay_percent" id="wb_gam_points_decay_percent" class="wb-gam-input-narrow" min="1" max="100" value="<?php echo esc_attr( (string) (int) get_option( 'wb_gam_points_decay_percent', 100 ) ); ?>" />
									<?php esc_html_e( '% of balance (100 = expire fully)', 'wb-gamification' ); ?>
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

		$hub_page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		$hub_url     = $hub_page_id ? get_permalink( $hub_page_id ) : '';
		$hub_edit    = $hub_page_id ? get_edit_post_link( $hub_page_id ) : '';

		// Show one-time success banner immediately after the Setup Wizard finishes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner driven by post-redirect query, no state mutation.
		if ( isset( $_GET['setup'] ) && 'complete' === sanitize_key( wp_unslash( $_GET['setup'] ) ) && $hub_url ) :
			?>
			<div class="wbgam-settings-card wbgam-stack-block wbgam-card--success">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<span class="icon-circle-check" aria-hidden="true"></span>
						<?php esc_html_e( 'Setup complete - your Gamification Hub is ready', 'wb-gamification' ); ?>
					</h3>
				</div>
				<div class="wbgam-card-body">
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s: hub page URL. */
								__( 'A page was auto-created at <code>%s</code> with the full member hub. Share this link with your community - they can see their points, badges, level progress, leaderboard, and challenges all in one place.', 'wb-gamification' ),
								array( 'code' => array() )
							),
							esc_html( wp_make_link_relative( $hub_url ) )
						);
						?>
					</p>
					<p class="wbgam-actions-row">
						<a href="<?php echo esc_url( $hub_url ); ?>" target="_blank" rel="noopener" class="wbgam-btn wbgam-btn--primary">
							<span class="icon-external-link" aria-hidden="true"></span>
							<?php esc_html_e( 'View Hub page', 'wb-gamification' ); ?>
						</a>
						<?php if ( $hub_edit ) : ?>
							<a href="<?php echo esc_url( $hub_edit ); ?>" class="wbgam-btn wbgam-btn--secondary">
								<span class="icon-pencil" aria-hidden="true"></span>
								<?php esc_html_e( 'Edit Hub page', 'wb-gamification' ); ?>
							</a>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<?php
		endif;

		// Show first-run welcome card if no points awarded yet and admin hasn't dismissed it.
		// `?wb_gam_force_welcome=1` bypasses the gate so admins (or the QA browser
		// runner) can re-open the card on a populated install.
		$dismissed = get_user_meta( get_current_user_id(), 'wb_gam_dismissed_welcome', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only force flag.
		$force_welcome = isset( $_GET['wb_gam_force_welcome'] ) && '1' === $_GET['wb_gam_force_welcome'];
		$show_welcome  = $force_welcome || ( ! $dismissed && 0 === (int) $stats['points_total'] && 0 === (int) $stats['active_members'] );
		if ( $show_welcome ) :
			?>
			<div class="wbgam-settings-card wbgam-stack-block wbgam-card--accent" data-wb-gam-welcome>
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Getting Started', 'wb-gamification' ); ?></h3>
					<span class="wbgam-card-header__actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-setup' ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary" title="<?php esc_attr_e( 'Re-run the Setup Wizard to pick a different starter template or change defaults.', 'wb-gamification' ); ?>"><?php esc_html_e( 'Re-run wizard', 'wb-gamification' ); ?></a>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wb-gamification&dismiss_welcome=1' ), 'wb_gam_dismiss_welcome' ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary"><?php esc_html_e( 'Dismiss', 'wb-gamification' ); ?></a>
					</span>
				</div>
				<div class="wbgam-card-body">
					<p><?php esc_html_e( 'Your gamification system is active! Points, badges, and levels will appear here as members interact with your site. Here are some next steps:', 'wb-gamification' ); ?></p>
					<p class="wbgam-actions-row">
						<button
							type="button"
							class="wbgam-btn wbgam-btn--secondary"
							data-wb-gam-test-event
							data-points="10"
						>
							<span class="icon-zap" aria-hidden="true"></span>
							<?php esc_html_e( 'Send a test event (award yourself 10 points)', 'wb-gamification' ); ?>
						</button>
						<span class="wbgam-test-event-status" data-wb-gam-test-event-status role="status" aria-live="polite"></span>
					</p>
					<p class="wbgam-quick-nav">
						<?php if ( $hub_url ) : ?>
							<a href="<?php echo esc_url( $hub_url ); ?>" target="_blank" rel="noopener" class="wbgam-quick-nav__item">
								<span class="icon-external-link"></span>
								<?php esc_html_e( 'Visit your Hub (preview as member)', 'wb-gamification' ); ?>
							</a>
						<?php endif; ?>
						<a href="#points" class="wbgam-quick-nav__item" data-section="points">
							<span class="icon-star"></span>
							<?php esc_html_e( 'Configure point values', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-point-types' ) ); ?>" class="wbgam-quick-nav__item">
							<span class="icon-tag"></span>
							<?php esc_html_e( 'Add a currency (XP, Coins…)', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>" class="wbgam-quick-nav__item">
							<span class="icon-flag"></span>
							<?php esc_html_e( 'Create a challenge', 'wb-gamification' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>" class="wbgam-quick-nav__item">
							<span class="icon-award"></span>
							<?php esc_html_e( 'View badge library', 'wb-gamification' ); ?>
						</a>
					</p>
				</div>
			</div>
			<?php
		endif;

		// Setup-progress checklist — visible until every step is checked OR the
		// admin dismisses it. Each step queries the live site state; admins
		// don't need to manually mark anything done. Hides itself once the
		// last step turns green.
		$checklist_dismissed = (bool) get_user_meta( get_current_user_id(), 'wb_gam_dismissed_checklist', true );
		$checklist_steps     = self::compute_setup_checklist( $hub_page_id, (int) $stats['points_total'] );
		$checklist_done      = count( array_filter( $checklist_steps, static fn( $step ) => $step['done'] ) );
		$checklist_total     = count( $checklist_steps );

		if ( ! $checklist_dismissed && $checklist_done < $checklist_total ) :
			?>
			<div class="wbgam-settings-card wbgam-stack-block wbgam-card--accent">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<?php
						printf(
							/* translators: 1: completed step count, 2: total step count */
							esc_html__( 'Setup checklist (%1$d / %2$d)', 'wb-gamification' ),
							(int) $checklist_done,
							(int) $checklist_total
						);
						?>
					</h3>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wb-gamification&dismiss_checklist=1' ), 'wb_gam_dismiss_checklist' ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary">
						<?php esc_html_e( 'Dismiss', 'wb-gamification' ); ?>
					</a>
				</div>
				<div class="wbgam-card-body">
					<ul class="wbgam-checklist">
						<?php foreach ( $checklist_steps as $step ) : ?>
							<li class="wbgam-checklist__item <?php echo $step['done'] ? 'is-done' : ''; ?>">
								<span class="wbgam-checklist__icon icon-<?php echo $step['done'] ? 'check-circle' : 'circle'; ?>" aria-hidden="true"></span>
								<span class="wbgam-checklist__body">
									<strong class="wbgam-checklist__title"><?php echo esc_html( $step['title'] ); ?></strong>
									<span class="wbgam-checklist__desc"><?php echo esc_html( $step['desc'] ); ?></span>
								</span>
								<?php if ( ! $step['done'] && ! empty( $step['action_url'] ) ) : ?>
									<a class="wbgam-btn wbgam-btn--sm wbgam-checklist__action" href="<?php echo esc_url( $step['action_url'] ); ?>"<?php echo ! empty( $step['action_target'] ) ? ' target="' . esc_attr( $step['action_target'] ) . '" rel="noopener"' : ''; ?>>
										<?php echo esc_html( $step['action_label'] ); ?>
									</a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
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
				'icon-star'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Active Members', 'wb-gamification' ),
				number_format_i18n( $stats['active_members'] ),
				sprintf(
					/* translators: %d = total member count */
					__( '%d total members', 'wb-gamification' ),
					$stats['total_members']
				),
				'icon-users'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Badges Earned', 'wb-gamification' ),
				number_format_i18n( $stats['badges_earned'] ),
				sprintf(
					/* translators: %s = badge earner percentage */
					__( '%s%% of active members', 'wb-gamification' ),
					$stats['badge_earner_pct']
				),
				'icon-medal'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Challenges Completed', 'wb-gamification' ),
				number_format_i18n( $stats['challenges_completed'] ),
				sprintf(
					/* translators: %s = completion rate percentage */
					__( '%s%% completion rate', 'wb-gamification' ),
					$stats['challenge_completion_pct']
				),
				'icon-target'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Active Streaks', 'wb-gamification' ),
				number_format_i18n( $stats['active_streaks'] ),
				sprintf(
					/* translators: %s = streak health percentage */
					__( '%s%% streak health', 'wb-gamification' ),
					$stats['streak_health_pct']
				),
				'icon-flame'
			);
			AnalyticsDashboard::kpi_card(
				__( 'Kudos Given', 'wb-gamification' ),
				number_format_i18n( $stats['kudos_given'] ),
				__( 'Last 30 days', 'wb-gamification' ),
				'icon-heart-handshake'
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
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification#points' ) ); ?>"
				class="button">
				<?php esc_html_e( 'Configure Points', 'wb-gamification' ); ?>
			</a>
		</div>

		<?php
		// Activity proof + engagement signal — what a customer wants to see
		// every time they open the Dashboard: "Is the system running? Who's
		// winning right now? What's earning the most points?". The same
		// $stats payload already powers the standalone Analytics page; reusing
		// it here keeps Dashboard cheap and Analytics consistent.
		$has_top_earners = ! empty( $stats['top_earners'] );
		$has_top_actions = ! empty( $stats['top_actions'] );
		$recent_kudos    = \WBGam\Engine\KudosEngine::get_recent( 6 );
		?>
		<div class="wbgam-dashboard-row">

			<!-- Top members (30d) -->
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<span class="icon-trophy" aria-hidden="true"></span>
						<?php esc_html_e( 'Top members - last 30 days', 'wb-gamification' ); ?>
					</h3>
					<a class="wbgam-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-analytics' ) ); ?>">
						<?php esc_html_e( 'View all', 'wb-gamification' ); ?>
					</a>
				</div>
				<div class="wbgam-card-body">
					<?php if ( ! $has_top_earners ) : ?>
						<p class="wbgam-empty"><?php esc_html_e( 'No earnings yet - points will appear here as members engage.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<ol class="wbgam-rank-list" role="list">
							<?php
							$rank = 0;
							foreach ( array_slice( (array) $stats['top_earners'], 0, 6 ) as $row ) :
								++$rank;
								$user = isset( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : null;
								if ( ! $user ) {
									continue;
								}
								?>
								<li class="wbgam-rank-list__item">
									<span class="wbgam-rank-list__rank">#<?php echo esc_html( (string) $rank ); ?></span>
									<span class="wbgam-rank-list__avatar" aria-hidden="true"><?php echo get_avatar( $user->ID, 28 ); ?></span>
									<span class="wbgam-rank-list__name"><?php echo esc_html( $user->display_name ); ?></span>
									<span class="wbgam-rank-list__points"><?php echo esc_html( number_format_i18n( (int) ( $row['pts'] ?? 0 ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>
			</div>

			<!-- Top actions (30d) -->
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<span class="icon-zap" aria-hidden="true"></span>
						<?php esc_html_e( 'Top actions - last 30 days', 'wb-gamification' ); ?>
					</h3>
					<a class="wbgam-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification#points' ) ); ?>">
						<?php esc_html_e( 'Configure', 'wb-gamification' ); ?>
					</a>
				</div>
				<div class="wbgam-card-body">
					<?php if ( ! $has_top_actions ) : ?>
						<p class="wbgam-empty"><?php esc_html_e( 'No actions logged yet.', 'wb-gamification' ); ?></p>
					<?php else : ?>
						<ul class="wbgam-action-list" role="list">
							<?php foreach ( array_slice( (array) $stats['top_actions'], 0, 6 ) as $row ) : ?>
								<li class="wbgam-action-list__item">
									<span class="wbgam-action-list__name"><?php echo esc_html( str_replace( '_', ' ', (string) ( $row['action_id'] ?? '' ) ) ); ?></span>
									<span class="wbgam-action-list__count">
										<?php
										printf(
											/* translators: %d: number of times this action fired in the last 30 days */
											esc_html( _n( '%d event', '%d events', (int) ( $row['events'] ?? 0 ), 'wb-gamification' ) ),
											(int) ( $row['events'] ?? 0 )
										);
										?>
									</span>
									<span class="wbgam-action-list__points"><?php echo esc_html( number_format_i18n( (int) ( $row['pts'] ?? 0 ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>

		</div>

		<?php if ( ! empty( $recent_kudos ) ) : ?>
			<div class="wbgam-card wbgam-dashboard-row__full">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<span class="icon-heart-handshake" aria-hidden="true"></span>
						<?php esc_html_e( 'Recent kudos', 'wb-gamification' ); ?>
					</h3>
					<a class="wbgam-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=kudos' ) ); ?>">
						<?php esc_html_e( 'Manage kudos', 'wb-gamification' ); ?>
					</a>
				</div>
				<div class="wbgam-card-body">
					<ul class="wbgam-kudos-feed" role="list">
						<?php foreach ( $recent_kudos as $kudo ) : ?>
							<li class="wbgam-kudos-feed__item">
								<span class="wbgam-kudos-feed__giver"><?php echo esc_html( $kudo['giver_name'] ); ?></span>
								<span class="wbgam-kudos-feed__arrow" aria-hidden="true">→</span>
								<span class="wbgam-kudos-feed__receiver"><?php echo esc_html( $kudo['receiver_name'] ); ?></span>
								<?php if ( ! empty( $kudo['message'] ) ) : ?>
									<span class="wbgam-kudos-feed__message"><?php echo esc_html( '“' . wp_trim_words( (string) $kudo['message'], 14 ) . '”' ); ?></span>
								<?php endif; ?>
								<span class="wbgam-kudos-feed__time">
									<?php
									printf(
										/* translators: %s: human-readable time difference, e.g. "3 hours" */
										esc_html__( '%s ago', 'wb-gamification' ),
										esc_html( human_time_diff( strtotime( (string) $kudo['created_at'] ), current_time( 'timestamp' ) ) )
									);
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php endif; ?>
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
								<input type="number" name="wb_gam_kudos_daily_limit" id="wb-gam-kudos-daily-limit" value="<?php echo esc_attr( (string) $daily_limit ); ?>" min="1" max="999" class="wb-gam-input-narrow">
								<p class="description"><?php esc_html_e( 'Maximum number of kudos a member can send per day. Prevents spam.', 'wb-gamification' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wb-gam-kudos-receiver-points"><?php esc_html_e( 'Points per kudos received', 'wb-gamification' ); ?></label></th>
							<td>
								<input type="number" name="wb_gam_kudos_receiver_points" id="wb-gam-kudos-receiver-points" value="<?php echo esc_attr( (string) $receiver_points ); ?>" min="0" max="9999" class="wb-gam-input-narrow">
								<p class="description"><?php esc_html_e( 'Points awarded to the member who receives kudos.', 'wb-gamification' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wb-gam-kudos-giver-points"><?php esc_html_e( 'Points per kudos given', 'wb-gamification' ); ?></label></th>
							<td>
								<input type="number" name="wb_gam_kudos_giver_points" id="wb-gam-kudos-giver-points" value="<?php echo esc_attr( (string) $giver_points ); ?>" min="0" max="9999" class="wb-gam-input-narrow">
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

	// ── Realtime section ──────────────────────────────────────────────────────

	/**
	 * Render the Realtime transport selector.
	 *
	 * Closes the "site owners must use wp-cli to flip transport" friction
	 * we surfaced after the SSE rollout. Three radio options matching the
	 * wb_gam_realtime_transport contract: heartbeat (universal, 5s),
	 * sse (sub-second when host supports it), auto (SSE-first with
	 * heartbeat fallback — the new default).
	 */
	/**
	 * Persist the earning-exclusion settings (Settings > Access).
	 *
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	private static function save_access_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
		$roles = array();
		if ( isset( $_POST['wb_gam_excluded_roles'] ) && is_array( $_POST['wb_gam_excluded_roles'] ) ) {
			$valid  = array_keys( wp_roles()->get_names() );
			$posted = array_map( 'sanitize_key', wp_unslash( (array) $_POST['wb_gam_excluded_roles'] ) );
			foreach ( $posted as $role ) {
				if ( in_array( $role, $valid, true ) ) {
					$roles[] = $role;
				}
			}
		}
		update_option( 'wb_gam_excluded_roles', array_values( array_unique( $roles ) ) );

		$user_ids = array();
		if ( isset( $_POST['wb_gam_excluded_users'] ) ) {
			$raw    = sanitize_textarea_field( wp_unslash( $_POST['wb_gam_excluded_users'] ) );
			$tokens = preg_split( '/[\s,]+/', $raw ) ?: array();
			foreach ( $tokens as $token ) {
				$token = trim( $token );
				if ( '' === $token ) {
					continue;
				}
				$user = is_numeric( $token )
					? get_user_by( 'id', (int) $token )
					: ( get_user_by( 'login', $token ) ?: get_user_by( 'email', $token ) );
				if ( $user ) {
					$user_ids[] = (int) $user->ID;
				}
			}
		}
		update_option( 'wb_gam_excluded_users', array_values( array_unique( $user_ids ) ) );

		// Drop the per-request resolve cache so a read later in this request
		// reflects the new exclusions.
		\WBGam\Engine\PointsEngine::flush_exclusion_cache();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Render the Access section: exclude roles or specific accounts from
	 * earning points. Excluded users keep any points they already have but stop
	 * earning and are hidden from leaderboards (PointsEngine::user_can_earn).
	 */
	private static function render_access_section(): void {
		$excluded_roles = (array) get_option( 'wb_gam_excluded_roles', array() );
		$all_roles      = wp_roles()->get_names();

		$excluded_users = array_map( 'absint', (array) get_option( 'wb_gam_excluded_users', array() ) );
		$user_tokens    = array();
		foreach ( $excluded_users as $uid ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$user_tokens[] = $user->user_login;
			}
		}
		$users_value = implode( ', ', $user_tokens );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=access' ) ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h2 class="wbgam-card-title">
						<span class="icon-user-x" aria-hidden="true"></span>
						<?php esc_html_e( 'Exclude from earning', 'wb-gamification' ); ?>
					</h2>
					<p class="wbgam-card-desc">
						<?php esc_html_e( 'Stop chosen roles or accounts from earning points, badges, levels, and streaks - useful for administrators, staff, support agents, and bots. Excluded members keep any points they already earned but stop accruing new ones and are hidden from leaderboards.', 'wb-gamification' ); ?>
					</p>
				</div>
				<div class="wbgam-card-body">
					<label class="wbgam-field-label"><?php esc_html_e( 'Excluded roles', 'wb-gamification' ); ?></label>
					<?php foreach ( $all_roles as $slug => $name ) : ?>
						<label class="wbgam-checkbox-option wbgam-stack-block">
							<input type="checkbox"
								name="wb_gam_excluded_roles[]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $excluded_roles, true ) ); ?>
							/>
							<span><?php echo esc_html( translate_user_role( $name ) ); ?></span>
						</label>
					<?php endforeach; ?>
					<p class="description wbgam-stack-block">
						<?php esc_html_e( 'Most communities exclude Administrator and any staff role so internal testing does not skew the leaderboard.', 'wb-gamification' ); ?>
					</p>
				</div>
			</div>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h2 class="wbgam-card-title">
						<span class="icon-ban" aria-hidden="true"></span>
						<?php esc_html_e( 'Excluded accounts', 'wb-gamification' ); ?>
					</h2>
					<p class="wbgam-card-desc">
						<?php esc_html_e( 'Exclude specific accounts regardless of role. Enter usernames, emails, or user IDs separated by commas or new lines.', 'wb-gamification' ); ?>
					</p>
				</div>
				<div class="wbgam-card-body">
					<label class="wbgam-field-label" for="wb_gam_excluded_users">
						<?php esc_html_e( 'Usernames, emails, or IDs', 'wb-gamification' ); ?>
					</label>
					<textarea id="wb_gam_excluded_users"
						name="wb_gam_excluded_users"
						class="wbgam-textarea"
						rows="3"
						placeholder="<?php echo esc_attr__( 'e.g. supportbot, qa@example.com, 42', 'wb-gamification' ); ?>"><?php echo esc_textarea( $users_value ); ?></textarea>
					<p class="description wbgam-stack-block">
						<?php esc_html_e( 'Unrecognized entries are dropped on save. The saved list shows the resolved usernames.', 'wb-gamification' ); ?>
					</p>
				</div>
			</div>

			<div class="wbgam-settings-section__footer">
				<?php submit_button( __( 'Save Access Settings', 'wb-gamification' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Persist the optional-module on/off toggles (Settings > Modules).
	 *
	 * Nonce is verified by check_admin_referer() in handle_save().
	 */
	private static function save_modules_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer() in handle_save().
		$posted = array();
		if ( isset( $_POST['wb_gam_modules'] ) && is_array( $_POST['wb_gam_modules'] ) ) {
			$posted = array_map( 'sanitize_key', wp_unslash( (array) $_POST['wb_gam_modules'] ) );
		}
		// Checkbox semantics: present = enabled ('1'), absent = disabled ('0').
		$map = array();
		foreach ( array_keys( \WBGam\Engine\ModuleToggles::modules() ) as $slug ) {
			$map[ $slug ] = in_array( $slug, $posted, true ) ? '1' : '0';
		}
		update_option( 'wb_gam_modules', $map );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Render the Modules section: turn optional engagement modules on or off.
	 * A disabled module's blocks/shortcodes render nothing and its admin page
	 * is hidden, but its data is preserved (re-enabling restores it).
	 */
	private static function render_modules_section(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=modules' ) ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h2 class="wbgam-card-title">
						<span class="icon-sliders" aria-hidden="true"></span>
						<?php esc_html_e( 'Optional modules', 'wb-gamification' ); ?>
					</h2>
					<p class="wbgam-card-desc">
						<?php esc_html_e( 'Turn off engagement modules your community does not use. A disabled module is hidden from members (its blocks and shortcodes render nothing) and its admin page is removed. Nothing is deleted - re-enabling a module restores it exactly as it was. Points, badges, levels, and leaderboards are always on.', 'wb-gamification' ); ?>
					</p>
				</div>
				<div class="wbgam-card-body">
					<?php foreach ( \WBGam\Engine\ModuleToggles::modules() as $slug => $module ) : ?>
						<label class="wbgam-checkbox-option wbgam-stack-block">
							<input type="checkbox"
								name="wb_gam_modules[]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( \WBGam\Engine\ModuleToggles::enabled( $slug ) ); ?>
							/>
							<span><?php echo esc_html( $module['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="wbgam-settings-section__footer">
				<?php submit_button( __( 'Save Modules', 'wb-gamification' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the Tools section: settings import / export (config portability).
	 */
	private static function render_tools_section(): void {
		?>
		<div class="wbgam-card wbgam-stack-block">
			<div class="wbgam-card-header">
				<h2 class="wbgam-card-title">
					<span class="icon-download" aria-hidden="true"></span>
					<?php esc_html_e( 'Export settings', 'wb-gamification' ); ?>
				</h2>
				<p class="wbgam-card-desc">
					<?php esc_html_e( 'Download this site\'s gamification configuration as a JSON file - points values, enabled actions, currencies, levels config, kudos, automation rules, realtime, access exclusions, and the hub page mapping. Runtime data (caches, schema versions, snapshots) is excluded so the file is safe to import elsewhere.', 'wb-gamification' ); ?>
				</p>
			</div>
			<div class="wbgam-card-body">
				<button type="button" id="wb-gam-export-settings" class="wbgam-btn"><?php esc_html_e( 'Export settings', 'wb-gamification' ); ?></button>
			</div>
		</div>

		<div class="wbgam-card wbgam-stack-block">
			<div class="wbgam-card-header">
				<h2 class="wbgam-card-title">
					<span class="icon-upload" aria-hidden="true"></span>
					<?php esc_html_e( 'Import settings', 'wb-gamification' ); ?>
				</h2>
				<p class="wbgam-card-desc">
					<?php esc_html_e( 'Apply a settings file exported from another WB Gamification site. Matching settings are overwritten; your content (badges, members, point ledgers) is never touched.', 'wb-gamification' ); ?>
				</p>
			</div>
			<div class="wbgam-card-body">
				<p>
					<input type="file" id="wb-gam-import-file" accept="application/json,.json" />
				</p>
				<button type="button" id="wb-gam-import-settings" class="wbgam-btn"><?php esc_html_e( 'Import settings', 'wb-gamification' ); ?></button>
			</div>
		</div>

		<div class="wbgam-card wbgam-stack-block">
			<div class="wbgam-card-header">
				<h2 class="wbgam-card-title">
					<span class="icon-refresh-cw" aria-hidden="true"></span>
					<?php esc_html_e( 'Maintenance', 'wb-gamification' ); ?>
				</h2>
				<p class="wbgam-card-desc">
					<?php esc_html_e( 'Rebuild the leaderboard snapshot and clear its caches. Use this if the leaderboard looks stale after a manual award or import - it normally refreshes on a 5-minute cron.', 'wb-gamification' ); ?>
				</p>
			</div>
			<div class="wbgam-card-body">
				<button type="button" id="wb-gam-recompute-leaderboard" class="wbgam-btn"><?php esc_html_e( 'Rebuild leaderboard', 'wb-gamification' ); ?></button>
			</div>
		</div>

		<div class="wbgam-card wbgam-stack-block wb-gam-danger-zone">
			<div class="wbgam-card-header">
				<h2 class="wbgam-card-title">
					<span class="icon-triangle-alert" aria-hidden="true"></span>
					<?php esc_html_e( 'Reset member progress', 'wb-gamification' ); ?>
				</h2>
				<p class="wbgam-card-desc">
					<?php esc_html_e( 'Permanently delete all accumulated member progress - points, the event log, earned badges, streaks, kudos, league membership, challenge logs, redemptions, and submissions - so the community starts fresh. Your configuration is kept: badge definitions, levels, rules, challenges, point types, rewards, member privacy settings, webhooks, and all settings survive. This cannot be undone.', 'wb-gamification' ); ?>
				</p>
			</div>
			<div class="wbgam-card-body">
				<button type="button" id="wb-gam-reset-progress" class="wbgam-btn wb-gam-btn-danger"><?php esc_html_e( 'Reset all member progress', 'wb-gamification' ); ?></button>
			</div>
		</div>
		<?php
	}

	private static function render_realtime_section(): void {
		$current = \WBGam\API\SSEController::get_transport();
		$saved   = (bool) ( isset( $_GET['saved'] ) && 'realtime' === sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current_position = \WBGam\Engine\NotificationBridge::get_toast_position();
		$positions        = array(
			'bottom-right' => __( 'Bottom right (recommended)', 'wb-gamification' ),
			'bottom-left'  => __( 'Bottom left', 'wb-gamification' ),
			'top-right'    => __( 'Top right', 'wb-gamification' ),
			'top-center'   => __( 'Top center', 'wb-gamification' ),
		);

		$choices = array(
			\WBGam\API\SSEController::TRANSPORT_HEARTBEAT => array(
				'label'       => __( 'Heartbeat (recommended)', 'wb-gamification' ),
				'description' => __( 'Shared WP Heartbeat polling. Polls every 15 seconds at rest and briefly speeds up to ~5 seconds right after the member does something, then eases back. Backgrounded tabs nearly suspend. Works everywhere and scales to large communities - no held server connections.', 'wb-gamification' ),
			),
			\WBGam\API\SSEController::TRANSPORT_AUTO      => array(
				'label'       => __( 'Auto (SSE where allowed)', 'wb-gamification' ),
				'description' => __( 'Use Server-Sent Events if SSE is permitted on this host, otherwise Heartbeat. SSE stays OFF until you enable it (see SSE below) because it holds a PHP worker per connection.', 'wb-gamification' ),
			),
			\WBGam\API\SSEController::TRANSPORT_SSE       => array(
				'label'       => __( 'Server-Sent Events', 'wb-gamification' ),
				'description' => __( 'Sub-second streaming, but each connection pins a PHP worker for its lifetime - this does NOT scale on a standard PHP-FPM pool. Off by default: SSE only activates when a developer returns true from the wb_gam_sse_allowed filter on infrastructure built for long-lived streaming. Without that filter this falls back to Heartbeat.', 'wb-gamification' ),
			),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=realtime' ) ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h2 class="wbgam-card-title">
						<span class="icon-zap" aria-hidden="true"></span>
						<?php esc_html_e( 'Realtime Transport', 'wb-gamification' ); ?>
					</h2>
					<p class="wbgam-card-desc">
						<?php esc_html_e( 'Choose how members receive realtime notifications (kudos toasts, badge celebrations, leaderboard updates).', 'wb-gamification' ); ?>
					</p>
				</div>
				<div class="wbgam-card-body">
					<?php foreach ( $choices as $value => $info ) : ?>
						<label class="wbgam-radio-option wbgam-stack-block">
							<input type="radio"
								name="wb_gam_realtime_transport"
								value="<?php echo esc_attr( $value ); ?>"
								<?php checked( $current, $value ); ?>
							/>
							<span class="wbgam-radio-option__body">
								<strong><?php echo esc_html( $info['label'] ); ?></strong>
								<span class="description"><?php echo esc_html( $info['description'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>

					<p class="description wbgam-stack-block">
						<?php
						printf(
							/* translators: %s: link to the realtime-transport doc */
							esc_html__( 'Host requirements + verification tips: %s', 'wb-gamification' ),
							'<a href="https://docs.wbcomdesigns.com/wb-gamification/developer-guide/realtime-transport-wbgam/" target="_blank" rel="noopener">' . esc_html__( 'Realtime Transport guide', 'wb-gamification' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h2 class="wbgam-card-title">
						<span class="icon-bell" aria-hidden="true"></span>
						<?php esc_html_e( 'Notification placement', 'wb-gamification' ); ?>
					</h2>
					<p class="wbgam-card-desc">
						<?php esc_html_e( 'Where reward toasts (points, badges, kudos) appear on screen. Bottom-right is recommended - it never overlaps your theme header or navigation. Choose a top position only if a chat or support widget already sits in the bottom corner.', 'wb-gamification' ); ?>
					</p>
				</div>
				<div class="wbgam-card-body">
					<label class="wbgam-field-label" for="wb_gam_toast_position">
						<?php esc_html_e( 'Toast position', 'wb-gamification' ); ?>
					</label>
					<select id="wb_gam_toast_position" name="wb_gam_toast_position" class="wbgam-select">
						<?php foreach ( $positions as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_position, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="wbgam-settings-section__footer">
				<?php submit_button( __( 'Save Realtime Settings', 'wb-gamification' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	// ── Integrations section ──────────────────────────────────────────────────

	/**
	 * Render the Emails section — per-event toggles for transactional emails.
	 *
	 * Each email type has a wb_gam_email_<slug> option. Default OFF so
	 * existing sites don't get a flood of email after upgrade. Saves via
	 * the generic admin REST form driver (Tier-0 pattern), no admin_post.
	 */
	private static function render_emails_section(): void {
		$events = array(
			'level_up'            => array(
				'label'       => __( 'Level up', 'wb-gamification' ),
				'description' => __( 'Sent when a member reaches a new level.', 'wb-gamification' ),
			),
			'badge_earned'        => array(
				'label'       => __( 'Badge earned', 'wb-gamification' ),
				'description' => __( 'Sent when a member earns a badge.', 'wb-gamification' ),
			),
			'challenge_completed' => array(
				'label'       => __( 'Challenge completed', 'wb-gamification' ),
				'description' => __( 'Sent when a member finishes a challenge.', 'wb-gamification' ),
			),
			'redemption'          => array(
				'label'       => __( 'Redemption confirmation', 'wb-gamification' ),
				'description' => __( 'Sent when a member redeems a reward - includes the points spent, remaining balance, and the generated coupon code (if any).', 'wb-gamification' ),
			),
		);
		?>
		<div class="wbgam-card">
			<div class="wbgam-card-header">
				<h2 class="wbgam-card-title"><?php esc_html_e( 'Transactional emails', 'wb-gamification' ); ?></h2>
				<p class="wbgam-card-desc"><?php esc_html_e( 'Toggle which gamification events trigger an email to the member. All emails respect each member\'s notification preference and use the theme template at YOUR-THEME/wb-gamification/emails/{slug}.php when present.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-card-body">
				<form data-wb-gam-rest-form="wbGamSettings"
					data-wb-gam-rest-method="POST"
					data-wb-gam-rest-path="/settings/emails"
					data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Email settings saved.', 'wb-gamification' ); ?>"
					data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Could not save email settings.', 'wb-gamification' ); ?>">
					<?php foreach ( $events as $slug => $meta ) : ?>
						<?php $enabled = (bool) get_option( 'wb_gam_email_' . $slug, false ); ?>
						<div class="wbgam-toggle-row">
							<label class="wbgam-switch" for="wb_gam_email_<?php echo esc_attr( $slug ); ?>">
								<input
									type="checkbox"
									id="wb_gam_email_<?php echo esc_attr( $slug ); ?>"
									name="<?php echo esc_attr( $slug ); ?>"
									value="1"
									<?php checked( $enabled ); ?>
								>
								<span class="wbgam-switch__track" aria-hidden="true"></span>
							</label>
							<div class="wbgam-toggle-row__body">
								<strong class="wbgam-toggle-row__title"><?php echo esc_html( $meta['label'] ); ?></strong>
								<p class="wbgam-toggle-row__desc"><?php echo esc_html( $meta['description'] ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
					<div class="wbgam-card-footer">
						<button type="submit" class="wbgam-btn"><?php esc_html_e( 'Save email settings', 'wb-gamification' ); ?></button>
					</div>
				</form>
				<p class="wbgam-help">
					<?php
					printf(
						/* translators: %s: WP-CLI command */
						esc_html__( 'Test any template before enabling: %s', 'wb-gamification' ),
						'<code>wp wb-gamification email-test --user=1 --event=level_up</code>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

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
}
