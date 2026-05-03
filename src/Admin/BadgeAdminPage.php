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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_{save,delete}_badge removed in 1.0.0 — page now
		// consumes /wb-gamification/v1/badges (POST + DELETE) via the generic
		// admin-rest-form driver. See Tier 0.C migration.
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

		// REST-form driver dependencies (Tier 0.C).
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
			'wbGamBadgesSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Badge saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the badge.', 'wb-gamification' ),
				),
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
			'wb_gam_manage_badges',
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
		$editing = sanitize_key( $_GET['badge'] ?? '' );
		$notice  = sanitize_key( $_GET['notice'] ?? '' );
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
						<?php
						// Build the REST endpoint path: POST /badges (create) or POST /badges/{id} (update).
						$rest_path = $is_new ? '/badges' : '/badges/' . rawurlencode( $editing );
						?>
						<form
							data-wb-gam-rest-form="wbGamBadgesSettings"
							data-wb-gam-rest-method="POST"
							data-wb-gam-rest-path="<?php echo esc_attr( $rest_path ); ?>"
							data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Badge saved.', 'wb-gamification' ); ?>"
							data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to save the badge.', 'wb-gamification' ); ?>"
							data-wb-gam-rest-after="reload"
						>
							<table class="form-table">
								<?php if ( $is_new ) : ?>
								<tr>
									<th><label for="wb-gam-badge-id"><?php esc_html_e( 'Badge ID', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="text" name="id" id="wb-gam-badge-id" class="regular-text wbgam-input"
											value="" placeholder="e.g. first_post" required>
										<p class="description"><?php esc_html_e( 'Lowercase letters, numbers, underscores only.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<?php else : ?>
								<tr>
									<th><label for="wb-gam-badge-id-readonly"><?php esc_html_e( 'Badge ID', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="text" id="wb-gam-badge-id-readonly" class="regular-text wbgam-input" value="<?php echo esc_attr( $badge['id'] ?? '' ); ?>" readonly>
										<p class="description"><?php esc_html_e( 'ID cannot be changed after creation.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<?php endif; ?>
								<tr>
									<th><label for="wb-gam-badge-name"><?php esc_html_e( 'Name', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="text" name="name" id="wb-gam-badge-name" class="regular-text wbgam-input" value="<?php echo esc_attr( $badge['name'] ?? '' ); ?>" required>
										<p class="description"><?php esc_html_e( 'Display name shown to members when they earn this badge.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="wb-gam-badge-description"><?php esc_html_e( 'Description', 'wb-gamification' ); ?></label></th>
									<td>
										<textarea name="description" id="wb-gam-badge-description" rows="3" class="large-text wbgam-input"><?php echo esc_textarea( $badge['description'] ?? '' ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Explains what this badge is for. Shown on badge cards and share pages.', 'wb-gamification' ); ?></p>
									</td>
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
										<input type="hidden" name="image_url" id="wb-gam-badge-image-url" value="<?php echo esc_attr( $badge['image_url'] ?? '' ); ?>">
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
										<p class="description"><?php esc_html_e( 'Upload an image from the Media Library. Recommended: 128x128px PNG with transparent background.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="wb-gam-badge-category"><?php esc_html_e( 'Category', 'wb-gamification' ); ?></label></th>
									<td>
										<select name="category" id="wb-gam-badge-category" class="wbgam-select">
											<?php foreach ( array( 'general', 'points', 'wordpress', 'buddypress', 'special' ) as $cat ) : ?>
												<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $badge['category'] ?? 'general', $cat ); ?>>
													<?php echo esc_html( ucfirst( $cat ) ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Group badges by category for organized display in the frontend badge showcase.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Is Credential', 'wb-gamification' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="is_credential" id="wb-gam-badge-is-credential" value="1" <?php checked( ! empty( $badge['is_credential'] ) ); ?>>
											<?php esc_html_e( 'Mark as shareable credential (LinkedIn, OpenBadges)', 'wb-gamification' ); ?>
										</label>
										<p class="description"><?php esc_html_e( 'Enable OpenBadges 3.0 verifiable credential issuance. Members can share a verified badge URL.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="wb-gam-badge-closes-at"><?php esc_html_e( 'Closes at', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="datetime-local" name="closes_at" id="wb-gam-badge-closes-at" class="wbgam-input"
											value="
											<?php
											if ( ! empty( $badge['closes_at'] ) ) {
												$dt = new \DateTime( $badge['closes_at'], new \DateTimeZone( 'UTC' ) );
												$dt->setTimezone( wp_timezone() );
												echo esc_attr( $dt->format( 'Y-m-d\TH:i' ) );
											}
											?>
											">
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
										<input type="number" name="max_earners" id="wb-gam-badge-max-earners" class="small-text wbgam-input"
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
										<select name="condition[type]" id="wb-gam-condition-type" class="wbgam-select" onchange="wbGamToggleConditionFields(this.value)">
											<option value="admin_awarded" <?php selected( $condition['condition_type'], 'admin_awarded' ); ?>><?php esc_html_e( 'Admin awarded only (manual)', 'wb-gamification' ); ?></option>
											<option value="point_milestone" <?php selected( $condition['condition_type'], 'point_milestone' ); ?>><?php esc_html_e( 'Reaches a point milestone', 'wb-gamification' ); ?></option>
											<option value="action_count" <?php selected( $condition['condition_type'], 'action_count' ); ?>><?php esc_html_e( 'Performs an action N times', 'wb-gamification' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Choose how this badge is awarded. "Manual" means only admins can grant it. Other options award automatically when conditions are met.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr id="wb-gam-field-points" <?php echo 'point_milestone' !== $condition['condition_type'] ? 'class="wb-gam-hidden"' : ''; ?>>
									<th><label for="wb-gam-condition-points"><?php esc_html_e( 'Points Threshold', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="number" name="condition[points]" id="wb-gam-condition-points" class="small-text wbgam-input" min="1" value="<?php echo esc_attr( $condition['points'] ?? 100 ); ?>">
										<p class="description"><?php esc_html_e( 'The badge is awarded when the member\'s total points reach or exceed this value.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr id="wb-gam-field-action" <?php echo 'action_count' !== $condition['condition_type'] ? 'class="wb-gam-hidden"' : ''; ?>>
									<th><label for="wb-gam-condition-action-id"><?php esc_html_e( 'Action', 'wb-gamification' ); ?></label></th>
									<td>
										<select name="condition[action_id]" id="wb-gam-condition-action-id" class="wbgam-select">
											<?php foreach ( $actions as $action_id => $action_data ) : ?>
												<option value="<?php echo esc_attr( $action_id ); ?>" <?php selected( $condition['action_id'] ?? '', $action_id ); ?>>
													<?php echo esc_html( $action_data['label'] ?? $action_id ); ?>
												</option>
											<?php endforeach; ?>
											<?php if ( empty( $actions ) ) : ?>
												<option value=""><?php esc_html_e( 'No actions registered', 'wb-gamification' ); ?></option>
											<?php endif; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Which action the member must perform (e.g. publish post, complete course, upload media).', 'wb-gamification' ); ?></p>
									</td>
								</tr>
								<tr id="wb-gam-field-count" <?php echo 'action_count' !== $condition['condition_type'] ? 'class="wb-gam-hidden"' : ''; ?>>
									<th><label for="wb-gam-condition-count"><?php esc_html_e( 'Target Count', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="number" name="condition[count]" id="wb-gam-condition-count" class="small-text wbgam-input" min="1" value="<?php echo esc_attr( $condition['count'] ?? 1 ); ?>">
										<p class="description"><?php esc_html_e( 'How many times the action must be performed to earn this badge.', 'wb-gamification' ); ?></p>
									</td>
								</tr>
							</table>

							<p>
								<button type="submit" class="wbgam-btn">
									<?php echo $is_new ? esc_html__( 'Create Badge', 'wb-gamification' ) : esc_html__( 'Save Changes', 'wb-gamification' ); ?>
								</button>
								<?php if ( ! $is_new ) : ?>
									<button
										type="button"
										class="wbgam-btn wbgam-btn--danger"
										style="margin-left:8px;"
										data-wb-gam-rest-action="wbGamBadgesSettings"
										data-wb-gam-rest-method="DELETE"
										data-wb-gam-rest-path="/badges/<?php echo esc_attr( rawurlencode( $editing ) ); ?>"
										data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this badge and all earned records?', 'wb-gamification' ); ?>"
										data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Badge deleted.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to delete badge.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-after="reload"
									>
										<?php esc_html_e( 'Delete Badge', 'wb-gamification' ); ?>
									</button>
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
					$config    = json_decode( $b['condition'] ?? '{}', true ) ?: array();
					$cond_type = $config['condition_type'] ?? 'admin_awarded';
					$has_rule  = 'admin_awarded' !== $cond_type;
					$edit_url  = admin_url( 'admin.php?page=wb-gamification-badges&badge=' . rawurlencode( $b['id'] ) );
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
	// handle_save() / handle_delete() removed in 1.0.0 (Tier 0.C). Badges are
	// now written by BadgesController (POST /badges and POST /badges/{id} for
	// create/update; DELETE /badges/{id} for deletion). The condition rule is
	// passed as a nested `condition` object on the badge save endpoint.
}
