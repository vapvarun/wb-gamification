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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_{save,delete}_reward removed in 1.0.0 — page now
		// consumes /wb-gamification/v1/redemptions/items via the generic
		// admin-rest-form driver. See Tier 0.C migration.
	}

	/**
	 * Enqueue REST-driven JS bundle on the Redemption Store admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gam-redemption' !== $hook_suffix ) {
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
			'wbGamRedemptionSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Reward saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the reward.', 'wb-gamification' ),
				),
			)
		);

		// Reward-type field toggle (replaces the legacy inline <script>).
		// Matching CSS rules (.wb-gam-config-row.is-visible etc.) live in the
		// shared assets/css/admin.css, so no separate stylesheet to enqueue.
		wp_enqueue_script(
			'wb-gam-admin-reward-type-toggle',
			plugins_url( 'assets/js/admin-reward-type-toggle.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
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
			'wb_gam_manage_rewards',
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

		$edit_config = array();
		if ( $edit_data && ! empty( $edit_data['reward_config'] ) ) {
			$decoded     = json_decode( $edit_data['reward_config'], true );
			$edit_config = is_array( $decoded ) ? $decoded : array();
		}

		$woo_active     = class_exists( '\WC_Coupon' );
		$credits_active = class_exists( '\Wbcom\Credits\Credits' );
		$credits_slugs  = array();
		if ( $credits_active && class_exists( '\Wbcom\Credits\Registry' ) ) {
			$credits_slugs = \Wbcom\Credits\Registry::instance()->get_slugs();
		}

		$notice_map = array(
			'saved'   => array( 'success', __( 'Reward item saved.', 'wb-gamification' ) ),
			'deleted' => array( 'success', __( 'Reward item deleted.', 'wb-gamification' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Redemption Store', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Create rewards that members can purchase with earned points.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="wbgam-banner wbgam-banner--<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> wbgam-stack-block" role="status" aria-live="polite"><span class="wbgam-banner__icon icon-check-circle" aria-hidden="true"></span><div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p></div></div>
			<?php endif; ?>

			<!-- Create/Edit Form Card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<?php echo $editing ? esc_html__( 'Edit Reward', 'wb-gamification' ) : esc_html__( 'Add Reward', 'wb-gamification' ); ?>
					</h3>
				</div>
				<div class="wbgam-card-body">
					<?php
					$reward_rest_path = $editing ? '/redemptions/items/' . (int) $editing : '/redemptions/items';
					?>
					<form
						data-wb-gam-rest-form="wbGamRedemptionSettings"
						data-wb-gam-rest-method="POST"
						data-wb-gam-rest-path="<?php echo esc_attr( $reward_rest_path ); ?>"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Reward saved.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to save the reward.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload"
					>

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
											<?php esc_html_e( 'Custom Reward (fulfilled via hook)', 'wb-gamification' ); ?>
										</option>
										<?php if ( $woo_active ) : ?>
											<option value="discount_pct" <?php selected( $edit_data['reward_type'] ?? '', 'discount_pct' ); ?>>
												<?php esc_html_e( 'WooCommerce — Percentage Discount', 'wb-gamification' ); ?>
											</option>
											<option value="discount_fixed" <?php selected( $edit_data['reward_type'] ?? '', 'discount_fixed' ); ?>>
												<?php esc_html_e( 'WooCommerce — Fixed Discount', 'wb-gamification' ); ?>
											</option>
											<option value="free_shipping" <?php selected( $edit_data['reward_type'] ?? '', 'free_shipping' ); ?>>
												<?php esc_html_e( 'WooCommerce — Free Shipping', 'wb-gamification' ); ?>
											</option>
											<option value="free_product" <?php selected( $edit_data['reward_type'] ?? '', 'free_product' ); ?>>
												<?php esc_html_e( 'WooCommerce — Free Product', 'wb-gamification' ); ?>
											</option>
										<?php endif; ?>
										<?php if ( $credits_active ) : ?>
											<option value="wbcom_credits" <?php selected( $edit_data['reward_type'] ?? '', 'wbcom_credits' ); ?>>
												<?php esc_html_e( 'Wbcom Credits — Top up balance', 'wb-gamification' ); ?>
											</option>
										<?php endif; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Custom rewards fire wb_gam_points_redeemed for your code to listen on. WooCommerce rewards auto-generate a coupon. Wbcom Credits adds to a registered SDK ledger.', 'wb-gamification' ); ?>
										<?php if ( ! $woo_active ) : ?>
											<br><em><?php esc_html_e( 'WooCommerce is not active — coupon-based rewards are hidden.', 'wb-gamification' ); ?></em>
										<?php endif; ?>
										<?php if ( ! $credits_active ) : ?>
											<br><em><?php esc_html_e( 'Wbcom Credits SDK is not loaded by any active plugin — credit topup is hidden.', 'wb-gamification' ); ?></em>
										<?php endif; ?>
									</p>
								</td>
							</tr>
							<tr class="wb-gam-config-row" data-show-for="discount_pct discount_fixed">
								<th><label for="wb-gam-cfg-amount"><?php esc_html_e( 'Discount amount', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="reward_config[amount]" id="wb-gam-cfg-amount" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_config['amount'] ?? '10' ); ?>" min="1" step="0.01">
									<p class="description">
										<span class="wb-gam-cfg-hint" data-hint-for="discount_pct"><?php esc_html_e( 'Percentage off the cart (e.g. 10 = 10%).', 'wb-gamification' ); ?></span>
										<span class="wb-gam-cfg-hint" data-hint-for="discount_fixed"><?php esc_html_e( 'Fixed amount off the cart in store currency.', 'wb-gamification' ); ?></span>
									</p>
								</td>
							</tr>
							<tr class="wb-gam-config-row" data-show-for="free_product">
								<th><label for="wb-gam-cfg-product"><?php esc_html_e( 'WooCommerce product ID', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="reward_config[product_id]" id="wb-gam-cfg-product" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_config['product_id'] ?? '' ); ?>" min="1">
									<p class="description"><?php esc_html_e( 'Numeric ID of the product to give away free. Member receives a single-use full-discount coupon scoped to this product.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr class="wb-gam-config-row" data-show-for="wbcom_credits">
								<th><label for="wb-gam-cfg-slug"><?php esc_html_e( 'Credits destination', 'wb-gamification' ); ?></label></th>
								<td>
									<?php if ( ! empty( $credits_slugs ) ) : ?>
										<select name="reward_config[slug]" id="wb-gam-cfg-slug" class="wbgam-select">
											<?php foreach ( $credits_slugs as $slug_option ) : ?>
												<option value="<?php echo esc_attr( $slug_option ); ?>" <?php selected( $edit_config['slug'] ?? '', $slug_option ); ?>>
													<?php echo esc_html( $slug_option ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									<?php else : ?>
										<input type="text" name="reward_config[slug]" id="wb-gam-cfg-slug" class="regular-text wbgam-input"
											value="<?php echo esc_attr( $edit_config['slug'] ?? '' ); ?>"
											placeholder="<?php esc_attr_e( 'plugin-slug-here', 'wb-gamification' ); ?>">
									<?php endif; ?>
									<p class="description"><?php esc_html_e( 'Which plugin\'s credit ledger to top up. Each plugin that uses the Wbcom Credits SDK registers its own slug.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr class="wb-gam-config-row" data-show-for="wbcom_credits">
								<th><label for="wb-gam-cfg-credits"><?php esc_html_e( 'Credits to add', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="reward_config[credits]" id="wb-gam-cfg-credits" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_config['amount'] ?? '100' ); ?>" min="1" step="1">
									<p class="description"><?php esc_html_e( 'How many credits to deposit into the ledger when this reward is redeemed.', 'wb-gamification' ); ?></p>
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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-redemption' ) ); ?>" class="wbgam-btn wbgam-btn--secondary wbgam-ms-sm">
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
				<div class="wbgam-card-body wbgam-card-body--flush">
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
						<?php
						// Pre-fetch the currency label map so each row's cost
						// suffix matches the reward's actual point_type.
						$pt_service   = new \WBGam\Services\PointTypeService();
						$pt_label_map = array();
						foreach ( $pt_service->list() as $pt ) {
							$pt_label_map[ (string) $pt['slug'] ] = (string) $pt['label'];
						}
						?>
						<?php foreach ( $items as $item ) : ?>
							<?php
							$type_labels  = array(
								'custom'         => __( 'Custom', 'wb-gamification' ),
								'discount_pct'   => __( '% Discount', 'wb-gamification' ),
								'discount_fixed' => __( 'Fixed Discount', 'wb-gamification' ),
								'free_shipping'  => __( 'Free Shipping', 'wb-gamification' ),
								'free_product'   => __( 'Free Product', 'wb-gamification' ),
								'wbcom_credits'  => __( 'Wbcom Credits', 'wb-gamification' ),
							);
							$type_label   = $type_labels[ $item['reward_type'] ] ?? $item['reward_type'];
							$status_class = $item['is_active'] ? 'active' : 'info';
							$stock_label  = ( null === $item['stock'] || 0 === (int) $item['stock'] )
								? __( 'Unlimited', 'wb-gamification' )
								: (int) $item['stock'];
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php if ( ! empty( $item['description'] ) ) : ?>
										<br><small class="wbgam-text-muted"><?php echo esc_html( wp_trim_words( $item['description'], 12 ) ); ?></small>
									<?php endif; ?>
								</td>
								<?php
								$item_slug  = (string) ( $item['point_type'] ?? $pt_service->default_slug() );
								$item_label = $pt_label_map[ $item_slug ] ?? $item_slug;
								?>
								<td><strong><?php echo esc_html( number_format_i18n( $item['points_cost'] ) ); ?></strong> <?php echo esc_html( $item_label ); ?></td>
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
									<button
										type="button"
										class="wbgam-btn wbgam-btn--sm wbgam-btn--danger"
										class="wbgam-ms-xs"
										data-wb-gam-rest-action="wbGamRedemptionSettings"
										data-wb-gam-rest-method="DELETE"
										data-wb-gam-rest-path="/redemptions/items/<?php echo (int) $item['id']; ?>"
										data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this reward?', 'wb-gamification' ); ?>"
										data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Reward deleted.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to delete reward.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-after="remove-row"
									>
										<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="icon-shopping-cart wbgam-icon-xl"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No rewards yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Create your first reward above to open the redemption store for your members.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
		// Reward-type toggle CSS  → assets/css/admin-redemption.css
		// Reward-type toggle JS   → assets/js/admin-reward-type-toggle.js
		// Both are enqueued via enqueue_assets() — never inline.
	}

	// handle_save() / handle_delete() removed in 1.0.0 (Tier 0.C). Rewards are
	// now written by RedemptionController (POST /redemptions/items + POST
	// /redemptions/items/{id}; DELETE /redemptions/items/{id}). The reward
	// configuration is sent as a nested `reward_config` object on save.
}
