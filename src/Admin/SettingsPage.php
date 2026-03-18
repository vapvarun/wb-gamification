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

use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_save_levels', array( __CLASS__, 'handle_save_levels' ) );
	}

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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_automation_rule sanitizes each field.
		$raw  = (array) wp_unslash( $_POST['wb_gam_new_rule'] ?? array() );
		$rule = self::normalize_automation_rule( $raw );
		if ( $rule ) {
			$existing_rules[] = $rule;
			update_option( 'wb_gam_rank_automation_rules', wp_json_encode( array_values( $existing_rules ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

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

	public static function handle_save_levels(): void {
		check_admin_referer( 'wb_gam_save_levels', 'wb_gam_levels_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wb-gamification' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_levels';

		// Process updates for existing levels.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized individually inside the loop.
		if ( ! empty( $_POST['wb_gam_level'] ) && is_array( $_POST['wb_gam_level'] ) ) {
			foreach ( (array) wp_unslash( $_POST['wb_gam_level'] ) as $id => $data ) {
				$id         = (int) $id;
				$name       = sanitize_text_field( wp_unslash( $data['name'] ?? '' ) );
				$min_points = max( 0, (int) ( $data['min_points'] ?? 0 ) );

				if ( ! $id || ! $name ) {
					continue;
				}

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

	// ── Render ────────────────────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'points';

		settings_errors( 'wb_gamification' );
		?>
		<div class="wrap" id="wb-gam-settings">

			<div style="display:flex;align-items:center;gap:12px;margin:20px 0 0;">
				<h1 style="margin:0;"><?php esc_html_e( 'WB Gamification', 'wb-gamification' ); ?></h1>
				<?php self::render_mode_badge(); ?>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wb-gamification' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['setup'] ) && 'complete' === $_GET['setup'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setup complete! Review your point values below.', 'wb-gamification' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-top:16px;">
				<?php
				$tabs = array(
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

			<div class="tab-content" style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:24px;">
				<?php
				match ( $tab ) {
					'levels'     => self::render_levels_tab(),
					'automation' => self::render_automation_tab(),
					default      => self::render_points_tab(),
				};
		?>
			</div>

		</div>
		<?php
	}

	// ── Points tab ────────────────────────────────────────────────────────────

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
					<h3 style="margin:20px 0 8px;text-transform:capitalize;">
						<?php echo esc_html( $cat_labels[ $cat ] ?? ucfirst( $cat ) ); ?>
					</h3>
					<table class="widefat striped" style="margin-bottom:20px;">
						<thead>
						<tr>
							<th style="width:28px;"><?php esc_html_e( 'On', 'wb-gamification' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Points', 'wb-gamification' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Repeat', 'wb-gamification' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Daily cap', 'wb-gamification' ); ?></th>
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
										<br><span style="color:#646970;font-size:12px;"><?php echo esc_html( $action['description'] ); ?></span>
									<?php endif; ?>
									<code style="font-size:11px;color:#999;"><?php echo esc_html( $action_id ); ?></code>
								</td>
								<td>
									<input
										type="number"
										name="<?php echo esc_attr( 'wb_gam_points_' . $action_id ); ?>"
										value="<?php echo esc_attr( $pts ); ?>"
										min="0"
										max="9999"
										style="width:70px;"
									>
								</td>
								<td style="color:#646970;font-size:12px;">
									<?php echo $repeatable ? esc_html__( 'Yes', 'wb-gamification' ) : esc_html__( 'Once', 'wb-gamification' ); ?>
								</td>
								<td style="color:#646970;font-size:12px;">
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
						<th scope="row"><?php esc_html_e( 'Keep points history for', 'wb-gamification' ); ?></th>
						<td>
							<input
								type="number"
								name="wb_gam_log_retention_months"
								value="<?php echo esc_attr( (int) get_option( 'wb_gam_log_retention_months', 6 ) ); ?>"
								min="1"
								max="24"
								style="width:70px;"
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

	// ── Levels tab ────────────────────────────────────────────────────────────

	private static function render_levels_tab(): void {
		global $wpdb;
		$levels = $wpdb->get_results(
			"SELECT id, name, min_points, sort_order FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		);
		?>
		<p style="color:#646970;margin-top:0;">
			<?php esc_html_e( 'Edit level names and minimum point thresholds. Members move up automatically when they cross a threshold.', 'wb-gamification' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wb_gam_save_levels">
			<?php wp_nonce_field( 'wb_gam_save_levels', 'wb_gam_levels_nonce' ); ?>

			<table class="widefat striped" style="max-width:600px;">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Level Name', 'wb-gamification' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Min Points Required', 'wb-gamification' ); ?></th>
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
								style="width:100%;"
							>
						</td>
						<td>
							<input
								type="number"
								name="wb_gam_level[<?php echo esc_attr( $level['id'] ); ?>][min_points]"
								value="<?php echo esc_attr( $level['min_points'] ); ?>"
								min="0"
								style="width:120px;"
								<?php echo 0 === (int) $level['min_points'] ? 'readonly title="' . esc_attr__( 'Starting level is always 0', 'wb-gamification' ) . '"' : ''; ?>
							>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Levels', 'wb-gamification' ) ); ?>
		</form>
		<?php
	}

	// ── Automation tab ────────────────────────────────────────────────────────

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
			<table class="widefat striped" style="margin-bottom:24px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When member reaches', 'wb-gamification' ); ?></th>
						<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
						<th><?php esc_html_e( 'Parameters', 'wb-gamification' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rules as $i => $rule ) :
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
								<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;">
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
			<p class="description" style="margin-bottom:24px;"><?php esc_html_e( 'No automation rules configured yet.', 'wb-gamification' ); ?></p>
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
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Group ID', 'wb-gamification' ); ?>
						<span style="color:#999;font-size:0.85em;"><?php esc_html_e( '(for "Add to group")', 'wb-gamification' ); ?></span>
					</th>
					<td><input type="number" name="wb_gam_new_rule[group_id]" class="small-text" min="0" value="" placeholder="0" /></td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Role slug', 'wb-gamification' ); ?>
						<span style="color:#999;font-size:0.85em;"><?php esc_html_e( '(for "Add role")', 'wb-gamification' ); ?></span>
					</th>
					<td><input type="text" name="wb_gam_new_rule[role]" class="regular-text" value="" placeholder="contributor" /></td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Message sender user ID', 'wb-gamification' ); ?>
						<span style="color:#999;font-size:0.85em;"><?php esc_html_e( '(for "Send message")', 'wb-gamification' ); ?></span>
					</th>
					<td><input type="number" name="wb_gam_new_rule[sender_id]" class="small-text" min="1" value="1" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Message subject', 'wb-gamification' ); ?></th>
					<td><input type="text" name="wb_gam_new_rule[subject]" class="regular-text" value="" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Message content', 'wb-gamification' ); ?></th>
					<td><textarea name="wb_gam_new_rule[content]" rows="4" class="large-text"></textarea></td>
				</tr>
			</table>

			<?php submit_button( __( 'Add Rule', 'wb-gamification' ) ); ?>
		</form>
		<?php
	}

	// ── Mode badge ────────────────────────────────────────────────────────────

	private static function render_mode_badge(): void {
		$bp_active = function_exists( 'buddypress' );

		if ( $bp_active ) {
			$mode  = __( 'Community Mode', 'wb-gamification' );
			$color = '#00a32a';
		} else {
			$mode  = __( 'Standalone Mode', 'wb-gamification' );
			$color = '#0073aa';
		}

		printf(
			'<span style="background:%s;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;">%s</span>',
			esc_attr( $color ),
			esc_html( $mode )
		);
	}
}
