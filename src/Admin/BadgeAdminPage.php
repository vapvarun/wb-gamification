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

/**
 * Manages the Badge Library admin page for creating, editing, and deleting badges.
 *
 * @package WB_Gamification
 */
final class BadgeAdminPage {

	/**
	 * Register admin_menu and admin-post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_post_wb_gam_save_badge', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_delete_badge', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue scripts for the badges admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'wb-gamification-badges' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'wb-gam-admin-badge',
			WB_GAM_URL . 'assets/js/admin-badge.js',
			array( 'jquery' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-badge',
			'wbGamBadgeAdmin',
			array(
				'chooseIcon' => __( 'Choose Badge Icon', 'wb-gamification' ),
				'useIcon'    => __( 'Use as Icon', 'wb-gamification' ),
			)
		);
	}

	/**
	 * Register the Badges submenu page under WB Gamification.
	 *
	 * @return void
	 */
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

	/**
	 * Render the badge library page with premium grid layout and inline form.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET params used for routing/display only, no data modification.
		$editing  = sanitize_key( $_GET['badge'] ?? '' );
		$notice   = sanitize_key( $_GET['notice'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notice_map = array(
			'saved'   => array( 'success', __( 'Badge saved.', 'wb-gamification' ) ),
			'deleted' => array( 'success', __( 'Badge deleted.', 'wb-gamification' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		// Load all badges for the grid.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list view, infrequent, no caching needed.
		$badges = $wpdb->get_results(
			"SELECT b.id, b.name, b.description, b.image_url, b.category, b.is_credential,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges ub WHERE ub.badge_id = b.id) AS earned_count,
			        (SELECT rule_config FROM {$wpdb->prefix}wb_gam_rules r WHERE r.rule_type = 'badge_condition' AND r.target_id = b.id AND r.is_active = 1 LIMIT 1) AS `condition`
			   FROM {$wpdb->prefix}wb_gam_badge_defs b
			  ORDER BY b.category, b.name",
			ARRAY_A
		) ?: array();

		// Load edit data if editing.
		$badge     = array();
		$condition = array( 'condition_type' => 'admin_awarded' );
		$is_new    = empty( $editing );

		if ( ! $is_new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- single badge edit form, no caching needed.
			$badge = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = %s",
					$editing
				),
				ARRAY_A
			) ?: array();

			if ( ! empty( $badge ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- single badge edit form, no caching needed.
				$rule = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT rule_config FROM {$wpdb->prefix}wb_gam_rules
						  WHERE rule_type = 'badge_condition' AND target_id = %s AND is_active = 1
						  LIMIT 1",
						$editing
					),
					ARRAY_A
				);

				if ( $rule ) {
					$condition = json_decode( $rule['rule_config'], true ) ?: $condition;
				}
			}
		}

		// Determine if the inline form should be visible.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show_form = ! empty( $editing ) || isset( $_GET['action'] ) && 'new' === sanitize_key( $_GET['action'] );

		$actions = \WBGam\Engine\Registry::get_actions();

		?>
		<div class="wrap wbgam-wrap">
			<h1 class="wbgam-page-title"><?php esc_html_e( 'Badge Library', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Create and manage badges to reward your community members for milestones and achievements.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Toolbar -->
			<div class="wbgam-toolbar">
				<div>
					<?php if ( ! $show_form ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges&action=new' ) ); ?>" class="wbgam-btn">
							+ <?php esc_html_e( 'Create New Badge', 'wb-gamification' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges' ) ); ?>" class="wbgam-btn wbgam-btn--secondary">
							<?php esc_html_e( 'Back to Badges', 'wb-gamification' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $show_form ) : ?>
			<!-- Inline Create/Edit Form -->
			<div class="wbgam-badge-form-panel">
				<div class="wbgam-card">
					<div class="wbgam-card-header">
						<h3 class="wbgam-card-title">
							<?php echo $is_new ? esc_html__( 'Create New Badge', 'wb-gamification' ) : esc_html__( 'Edit Badge', 'wb-gamification' ); ?>
						</h3>
					</div>
					<div class="wbgam-card-body">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'wb_gam_save_badge', 'wb_gam_badge_nonce' ); ?>
							<input type="hidden" name="action" value="wb_gam_save_badge">
							<input type="hidden" name="original_id" value="<?php echo esc_attr( $editing ); ?>">

							<table class="form-table">
								<?php if ( $is_new ) : ?>
								<tr>
									<th><label for="wb-gam-badge-id"><?php esc_html_e( 'Badge ID', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="text" name="badge_id" id="wb-gam-badge-id" class="regular-text wbgam-input"
											value="" placeholder="e.g. first_post">
										<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores only.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<?php else : ?>
								<tr>
									<th><label><?php esc_html_e( 'Badge ID', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="text" name="badge_id" class="regular-text wbgam-input" value="<?php echo esc_attr( $badge['id'] ?? '' ); ?>" readonly>
										<p class="description"><?php esc_html_e( 'ID cannot be changed after creation.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<?php endif; ?>
								<tr>
									<th><label for="wb-gam-badge-name"><?php esc_html_e( 'Name', 'wb-gamification' ); ?></label></th>
									<td><input type="text" name="badge_name" id="wb-gam-badge-name" class="regular-text wbgam-input" value="<?php echo esc_attr( $badge['name'] ?? '' ); ?>" required></td>
								</tr>
								<tr>
									<th><label for="wb-gam-badge-description"><?php esc_html_e( 'Description', 'wb-gamification' ); ?></label></th>
									<td><textarea name="badge_description" id="wb-gam-badge-description" rows="3" class="large-text wbgam-input"><?php echo esc_textarea( $badge['description'] ?? '' ); ?></textarea></td>
								</tr>
								<tr>
									<th><label><?php esc_html_e( 'Icon', 'wb-gamification' ); ?></label></th>
									<td>
										<div class="wbgam-badge-icon-preview" id="wb-gam-icon-preview">
											<?php if ( ! empty( $badge['image_url'] ) ) : ?>
												<img src="<?php echo esc_url( $badge['image_url'] ); ?>" alt="">
											<?php else : ?>
												<span class="dashicons dashicons-awards"></span>
											<?php endif; ?>
										</div>
										<input type="hidden" name="badge_image_url" id="wb-gam-badge-image-url" value="<?php echo esc_attr( $badge['image_url'] ?? '' ); ?>">
										<button type="button" class="wbgam-btn wbgam-btn--secondary wbgam-btn--sm" id="wb-gam-choose-icon">
											<?php esc_html_e( 'Choose Icon', 'wb-gamification' ); ?>
										</button>
										<?php if ( ! empty( $badge['image_url'] ) ) : ?>
											<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--danger" id="wb-gam-remove-icon" style="margin-left:4px;">
												<?php esc_html_e( 'Remove', 'wb-gamification' ); ?>
											</button>
										<?php else : ?>
											<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--danger" id="wb-gam-remove-icon" style="margin-left:4px;display:none;">
												<?php esc_html_e( 'Remove', 'wb-gamification' ); ?>
											</button>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><label for="wb-gam-badge-category"><?php esc_html_e( 'Category', 'wb-gamification' ); ?></label></th>
									<td>
										<select name="badge_category" id="wb-gam-badge-category" class="wbgam-select">
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
								<tr>
									<th><label for="wb-gam-badge-closes-at"><?php esc_html_e( 'Closes at', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="datetime-local" name="badge_closes_at" id="wb-gam-badge-closes-at" class="wbgam-input"
											value="<?php
											if ( ! empty( $badge['closes_at'] ) ) {
												$dt = new \DateTime( $badge['closes_at'], new \DateTimeZone( 'UTC' ) );
												$dt->setTimezone( wp_timezone() );
												echo esc_attr( $dt->format( 'Y-m-d\TH:i' ) );
											}
											?>">
										<p class="description">
											<?php esc_html_e( 'Stop awarding this badge after this date. Leave blank for no cutoff.', 'wb-gamification' ); ?>
											<?php
											printf(
												/* translators: %s: WordPress site timezone label */
												esc_html__( '(Site timezone: %s)', 'wb-gamification' ),
												esc_html( wp_timezone_string() )
											);
											?>
										</p>
									</td>
								</tr>
								<tr>
									<th><label for="wb-gam-badge-max-earners"><?php esc_html_e( 'Max earners', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="number" name="badge_max_earners" id="wb-gam-badge-max-earners" class="small-text wbgam-input"
											min="1" value="<?php echo esc_attr( $badge['max_earners'] ?? '' ); ?>">
										<p class="description"><?php esc_html_e( 'Stop awarding after this many members earn it. Leave blank for unlimited.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
							</table>

							<h3><?php esc_html_e( 'Auto-Award Condition', 'wb-gamification' ); ?></h3>
							<table class="form-table">
								<tr>
									<th><label for="wb-gam-condition-type"><?php esc_html_e( 'When user...', 'wb-gamification' ); ?></label></th>
									<td>
										<select name="condition_type" id="wb-gam-condition-type" class="wbgam-select" onchange="wbGamToggleConditionFields(this.value)">
											<option value="admin_awarded" <?php selected( $condition['condition_type'], 'admin_awarded' ); ?>><?php esc_html_e( 'Admin awarded only (manual)', 'wb-gamification' ); ?></option>
											<option value="point_milestone" <?php selected( $condition['condition_type'], 'point_milestone' ); ?>><?php esc_html_e( 'Reaches a point milestone', 'wb-gamification' ); ?></option>
											<option value="action_count" <?php selected( $condition['condition_type'], 'action_count' ); ?>><?php esc_html_e( 'Performs an action N times', 'wb-gamification' ); ?></option>
										</select>
									</td>
								</tr>
								<tr id="wb-gam-field-points" <?php echo 'point_milestone' !== $condition['condition_type'] ? 'class="wb-gam-hidden"' : ''; ?>>
									<th><label for="wb-gam-condition-points"><?php esc_html_e( 'Points Threshold', 'wb-gamification' ); ?></label></th>
									<td><input type="number" name="condition_points" id="wb-gam-condition-points" class="small-text wbgam-input" min="1" value="<?php echo esc_attr( $condition['points'] ?? 100 ); ?>"></td>
								</tr>
								<tr id="wb-gam-field-action" <?php echo 'action_count' !== $condition['condition_type'] ? 'class="wb-gam-hidden"' : ''; ?>>
									<th><label for="wb-gam-condition-action-id"><?php esc_html_e( 'Action', 'wb-gamification' ); ?></label></th>
									<td>
										<select name="condition_action_id" id="wb-gam-condition-action-id" class="wbgam-select">
											<?php foreach ( $actions as $action_id => $action_data ) : ?>
												<option value="<?php echo esc_attr( $action_id ); ?>" <?php selected( $condition['action_id'] ?? '', $action_id ); ?>>
													<?php echo esc_html( $action_data['label'] ?? $action_id ); ?>
												</option>
											<?php endforeach; ?>
											<?php if ( empty( $actions ) ) : ?>
												<option value=""><?php esc_html_e( 'No actions registered', 'wb-gamification' ); ?></option>
											<?php endif; ?>
										</select>
									</td>
								</tr>
								<tr id="wb-gam-field-count" <?php echo 'action_count' !== $condition['condition_type'] ? 'class="wb-gam-hidden"' : ''; ?>>
									<th><label for="wb-gam-condition-count"><?php esc_html_e( 'Target Count', 'wb-gamification' ); ?></label></th>
									<td><input type="number" name="condition_count" id="wb-gam-condition-count" class="small-text wbgam-input" min="1" value="<?php echo esc_attr( $condition['count'] ?? 1 ); ?>"></td>
								</tr>
							</table>

							<p>
								<button type="submit" class="wbgam-btn">
									<?php echo $is_new ? esc_html__( 'Create Badge', 'wb-gamification' ) : esc_html__( 'Save Changes', 'wb-gamification' ); ?>
								</button>
								<?php if ( ! $is_new ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wb_gam_delete_badge&badge=' . $editing ), 'wb_gam_delete_badge_' . $editing ) ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Delete this badge and all earned records?', 'wb-gamification' ); ?>')"
										class="wbgam-btn wbgam-btn--danger" style="margin-left:8px;">
										<?php esc_html_e( 'Delete Badge', 'wb-gamification' ); ?>
									</a>
								<?php endif; ?>
							</p>
						</form>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Badge Grid -->
			<?php if ( ! empty( $badges ) ) : ?>
			<div class="wbgam-grid-4">
				<?php foreach ( $badges as $b ) : ?>
					<?php
					$config     = json_decode( $b['condition'] ?? '{}', true ) ?: array();
					$cond_type  = $config['condition_type'] ?? 'admin_awarded';
					$has_rule   = 'admin_awarded' !== $cond_type;
					$edit_url   = admin_url( 'admin.php?page=wb-gamification-badges&badge=' . rawurlencode( $b['id'] ) );
					?>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="wbgam-badge-card" style="text-decoration:none;">
						<div class="wbgam-badge-card__icon">
							<?php if ( ! empty( $b['image_url'] ) ) : ?>
								<img src="<?php echo esc_url( $b['image_url'] ); ?>" alt="<?php echo esc_attr( $b['name'] ); ?>">
							<?php else : ?>
								<span class="dashicons dashicons-awards"></span>
							<?php endif; ?>
						</div>
						<p class="wbgam-badge-card__name"><?php echo esc_html( $b['name'] ); ?></p>
						<p class="wbgam-badge-card__earned">
							<?php
							printf(
								/* translators: %s: number of members who earned this badge */
								esc_html__( '%s earned', 'wb-gamification' ),
								esc_html( number_format_i18n( (int) $b['earned_count'] ) )
							);
							?>
						</p>
						<span class="wbgam-pill <?php echo $has_rule ? 'wbgam-pill--active' : 'wbgam-pill--inactive'; ?>">
							<?php echo $has_rule ? esc_html__( 'Auto-award', 'wb-gamification' ) : esc_html__( 'Manual', 'wb-gamification' ); ?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php elseif ( ! $show_form ) : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-awards" style="font-size:48px;width:48px;height:48px;color:var(--wb-gam-locked);"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No badges yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Badges reward members for milestones, actions, and achievements. Create your first badge to get started.', 'wb-gamification' ); ?></p>
				<p style="margin-top:1rem;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification-badges&action=new' ) ); ?>" class="wbgam-btn">
						<?php esc_html_e( 'Create your first badge', 'wb-gamification' ); ?>
					</a>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Form handlers ────────────────────────────────────────────────────────

	/**
	 * Handle the badge create/update form submission via admin-post.php.
	 *
	 * @return void
	 */
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

		$closes_at_raw = sanitize_text_field( wp_unslash( $_POST['badge_closes_at'] ?? '' ) );
		if ( $closes_at_raw ) {
			$dt        = \DateTime::createFromFormat( 'Y-m-d\TH:i', $closes_at_raw, wp_timezone() );
			$closes_at = $dt ? $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
		} else {
			$closes_at = null;
		}
		$max_earners   = '' !== ( $_POST['badge_max_earners'] ?? '' ) ? absint( $_POST['badge_max_earners'] ) : null;

		$badge_data = array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['badge_name'] ?? '' ) ),
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['badge_description'] ?? '' ) ),
			'image_url'     => esc_url_raw( wp_unslash( $_POST['badge_image_url'] ?? '' ) ),
			'category'      => sanitize_key( $_POST['badge_category'] ?? 'general' ),
			'is_credential' => ! empty( $_POST['badge_is_credential'] ) ? 1 : 0,
			'closes_at'     => $closes_at,
			'max_earners'   => $max_earners,
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

	/**
	 * Handle the badge delete action via admin-post.php.
	 *
	 * @return void
	 */
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
