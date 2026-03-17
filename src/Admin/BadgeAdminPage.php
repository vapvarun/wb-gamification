<?php
/**
 * Admin: Badge Library Manager
 *
 * Adds "Badges" submenu under WB Gamification.
 * Lets admins create, edit, and delete badge definitions and their
 * auto-award conditions — no code required.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

final class BadgeAdminPage {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_post_wb_gam_save_badge', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_delete_badge', array( __CLASS__, 'handle_delete' ) );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Badge Library', 'wb-gamification' ),
			__( 'Badges', 'wb-gamification' ),
			'manage_options',
			'wb-gamification-badges',
			array( __CLASS__, 'render_page' )
		);
	}

	// ── Page render ─────────────────────────────────────────────────────────

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET params used for routing/display only, no data modification.
		$action   = sanitize_key( $_GET['action'] ?? 'list' );
		$badge_id = sanitize_key( $_GET['badge'] ?? '' );
		$notice   = sanitize_key( $_GET['notice'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notice_map = array(
			'saved'   => array( 'success', __( 'Badge saved.', 'wb-gamification' ) ),
			'deleted' => array( 'success', __( 'Badge deleted.', 'wb-gamification' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		?>
		<div class="wrap wb-gam-badge-admin">
			<h1>
				<?php esc_html_e( 'Badge Library', 'wb-gamification' ); ?>
				<?php if ( 'list' === $action ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges&action=new' ) ); ?>" class="page-title-action">
						<?php esc_html_e( '+ Add Badge', 'wb-gamification' ); ?>
					</a>
				<?php endif; ?>
			</h1>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'edit' === $action || 'new' === $action ) : ?>
				<?php self::render_form( $badge_id ); ?>
			<?php else : ?>
				<?php self::render_list(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── List view ────────────────────────────────────────────────────────────

	private static function render_list(): void {
		global $wpdb;

		$badges = $wpdb->get_results(
			"SELECT b.id, b.name, b.description, b.category, b.is_credential,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges ub WHERE ub.badge_id = b.id) AS earned_count,
			        (SELECT rule_config FROM {$wpdb->prefix}wb_gam_rules r WHERE r.rule_type = 'badge_condition' AND r.target_id = b.id AND r.is_active = 1 LIMIT 1) AS condition
			   FROM {$wpdb->prefix}wb_gam_badge_defs b
			  ORDER BY b.category, b.name",
			ARRAY_A
		) ?: array();

		$categories = array_unique( array_column( $badges, 'category' ) );
		sort( $categories );

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Badge', 'wb-gamification' ); ?></th>
					<th><?php esc_html_e( 'Category', 'wb-gamification' ); ?></th>
					<th><?php esc_html_e( 'Condition', 'wb-gamification' ); ?></th>
					<th><?php esc_html_e( 'Credential', 'wb-gamification' ); ?></th>
					<th><?php esc_html_e( 'Earned By', 'wb-gamification' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $badges as $badge ) : ?>
					<?php
					$config     = json_decode( $badge['condition'] ?? '{}', true ) ?: array();
					$cond_type  = $config['condition_type'] ?? 'admin_awarded';
					$cond_label = match ( $cond_type ) {
						'point_milestone' => sprintf( __( '%s pts', 'wb-gamification' ), number_format_i18n( $config['points'] ?? 0 ) ),
						'action_count'    => sprintf( __( '%1$s × %2$d', 'wb-gamification' ), $config['action_id'] ?? '', $config['count'] ?? 1 ),
						default           => __( 'Admin awarded', 'wb-gamification' ),
					};
	?>
				<tr>
					<td>
						<strong><?php echo esc_html( $badge['name'] ); ?></strong>
						<br><small style="color:#999"><?php echo esc_html( $badge['id'] ); ?></small>
					</td>
					<td><?php echo esc_html( $badge['category'] ); ?></td>
					<td><?php echo esc_html( $cond_label ); ?></td>
					<td><?php echo $badge['is_credential'] ? '✅' : '—'; ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $badge['earned_count'] ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges&action=edit&badge=' . $badge['id'] ) ); ?>">
							<?php esc_html_e( 'Edit', 'wb-gamification' ); ?>
						</a>
						&nbsp;|&nbsp;
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wb_gam_delete_badge&badge=' . $badge['id'] ), 'wb_gam_delete_badge_' . $badge['id'] ) ); ?>"
							onclick="return confirm('<?php esc_attr_e( 'Delete this badge and all earned records?', 'wb-gamification' ); ?>')"
							style="color:#a00">
							<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
				<?php if ( empty( $badges ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No badges defined yet.', 'wb-gamification' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	// ── Edit / Create form ──────────────────────────────────────────────────

	private static function render_form( string $badge_id ): void {
		global $wpdb;

		$badge     = array();
		$condition = array( 'condition_type' => 'admin_awarded' );
		$is_new    = empty( $badge_id );

		if ( ! $is_new ) {
			$badge = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = %s",
					$badge_id
				),
				ARRAY_A
			);

			if ( ! $badge ) {
				echo '<p>' . esc_html__( 'Badge not found.', 'wb-gamification' ) . '</p>';
				return;
			}

			$rule = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT rule_config FROM {$wpdb->prefix}wb_gam_rules
					  WHERE rule_type = 'badge_condition' AND target_id = %s AND is_active = 1
					  LIMIT 1",
					$badge_id
				),
				ARRAY_A
			);

			if ( $rule ) {
				$condition = json_decode( $rule['rule_config'], true ) ?: $condition;
			}
		}

		$back_url = admin_url( 'admin.php?page=wb-gamification-badges' );
		?>
		<a href="<?php echo esc_url( $back_url ); ?>" style="display:inline-block;margin-bottom:1rem;">← <?php esc_html_e( 'Back to badges', 'wb-gamification' ); ?></a>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px">
			<?php wp_nonce_field( 'wb_gam_save_badge', 'wb_gam_badge_nonce' ); ?>
			<input type="hidden" name="action" value="wb_gam_save_badge">
			<input type="hidden" name="original_id" value="<?php echo esc_attr( $badge_id ); ?>">

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Badge ID', 'wb-gamification' ); ?></th>
					<td>
						<input type="text" name="badge_id" class="regular-text"
							value="<?php echo esc_attr( $badge['id'] ?? '' ); ?>"
							placeholder="e.g. first_post"
							<?php echo $is_new ? '' : 'readonly'; ?>>
						<?php if ( ! $is_new ) : ?>
							<p class="description"><?php esc_html_e( 'ID cannot be changed after creation.', 'wb-gamification' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores only.', 'wb-gamification' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Name', 'wb-gamification' ); ?></th>
					<td><input type="text" name="badge_name" class="regular-text" value="<?php echo esc_attr( $badge['name'] ?? '' ); ?>" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Description', 'wb-gamification' ); ?></th>
					<td><textarea name="badge_description" rows="3" class="large-text"><?php echo esc_textarea( $badge['description'] ?? '' ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Image URL', 'wb-gamification' ); ?></th>
					<td>
						<input type="url" name="badge_image_url" class="large-text" value="<?php echo esc_attr( $badge['image_url'] ?? '' ); ?>" placeholder="https://…">
						<p class="description"><?php esc_html_e( 'Leave blank to use the default badge icon.', 'wb-gamification' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Category', 'wb-gamification' ); ?></th>
					<td>
						<select name="badge_category">
							<?php foreach ( array( 'general', 'points', 'wordpress', 'buddypress', 'special' ) as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $badge['category'] ?? 'general', $cat ); ?>>
									<?php echo esc_html( ucfirst( $cat ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Is Credential', 'wb-gamification' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="badge_is_credential" value="1" <?php checked( ! empty( $badge['is_credential'] ) ); ?>>
							<?php esc_html_e( 'Mark as shareable credential (LinkedIn, OpenBadges)', 'wb-gamification' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Auto-Award Condition', 'wb-gamification' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Condition Type', 'wb-gamification' ); ?></th>
					<td>
						<select name="condition_type" id="wb-gam-condition-type" onchange="wbGamToggleConditionFields(this.value)">
							<option value="admin_awarded" <?php selected( $condition['condition_type'], 'admin_awarded' ); ?>><?php esc_html_e( 'Admin awarded only (manual)', 'wb-gamification' ); ?></option>
							<option value="point_milestone" <?php selected( $condition['condition_type'], 'point_milestone' ); ?>><?php esc_html_e( 'Point milestone — user reaches N cumulative points', 'wb-gamification' ); ?></option>
							<option value="action_count" <?php selected( $condition['condition_type'], 'action_count' ); ?>><?php esc_html_e( 'Action count — user performs action N times', 'wb-gamification' ); ?></option>
						</select>
					</td>
				</tr>
				<tr id="wb-gam-field-points" style="<?php echo 'point_milestone' === $condition['condition_type'] ? '' : 'display:none'; ?>">
					<th><?php esc_html_e( 'Points Threshold', 'wb-gamification' ); ?></th>
					<td><input type="number" name="condition_points" min="1" value="<?php echo esc_attr( $condition['points'] ?? 100 ); ?>"></td>
				</tr>
				<tr id="wb-gam-field-action" style="<?php echo 'action_count' === $condition['condition_type'] ? '' : 'display:none'; ?>">
					<th><?php esc_html_e( 'Action ID', 'wb-gamification' ); ?></th>
					<td>
						<input type="text" name="condition_action_id" class="regular-text" value="<?php echo esc_attr( $condition['action_id'] ?? '' ); ?>" placeholder="e.g. wp_publish_post">
						<p class="description"><?php esc_html_e( 'Must match a registered action ID.', 'wb-gamification' ); ?></p>
					</td>
				</tr>
				<tr id="wb-gam-field-count" style="<?php echo 'action_count' === $condition['condition_type'] ? '' : 'display:none'; ?>">
					<th><?php esc_html_e( 'Required Count', 'wb-gamification' ); ?></th>
					<td><input type="number" name="condition_count" min="1" value="<?php echo esc_attr( $condition['count'] ?? 1 ); ?>"></td>
				</tr>
			</table>

			<script>
			function wbGamToggleConditionFields(type) {
				document.getElementById('wb-gam-field-points').style.display  = type === 'point_milestone' ? '' : 'none';
				document.getElementById('wb-gam-field-action').style.display  = type === 'action_count' ? '' : 'none';
				document.getElementById('wb-gam-field-count').style.display   = type === 'action_count' ? '' : 'none';
			}
			</script>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php echo $is_new ? esc_html__( 'Create Badge', 'wb-gamification' ) : esc_html__( 'Save Changes', 'wb-gamification' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	// ── Form handlers ────────────────────────────────────────────────────────

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		check_admin_referer( 'wb_gam_save_badge', 'wb_gam_badge_nonce' );

		global $wpdb;

		$badge_id    = sanitize_key( $_POST['badge_id'] ?? '' );
		$original_id = sanitize_key( $_POST['original_id'] ?? '' );
		$is_new      = empty( $original_id );

		if ( ! $badge_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification-badges&notice=error' ) );
			exit;
		}

		$badge_data = array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['badge_name'] ?? '' ) ),
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['badge_description'] ?? '' ) ),
			'image_url'     => esc_url_raw( wp_unslash( $_POST['badge_image_url'] ?? '' ) ),
			'category'      => sanitize_key( $_POST['badge_category'] ?? 'general' ),
			'is_credential' => ! empty( $_POST['badge_is_credential'] ) ? 1 : 0,
		);

		if ( $is_new ) {
			$badge_data['id'] = $badge_id;
			$wpdb->insert( $wpdb->prefix . 'wb_gam_badge_defs', $badge_data );
		} else {
			$wpdb->update( $wpdb->prefix . 'wb_gam_badge_defs', $badge_data, array( 'id' => $original_id ) );
		}

		// Save / update condition rule.
		$condition_type = sanitize_key( $_POST['condition_type'] ?? 'admin_awarded' );
		$config         = array( 'condition_type' => $condition_type );

		if ( 'point_milestone' === $condition_type ) {
			$config['points'] = absint( $_POST['condition_points'] ?? 100 );
		} elseif ( 'action_count' === $condition_type ) {
			$config['action_id'] = sanitize_key( $_POST['condition_action_id'] ?? '' );
			$config['count']     = absint( $_POST['condition_count'] ?? 1 );
		}

		// Delete existing condition rules for this badge, then re-insert.
		$wpdb->delete(
			$wpdb->prefix . 'wb_gam_rules',
			array(
				'rule_type' => 'badge_condition',
				'target_id' => $badge_id,
			),
			array( '%s', '%s' )
		);

		if ( 'admin_awarded' !== $condition_type ) {
			$wpdb->insert(
				$wpdb->prefix . 'wb_gam_rules',
				array(
					'rule_type'   => 'badge_condition',
					'target_id'   => $badge_id,
					'rule_config' => wp_json_encode( $config ),
					'is_active'   => 1,
				),
				array( '%s', '%s', '%s', '%d' )
			);
		}

		wp_cache_delete( 'wb_gam_badge_rules', 'wb_gamification' );

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification-badges&notice=saved' ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		$badge_id = sanitize_key( $_GET['badge'] ?? '' );
		check_admin_referer( 'wb_gam_delete_badge_' . $badge_id );

		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'wb_gam_badge_defs', array( 'id' => $badge_id ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'wb_gam_user_badges', array( 'badge_id' => $badge_id ), array( '%s' ) );
		$wpdb->delete(
			$wpdb->prefix . 'wb_gam_rules',
			array(
				'rule_type' => 'badge_condition',
				'target_id' => $badge_id,
			),
			array( '%s', '%s' )
		);

		wp_cache_delete( 'wb_gam_badge_rules', 'wb_gamification' );

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification-badges&notice=deleted' ) );
		exit;
	}
}
