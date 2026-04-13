<?php
/**
 * Admin: Redemption Store
 *
 * Adds "Redemption Store" submenu under WB Gamification.
 * Lets admins create, edit, and delete reward items that
 * members can purchase with earned points.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Redemption Store admin page for creating, editing, and deleting reward items.
 *
 * @package WB_Gamification
 */
final class RedemptionStorePage {

	/**
	 * Register admin_menu and admin-post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_wb_gam_save_reward', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_delete_reward', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the Redemption Store submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Redemption Store', 'wb-gamification' ),
			__( 'Redemption Store', 'wb-gamification' ),
			'manage_options',
			'wb-gam-redemption',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the redemption store page with create/edit form and reward list.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_redemption_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin list view, infrequent.
		$items = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed constant.
			"SELECT * FROM {$table} ORDER BY id DESC",
			ARRAY_A
		) ?: array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET params for routing only.
		$editing = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$notice  = sanitize_key( $_GET['notice'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$edit_data = null;
		if ( $editing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Single row edit lookup.
			$edit_data = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed constant.
					"SELECT * FROM {$table} WHERE id = %d",
					$editing
				),
				ARRAY_A
			);
		}

		$notice_map = array(
			'saved'   => array( 'success', __( 'Reward item saved.', 'wb-gamification' ) ),
			'deleted' => array( 'success', __( 'Reward item deleted.', 'wb-gamification' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		?>
		<div class="wrap wbgam-wrap">
			<h1 class="wbgam-page-title"><?php esc_html_e( 'Redemption Store', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Create rewards that members can purchase with earned points.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Create/Edit Form Card -->
			<div class="wbgam-card" style="margin-bottom:24px;">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<?php echo $editing ? esc_html__( 'Edit Reward', 'wb-gamification' ) : esc_html__( 'Add Reward', 'wb-gamification' ); ?>
					</h3>
				</div>
				<div class="wbgam-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wb_gam_save_reward', 'wb_gam_reward_nonce' ); ?>
						<input type="hidden" name="action" value="wb_gam_save_reward">
						<input type="hidden" name="reward_id" value="<?php echo esc_attr( $editing ); ?>">

						<table class="form-table">
							<tr>
								<th><label for="wb-gam-reward-title"><?php esc_html_e( 'Name', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" name="title" id="wb-gam-reward-title" class="regular-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['title'] ?? '' ); ?>"
										required placeholder="<?php esc_attr_e( 'e.g. 10% Off Coupon', 'wb-gamification' ); ?>">
									<p class="description"><?php esc_html_e( 'A short name for this reward, shown to members in the store.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-reward-desc"><?php esc_html_e( 'Description', 'wb-gamification' ); ?></label></th>
								<td>
									<textarea name="description" id="wb-gam-reward-desc" class="large-text wbgam-input" rows="3"
										placeholder="<?php esc_attr_e( 'Describe what the member receives when they redeem this reward.', 'wb-gamification' ); ?>"><?php echo esc_textarea( $edit_data['description'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Optional description displayed on the reward card.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-reward-cost"><?php esc_html_e( 'Point Cost', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="points_cost" id="wb-gam-reward-cost" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['points_cost'] ?? '100' ); ?>" min="1">
									<p class="description"><?php esc_html_e( 'How many points a member must spend to redeem this reward.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-reward-stock"><?php esc_html_e( 'Stock', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="stock" id="wb-gam-reward-stock" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['stock'] ?? '0' ); ?>" min="0">
									<p class="description"><?php esc_html_e( 'Number of available redemptions. Set to 0 for unlimited stock.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-reward-type"><?php esc_html_e( 'Reward Type', 'wb-gamification' ); ?></label></th>
								<td>
									<select name="reward_type" id="wb-gam-reward-type" class="wbgam-select">
										<option value="custom" <?php selected( $edit_data['reward_type'] ?? 'custom', 'custom' ); ?>>
											<?php esc_html_e( 'Custom Reward', 'wb-gamification' ); ?>
										</option>
										<option value="discount_pct" <?php selected( $edit_data['reward_type'] ?? '', 'discount_pct' ); ?>>
											<?php esc_html_e( 'WooCommerce — Percentage Discount', 'wb-gamification' ); ?>
										</option>
										<option value="discount_fixed" <?php selected( $edit_data['reward_type'] ?? '', 'discount_fixed' ); ?>>
											<?php esc_html_e( 'WooCommerce — Fixed Discount', 'wb-gamification' ); ?>
										</option>
									</select>
									<p class="description"><?php esc_html_e( 'Custom rewards are fulfilled via hook. WooCommerce rewards auto-generate a coupon code.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-reward-status"><?php esc_html_e( 'Status', 'wb-gamification' ); ?></label></th>
								<td>
									<select name="is_active" id="wb-gam-reward-status" class="wbgam-select">
										<option value="1" <?php selected( $edit_data['is_active'] ?? '1', '1' ); ?>>
											<?php esc_html_e( 'Active', 'wb-gamification' ); ?>
										</option>
										<option value="0" <?php selected( $edit_data['is_active'] ?? '1', '0' ); ?>>
											<?php esc_html_e( 'Inactive', 'wb-gamification' ); ?>
										</option>
									</select>
									<p class="description"><?php esc_html_e( 'Inactive rewards are hidden from the store but preserved in the database.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p>
							<button type="submit" class="wbgam-btn">
								<?php echo $editing ? esc_html__( 'Update Reward', 'wb-gamification' ) : esc_html__( 'Add Reward', 'wb-gamification' ); ?>
							</button>
							<?php if ( $editing ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-redemption' ) ); ?>" class="wbgam-btn wbgam-btn--secondary" style="margin-left:8px;">
									<?php esc_html_e( 'Cancel', 'wb-gamification' ); ?>
								</a>
							<?php endif; ?>
						</p>
					</form>
				</div>
			</div>

			<!-- Reward Items List -->
			<?php if ( ! empty( $items ) ) : ?>
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'All Rewards', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body" style="padding:0;">
					<table class="wbgam-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Point Cost', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Type', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Stock', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $items as $item ) : ?>
							<?php
							$type_labels = array(
								'custom'         => __( 'Custom', 'wb-gamification' ),
								'discount_pct'   => __( '% Discount', 'wb-gamification' ),
								'discount_fixed' => __( 'Fixed Discount', 'wb-gamification' ),
							);
							$type_label  = $type_labels[ $item['reward_type'] ] ?? $item['reward_type'];
							$status_class = $item['is_active'] ? 'active' : 'info';
							$stock_label  = ( null === $item['stock'] || 0 === (int) $item['stock'] )
								? __( 'Unlimited', 'wb-gamification' )
								: (int) $item['stock'];
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php if ( ! empty( $item['description'] ) ) : ?>
										<br><small style="color:var(--wb-gam-muted);"><?php echo esc_html( wp_trim_words( $item['description'], 12 ) ); ?></small>
									<?php endif; ?>
								</td>
								<td><strong><?php echo esc_html( number_format_i18n( $item['points_cost'] ) ); ?></strong> <?php esc_html_e( 'pts', 'wb-gamification' ); ?></td>
								<td><code><?php echo esc_html( $type_label ); ?></code></td>
								<td><?php echo esc_html( $stock_label ); ?></td>
								<td>
									<span class="wbgam-pill wbgam-pill--<?php echo esc_attr( $status_class ); ?>">
										<?php echo $item['is_active'] ? esc_html__( 'Active', 'wb-gamification' ) : esc_html__( 'Inactive', 'wb-gamification' ); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-redemption&edit=' . $item['id'] ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary">
										<?php esc_html_e( 'Edit', 'wb-gamification' ); ?>
									</a>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wb_gam_delete_reward&reward_id=' . $item['id'] ), 'wb_gam_delete_reward_' . $item['id'] ) ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Delete this reward?', 'wb-gamification' ); ?>')"
										class="wbgam-btn wbgam-btn--sm wbgam-btn--danger" style="margin-left:4px;">
										<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-cart" style="font-size:48px;width:48px;height:48px;color:var(--wb-gam-locked);"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No rewards yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Create your first reward above to open the redemption store for your members.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the reward create/update form submission via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wb_gam_save_reward', 'wb_gam_reward_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_redemption_items';
		$id    = absint( $_POST['reward_id'] ?? 0 );

		$stock_raw = absint( $_POST['stock'] ?? 0 );

		$data = array(
			'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'points_cost' => max( 1, absint( $_POST['points_cost'] ?? 100 ) ),
			'reward_type' => sanitize_key( $_POST['reward_type'] ?? 'custom' ),
			'stock'       => 0 === $stock_raw ? null : $stock_raw,
			'is_active'   => absint( $_POST['is_active'] ?? 1 ) ? 1 : 0,
		);

		$formats = array( '%s', '%s', '%d', '%s', '%d', '%d' );

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row update.
			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

			/**
			 * Fires after a redemption reward item is updated by an admin.
			 *
			 * @since 1.0.0
			 * @param int   $item_id Item ID.
			 * @param array $data    Item data that was saved.
			 */
			do_action( 'wb_gamification_reward_updated', $id, $data );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row insert.
			$wpdb->insert( $table, $data, $formats );
			$new_id = (int) $wpdb->insert_id;

			/**
			 * Fires after a new redemption reward item is created by an admin.
			 *
			 * @since 1.0.0
			 * @param int   $item_id New item ID.
			 * @param array $data    Item data.
			 */
			do_action( 'wb_gamification_reward_created', $new_id, $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-redemption&notice=saved' ) );
		exit;
	}

	/**
	 * Handle the reward delete action via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = absint( $_GET['reward_id'] ?? $_POST['reward_id'] ?? 0 );
		check_admin_referer( 'wb_gam_delete_reward_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		global $wpdb;

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row delete.
			$wpdb->delete( $wpdb->prefix . 'wb_gam_redemption_items', array( 'id' => $id ), array( '%d' ) );

			/**
			 * Fires after a redemption reward item is deleted by an admin.
			 *
			 * @since 1.0.0
			 * @param int $item_id The deleted item ID.
			 */
			do_action( 'wb_gamification_reward_deleted', $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-redemption&notice=deleted' ) );
		exit;
	}
}
