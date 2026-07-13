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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

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
		wp_enqueue_style(
			'wb-gam-page-badges',
			plugins_url( 'assets/css/admin/pages/badges.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
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

		// The condition repeater. No jQuery dependency: it is plain DOM, and it clones a
		// server-rendered <template> rather than building markup in JavaScript.
		wp_enqueue_script(
			'wb-gam-admin-badge-conditions',
			WB_GAM_URL . 'assets/js/admin-badge-conditions.js',
			array(),
			WB_GAM_VERSION,
			true
		);

		// REST-form driver dependencies (Tier 0.C).
		wp_enqueue_script(
			'wb-gam-admin-rest-utils',
			plugins_url( 'assets/js/admin-rest-utils.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		// Shared confirm/cancel modal button labels — confirmAction() falls back
		// to these when a caller doesn't pass its own confirmText/cancelText.
		wp_localize_script(
			'wb-gam-admin-rest-utils',
			'wbGamAdminRestI18n',
			array(
				'confirm' => __( 'Confirm', 'wb-gamification' ),
				'cancel'  => __( 'Cancel', 'wb-gamification' ),
			)
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
	 * Render ONE condition row.
	 *
	 * Progressive disclosure: each type shows only its own fields, so a single-condition badge looks
	 * almost exactly as it did before this feature. The complex case became possible; the simple case
	 * did not get heavier.
	 *
	 * @param int    $index       Row index (posted as condition[conditions][i][...]).
	 * @param string $type        Condition type.
	 * @param array  $condition   Current values.
	 * @param array  $actions     Registered actions.
	 * @param array  $levels      Site levels (id, name).
	 * @param array  $badges      Badge choices (id, name).
	 * @param bool   $is_template True when rendering the clone template.
	 * @return string
	 */
	private static function render_condition_row( int $index, string $type, array $condition, array $actions, array $levels, array $badges, bool $is_template = false ): string {
		$i    = $is_template ? '__INDEX__' : (string) $index;
		$name = 'condition[conditions][' . $i . ']';

		$types = array(
			'admin_awarded'    => __( 'Admin awarded only (manual)', 'wb-gamification' ),
			'point_milestone'  => __( 'Reaches a point total', 'wb-gamification' ),
			'action_count'     => __( 'Performs an action N times', 'wb-gamification' ),
			'level_reached'    => __( 'Reaches a level', 'wb-gamification' ),
			'badge_earned'     => __( 'Has earned another badge', 'wb-gamification' ),
			'streak_days'      => __( 'Keeps a daily streak of N days', 'wb-gamification' ),
			'tenure_days'      => __( 'Has been a member for N days', 'wb-gamification' ),
			'points_in_period' => __( 'Earns N points in a period', 'wb-gamification' ),
		);

		$periods = array(
			'day'   => __( 'day', 'wb-gamification' ),
			'week'  => __( 'week', 'wb-gamification' ),
			'month' => __( 'month', 'wb-gamification' ),
		);

		ob_start();
		?>
		<div class="wb-gam-condition-row" data-index="<?php echo esc_attr( $i ); ?>">
			<select name="<?php echo esc_attr( $name . '[type]' ); ?>" class="wbgam-select wb-gam-condition-type">
				<?php foreach ( $types as $wb_gam_value => $wb_gam_label ) : ?>
					<option value="<?php echo esc_attr( $wb_gam_value ); ?>" <?php selected( $type, $wb_gam_value ); ?>>
						<?php echo esc_html( $wb_gam_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<span class="wb-gam-condition-fields" data-for="point_milestone">
				<input type="number" min="1" class="small-text wbgam-input"
					name="<?php echo esc_attr( $name . '[points]' ); ?>"
					value="<?php echo esc_attr( (string) ( $condition['points'] ?? 100 ) ); ?>">
				<span class="wb-gam-condition-unit"><?php esc_html_e( 'points', 'wb-gamification' ); ?></span>
			</span>

			<span class="wb-gam-condition-fields" data-for="action_count">
				<select name="<?php echo esc_attr( $name . '[action_id]' ); ?>" class="wbgam-select">
					<?php foreach ( $actions as $wb_gam_action ) : ?>
						<option value="<?php echo esc_attr( (string) $wb_gam_action['id'] ); ?>" <?php selected( (string) ( $condition['action_id'] ?? '' ), (string) $wb_gam_action['id'] ); ?>>
							<?php echo esc_html( (string) ( $wb_gam_action['label'] ?? $wb_gam_action['id'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="number" min="1" class="small-text wbgam-input"
					name="<?php echo esc_attr( $name . '[count]' ); ?>"
					value="<?php echo esc_attr( (string) ( $condition['count'] ?? 1 ) ); ?>">
				<span class="wb-gam-condition-unit"><?php esc_html_e( 'times', 'wb-gamification' ); ?></span>
			</span>

			<span class="wb-gam-condition-fields" data-for="level_reached">
				<select name="<?php echo esc_attr( $name . '[level_id]' ); ?>" class="wbgam-select">
					<?php foreach ( $levels as $wb_gam_level ) : ?>
						<option value="<?php echo esc_attr( (string) $wb_gam_level['id'] ); ?>" <?php selected( (int) ( $condition['level_id'] ?? 0 ), (int) $wb_gam_level['id'] ); ?>>
							<?php echo esc_html( (string) $wb_gam_level['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>

			<span class="wb-gam-condition-fields" data-for="badge_earned">
				<select name="<?php echo esc_attr( $name . '[badge_id]' ); ?>" class="wbgam-select">
					<?php foreach ( $badges as $wb_gam_badge ) : ?>
						<option value="<?php echo esc_attr( (string) $wb_gam_badge['id'] ); ?>" <?php selected( (string) ( $condition['badge_id'] ?? '' ), (string) $wb_gam_badge['id'] ); ?>>
							<?php echo esc_html( (string) $wb_gam_badge['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>

			<span class="wb-gam-condition-fields" data-for="streak_days">
				<input type="number" min="1" class="small-text wbgam-input"
					name="<?php echo esc_attr( $name . '[days]' ); ?>"
					value="<?php echo esc_attr( (string) ( $condition['days'] ?? 7 ) ); ?>">
				<span class="wb-gam-condition-unit"><?php esc_html_e( 'days in a row', 'wb-gamification' ); ?></span>
			</span>

			<span class="wb-gam-condition-fields" data-for="tenure_days">
				<input type="number" min="1" class="small-text wbgam-input"
					name="<?php echo esc_attr( $name . '[days]' ); ?>"
					value="<?php echo esc_attr( (string) ( $condition['days'] ?? 365 ) ); ?>">
				<span class="wb-gam-condition-unit"><?php esc_html_e( 'days since joining', 'wb-gamification' ); ?></span>
			</span>

			<span class="wb-gam-condition-fields" data-for="points_in_period">
				<input type="number" min="1" class="small-text wbgam-input"
					name="<?php echo esc_attr( $name . '[points]' ); ?>"
					value="<?php echo esc_attr( (string) ( $condition['points'] ?? 50 ) ); ?>">
				<span class="wb-gam-condition-unit"><?php esc_html_e( 'points in a', 'wb-gamification' ); ?></span>
				<select name="<?php echo esc_attr( $name . '[period]' ); ?>" class="wbgam-select">
					<?php foreach ( $periods as $wb_gam_p => $wb_gam_plabel ) : ?>
						<option value="<?php echo esc_attr( $wb_gam_p ); ?>" <?php selected( (string) ( $condition['period'] ?? 'week' ), $wb_gam_p ); ?>>
							<?php echo esc_html( $wb_gam_plabel ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>

			<button type="button" class="wb-gam-condition-remove" aria-label="<?php esc_attr_e( 'Remove this condition', 'wb-gamification' ); ?>">
				<span aria-hidden="true">&times;</span>
			</button>
		</div>
		<?php
		return (string) ob_get_clean();
	}


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
		// Read-only routing flag — `?action=new` toggles the create form
		// visibility without persisting anything; no nonce needed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$wb_gam_action_param = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$show_form = ! empty( $editing ) || 'new' === $wb_gam_action_param;

		$actions = \WBGam\Engine\Registry::get_actions();

		// Levels and badges for the level_reached / badge_earned conditions. Read from the database,
		// because level ids are SITE-SPECIFIC -- "Champion" is id 5 here and could be anything
		// anywhere. A hardcoded list in JavaScript would point at whatever level happened to be
		// fifth on someone else's site.
		global $wpdb;
		$levels        = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC", ARRAY_A ) ?: array();
		$badge_choices = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}wb_gam_badge_defs ORDER BY name ASC", ARRAY_A ) ?: array();

		?>
		<div class="wrap wbgam-wrap">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Badge Library', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Create and manage badges to reward your community members for milestones and achievements.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="wbgam-banner wbgam-banner--<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> wbgam-stack-block" role="status" aria-live="polite"><span class="wbgam-banner__icon icon-circle-check" aria-hidden="true"></span><div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p></div></div>
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
									<th><label for="wb-gam-choose-icon"><?php esc_html_e( 'Icon', 'wb-gamification' ); ?></label></th>
									<td>
										<div class="wbgam-badge-icon-preview" id="wb-gam-icon-preview">
											<?php if ( ! empty( $badge['image_url'] ) ) : ?>
												<img src="<?php echo esc_url( $badge['image_url'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: badge name. */ __( '%s icon', 'wb-gamification' ), ( $badge['name'] ?? '' ) !== '' ? $badge['name'] : __( 'Badge', 'wb-gamification' ) ) ); ?>">
											<?php else : ?>
												<span class="icon-award"></span>
											<?php endif; ?>
										</div>
										<input type="hidden" name="image_url" id="wb-gam-badge-image-url" value="<?php echo esc_attr( $badge['image_url'] ?? '' ); ?>">
										<button type="button" class="wbgam-btn wbgam-btn--secondary wbgam-btn--sm" id="wb-gam-choose-icon">
											<?php esc_html_e( 'Choose Icon', 'wb-gamification' ); ?>
										</button>
										<?php if ( ! empty( $badge['image_url'] ) ) : ?>
											<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--danger wbgam-ms-xs" id="wb-gam-remove-icon">
												<?php esc_html_e( 'Remove', 'wb-gamification' ); ?>
											</button>
										<?php else : ?>
											<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--danger wbgam-ms-xs wbgam-is-hidden" id="wb-gam-remove-icon">
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
									<th><label for="wb-gam-badge-validity-days"><?php esc_html_e( 'Valid for (days)', 'wb-gamification' ); ?></label></th>
									<td>
										<input type="number" name="validity_days" id="wb-gam-badge-validity-days" class="small-text wbgam-input"
											min="1" value="<?php echo esc_attr( $badge['validity_days'] ?? '' ); ?>">
										<p class="description"><?php esc_html_e( 'Days the badge stays valid after a member earns it, then it expires and stops counting. Leave blank so it never expires.', 'wb-gamification' ); ?></p>
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

							<h3><?php esc_html_e( 'Auto-Award Conditions', 'wb-gamification' ); ?></h3>

							<?php
							// THE REPEATER.
							//
							// A badge used to have exactly ONE condition. Worse, four tenure badges and three
							// site-first badges had no rule at all -- they were awarded by hardcoded engines,
							// the library chipped them "MANUAL", and the owner could not see or change what
							// they required. The only way to make "2-Year Member" mean eighteen months was to
							// edit PHP.
							//
							// Now every badge is one editable rule, and a rule can hold as many conditions as
							// the owner needs.
							$wb_gam_conditions = \WBGam\Engine\BadgeRule::conditions( $condition );
							$wb_gam_match      = \WBGam\Engine\BadgeRule::match_mode( $condition );
							$wb_gam_is_manual  = empty( $wb_gam_conditions );

							// A manual badge is a badge with no conditions. That is now the ONLY thing that
							// makes one, which is what lets the library's chip finally tell the truth.
							if ( $wb_gam_is_manual ) {
								$wb_gam_conditions = array( array( 'type' => 'admin_awarded' ) );
							}
							?>

							<div class="wb-gam-conditions" data-match="<?php echo esc_attr( $wb_gam_match ); ?>">

								<p class="wb-gam-conditions__match">
									<span class="wb-gam-conditions__match-label">
										<?php esc_html_e( 'Award this badge when the member matches', 'wb-gamification' ); ?>
									</span>
									<label>
										<input type="radio" name="condition[match]" value="all" <?php checked( $wb_gam_match, 'all' ); ?>>
										<?php esc_html_e( 'ALL of these', 'wb-gamification' ); ?>
									</label>
									<label>
										<input type="radio" name="condition[match]" value="any" <?php checked( $wb_gam_match, 'any' ); ?>>
										<?php esc_html_e( 'ANY of these', 'wb-gamification' ); ?>
									</label>
								</p>

								<div class="wb-gam-conditions__rows" id="wb-gam-condition-rows">
									<?php foreach ( $wb_gam_conditions as $wb_gam_i => $wb_gam_c ) : ?>
										<?php
										$wb_gam_type = (string) ( $wb_gam_c['type'] ?? 'admin_awarded' );
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_condition_row escapes internally.
										echo self::render_condition_row( (int) $wb_gam_i, $wb_gam_type, (array) $wb_gam_c, $actions, $levels, $badge_choices );
										?>
									<?php endforeach; ?>
								</div>

								<p class="wb-gam-conditions__actions">
									<button type="button" class="wbgam-btn wbgam-btn--secondary" id="wb-gam-add-condition">
										<?php esc_html_e( '+ Add condition', 'wb-gamification' ); ?>
									</button>
									<span class="description wb-gam-conditions__hint">
										<?php esc_html_e( 'Choose "Admin awarded" for a badge you grant by hand. Any other condition awards it automatically.', 'wb-gamification' ); ?>
									</span>
								</p>

								<?php
								// THE OWNER'S CHOICE, and it is theirs alone.
								//
								// Changing a condition does NOT retroactively award the badge. Awarding it to
								// everyone who already qualifies is a decision about someone's community --
								// plenty of owners launch a badge deliberately "from today onwards", and a
								// surprise flood of notifications to ten thousand members is not a feature.
								//
								// So: unchecked by default, and it says plainly what it will do.
								$wb_gam_backfill = $is_new ? null : \WBGam\Engine\BadgeEngine::backfill_progress( (string) ( $badge['id'] ?? '' ) );
								?>
								<div class="wb-gam-conditions__backfill">
									<label>
										<input type="checkbox" name="backfill" value="1">
										<strong><?php esc_html_e( 'Also award this badge to members who already qualify', 'wb-gamification' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Off by default. Leave it off and the badge is earned from now on. Turn it on and every member who already meets these conditions is awarded it in the background - which on a large community can be a lot of people, and a lot of notifications.', 'wb-gamification' ); ?>
									</p>

									<?php if ( is_array( $wb_gam_backfill ) ) : ?>
										<p class="wb-gam-backfill-status">
											<?php
											printf(
												/* translators: 1: members checked, 2: total members, 3: badges awarded */
												esc_html__( 'Last retroactive award: checked %1$s of %2$s members, awarded %3$s.', 'wb-gamification' ),
												esc_html( number_format_i18n( (int) ( $wb_gam_backfill['checked'] ?? 0 ) ) ),
												esc_html( number_format_i18n( (int) ( $wb_gam_backfill['total'] ?? 0 ) ) ),
												esc_html( number_format_i18n( (int) ( $wb_gam_backfill['awarded'] ?? 0 ) ) )
											);
											?>
											<?php if ( empty( $wb_gam_backfill['done'] ) ) : ?>
												<em><?php esc_html_e( 'Still running.', 'wb-gamification' ); ?></em>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</div>
							</div>

							<?php
							// The row template the JS clones. Kept in PHP so every label is translatable and
							// the option lists come from the real registry, not a hardcoded copy in JavaScript
							// that drifts the first time someone adds an action.
							?>
							<template id="wb-gam-condition-row-template">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_condition_row escapes internally.
								echo self::render_condition_row( 0, 'point_milestone', array(), $actions, $levels, $badge_choices, true );
								?>
							</template>

							<p>
								<button type="submit" class="wbgam-btn">
									<?php echo $is_new ? esc_html__( 'Create Badge', 'wb-gamification' ) : esc_html__( 'Save Changes', 'wb-gamification' ); ?>
								</button>
								<?php if ( ! $is_new ) : ?>
									<button
										type="button"
										class="wbgam-btn wbgam-btn--danger wbgam-ms-sm"
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

						<?php if ( ! $is_new ) : ?>
							<?php
							// Manual-award panel — POSTs to /badges/{id}/award via the
							// shared admin-rest-form pipeline. The endpoint accepts the
							// award whatever the rule says (a manual badge has no auto
							// rule, so this is the ONLY way it ever lands on a member;
							// an auto-award badge still accepts manual grants for one-off
							// "give them this badge anyway" admin actions).
							// Mirrors the wp_dropdown_users pattern already used by
							// ManualAwardPage for the points-award form so admins see
							// the same control across both surfaces. Closes Basecamp
							// #9933208551.
							$wb_gam_awards_itself = \WBGam\Engine\BadgeRule::is_auto_award( $condition );
							?>
							<div class="wbgam-card wbgam-stack-block wbgam-mt-md">
								<div class="wbgam-card-header">
									<h3 class="wbgam-card-title">
										<?php esc_html_e( 'Award this badge to a user', 'wb-gamification' ); ?>
									</h3>
								</div>
								<div class="wbgam-card-body">
									<p class="wbgam-card-desc">
										<?php
										if ( $wb_gam_awards_itself ) {
											esc_html_e( 'Manually grant this badge to a specific member. Useful for one-off recognition outside the auto-award rule.', 'wb-gamification' );
										} else {
											esc_html_e( 'This badge is admin-awarded only. Use the form below to grant it to a specific member.', 'wb-gamification' );
										}
										?>
									</p>
									<form
										data-wb-gam-rest-form="wbGamBadgesSettings"
										data-wb-gam-rest-method="POST"
										data-wb-gam-rest-path="/badges/<?php echo esc_attr( rawurlencode( $editing ) ); ?>/award"
										data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Badge awarded.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to award badge.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-after="reset"
									>
										<table class="form-table" role="presentation">
											<tr>
												<th scope="row">
													<label for="wb-gam-badge-award-user"><?php esc_html_e( 'User', 'wb-gamification' ); ?></label>
												</th>
												<td>
													<?php
													wp_dropdown_users(
														array(
															'name'              => 'user_id',
															'id'                => 'wb-gam-badge-award-user',
															'show_option_none'  => __( '- Select a user -', 'wb-gamification' ),
															'option_none_value' => '0',
														)
													);
													?>
													<p class="description"><?php esc_html_e( 'Member who will receive the badge.', 'wb-gamification' ); ?></p>
												</td>
											</tr>
										</table>
										<p>
											<button type="submit" class="wbgam-btn">
												<?php esc_html_e( 'Award Badge', 'wb-gamification' ); ?>
											</button>
										</p>
									</form>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Badge Grid -->
			<?php if ( ! empty( $badges ) ) : ?>
			<div class="wbgam-grid-4">
				<?php foreach ( $badges as $b ) : ?>
					<?php
					$config   = json_decode( $b['condition'] ?? '{}', true ) ?: array();
					$has_rule = \WBGam\Engine\BadgeRule::is_auto_award( $config );
					$edit_url = admin_url( 'admin.php?page=wb-gamification-badges&badge=' . rawurlencode( $b['id'] ) );
					?>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="wbgam-badge-card wbgam-is-link-clean">
						<div class="wbgam-badge-card__icon">
							<?php if ( ! empty( $b['image_url'] ) ) : ?>
								<img src="<?php echo esc_url( $b['image_url'] ); ?>" alt="<?php echo esc_attr( $b['name'] ); ?>">
							<?php else : ?>
								<span class="icon-award"></span>
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
				<div class="wbgam-empty-icon"><span class="icon-award wbgam-icon-xl"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No badges yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Badges reward members for milestones, actions, and achievements. Create your first badge to get started.', 'wb-gamification' ); ?></p>
				<p class="wbgam-mt-md">
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
