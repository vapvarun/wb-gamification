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
		} elseif ( 'automation' === $tab ) {
			self::save_automation_settings();
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
			$index = (int) ( $_POST['wb_gam_rule_index'] ?? -1 );
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
			$points = isset( $_POST[ $key ] ) ? (int) wp_unslash( $_POST[ $key ] ) : null;

			if ( null !== $points && $points >= 0 ) {
				update_option( 'wb_gam_points_' . $action_id, $points );
			}

			$enabled_key = 'wb_gam_enabled_' . sanitize_key( $action_id );
			update_option( 'wb_gam_enabled_' . $action_id, isset( $_POST[ $enabled_key ] ) ? true : false );
		}

		// Also save log retention.
		if ( isset( $_POST['wb_gam_log_retention_months'] ) ) {
			$months = max( 1, min( 24, (int) wp_unslash( $_POST['wb_gam_log_retention_months'] ) ) );
			update_option( 'wb_gam_log_retention_months', $months );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_settings_error( 'wb_gamification', 'saved', __( 'Settings saved.', 'wb-gamification' ), 'success' );
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
			$new_points = max( 1, (int) wp_unslash( $_POST['wb_gam_new_level_points'] ?? 0 ) );
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
		$level_id = isset( $_GET['level_id'] ) ? (int) wp_unslash( $_GET['level_id'] ) : 0;
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
	 * Render the settings page HTML.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/URL parameter, no form data processed here.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

		settings_errors( 'wb_gamification' );
		?>
		<div class="wrap" id="wb-gam-settings">

			<div class="wb-gam-admin-header">
				<h1><?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?></h1>
				<?php self::render_mode_badge(); ?>
			</div>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wb-gamification' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
			<?php if ( isset( $_GET['setup'] ) && 'complete' === $_GET['setup'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setup complete! Review your point values below.', 'wb-gamification' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper wb-gam-admin-nav">
				<?php
				$tabs = array(
					'dashboard'  => __( 'Dashboard', 'wb-gamification' ),
					'points'     => __( 'Points', 'wb-gamification' ),
					'levels'     => __( 'Levels', 'wb-gamification' ),
					'automation' => __( 'Automation', 'wb-gamification' ),
				);
				foreach ( $tabs as $slug => $label ) :
					$class = ( $tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=' . $slug ) ); ?>"
						class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="wb-gam-admin-tab-content">
				<?php
				match ( $tab ) {
					'levels'     => self::render_levels_tab(),
					'automation' => self::render_automation_tab(),
					'points'     => self::render_points_tab(),
					default      => self::render_dashboard_tab(),
				};
		?>
			</div>

		</div>
		<?php
	}

	// ── Points tab ────────────────────────────────────────────────────────────

	/**
	 * Render the Points settings tab.
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
				<p><?php esc_html_e( 'No gamification actions are registered yet. Triggers load automatically once BuddyPress or other integrations are active.', 'wb-gamification' ); ?></p>
			<?php else : ?>

				<?php foreach ( $by_cat as $cat => $cat_actions ) : ?>
					<h3 class="wb-gam-admin-section-heading">
						<?php echo esc_html( $cat_labels[ $cat ] ?? ucfirst( $cat ) ); ?>
					</h3>
					<table class="widefat striped wb-gam-settings-table">
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
				<?php endforeach; ?>

				<h3><?php esc_html_e( 'Log Retention', 'wb-gamification' ); ?></h3>
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

				<?php submit_button( __( 'Save Changes', 'wb-gamification' ) ); ?>
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
	 * Render the Levels settings tab.
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
		<p class="wb-gam-admin-description">
			<?php esc_html_e( 'Edit level names and minimum point thresholds. Members move up automatically when they cross a threshold.', 'wb-gamification' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wb_gam_save_levels">
			<?php wp_nonce_field( 'wb_gam_save_levels', 'wb_gam_levels_nonce' ); ?>

			<table class="widefat striped wb-gam-levels-table">
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
								value="<?php echo esc_attr( $level['name'] ); ?>"
								class="wb-gam-input-full"
							>
						</td>
						<td>
							<input
								type="number"
								name="wb_gam_level[<?php echo esc_attr( $level['id'] ); ?>][min_points]"
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

			<?php submit_button( __( 'Save Levels', 'wb-gamification' ) ); ?>
		</form>

		<h3><?php esc_html_e( 'Add New Level', 'wb-gamification' ); ?></h3>
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

			<?php submit_button( __( 'Add Level', 'wb-gamification' ), 'secondary' ); ?>
		</form>
		<?php
	}

	// ── Automation tab ────────────────────────────────────────────────────────

	/**
	 * Render the Automation settings tab.
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
		<h2><?php esc_html_e( 'Rank Automation Rules', 'wb-gamification' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Automatically trigger actions when a member reaches a level. One action per rule — add multiple rules for the same level to stack actions.', 'wb-gamification' ); ?>
		</p>

		<?php if ( $rules ) : ?>
			<table class="widefat striped wb-gam-automation-table">
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
			<p class="description wb-gam-mb-24"><?php esc_html_e( 'No automation rules configured yet.', 'wb-gamification' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Add New Rule', 'wb-gamification' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>
			<input type="hidden" name="wb_gam_automation_action" value="add" />

			<table class="form-table">
				<tr>
					<th scope="row"><label for="wb_gam_new_rule_level"><?php esc_html_e( 'When member reaches level', 'wb-gamification' ); ?></label></th>
					<td>
						<select name="wb_gam_new_rule[trigger_level_id]" id="wb_gam_new_rule_level" required>
							<option value=""><?php esc_html_e( '— select level —', 'wb-gamification' ); ?></option>
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

			<?php submit_button( __( 'Add Rule', 'wb-gamification' ) ); ?>
		</form>
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
