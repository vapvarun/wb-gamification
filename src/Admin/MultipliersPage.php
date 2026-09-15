<?php
/**
 * Admin: Point Multipliers
 *
 * Adds a "Point Multipliers" submenu under WB Gamification so the site owner
 * can see and manage the points_multiplier rules that silently rescale every
 * award. The rules already existed and were enforced by
 * {@see \WBGam\Engine\RuleEngine::apply_multipliers()} but were reachable only
 * through the REST API (RulesController) — an owner had no way to see, add, or
 * turn off a multiplier that was doubling everyone's points.
 *
 * This page is a thin admin surface over the existing
 * POST|PATCH|DELETE /wb-gamification/v1/rules endpoint, driven by the generic
 * admin-rest-form JS. It manages permanent multipliers (a factor, an optional
 * per-action scope, active/inactive). Time-bound campaign windows and condition
 * editing are a separate 2.0.0 gap; conditions authored via REST are shown
 * read-only here so they stay visible and can still be toggled or removed.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Manages the Point Multipliers admin page.
 *
 * @package WB_Gamification
 */
final class MultipliersPage {

	/**
	 * Admin page slug / menu hook suffix stem.
	 *
	 * @var string
	 */
	private const SLUG = 'wb-gam-multipliers';

	/**
	 * Register admin_menu and admin_enqueue_scripts hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the Point Multipliers submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Point Multipliers', 'wb-gamification' ),
			__( 'Point Multipliers', 'wb-gamification' ),
			'wb_gam_manage_rules',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the REST-driven form driver on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		// The shared admin CSS stack (tokens/components/utilities) is already
		// enqueued for any wb-gam* page by the plugin bootstrap, so only the
		// REST-form JS needs adding here.
		wp_enqueue_script(
			'wb-gam-admin-rest-utils',
			plugins_url( 'assets/js/admin-rest-utils.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
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
			'wbGamMultiplierSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Multiplier saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the multiplier.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Render the multipliers page: create/edit form plus the rules list.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_rules';

		$rules = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a prefixed constant.
			"SELECT id, target_id, rule_config, is_active FROM {$table} WHERE rule_type = 'points_multiplier' ORDER BY id DESC",
			ARRAY_A
		) ?: array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET param for routing only.
		$editing = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$edit = null;
		if ( $editing ) {
			foreach ( $rules as $r ) {
				if ( (int) $r['id'] === $editing ) {
					$edit = $r;
					break;
				}
			}
		}
		$edit_config = ( $edit && ! empty( $edit['rule_config'] ) ) ? (array) json_decode( $edit['rule_config'], true ) : array();
		$edit_mult   = isset( $edit_config['multiplier'] ) ? (float) $edit_config['multiplier'] : '';
		$edit_target = $edit ? (string) ( $edit['target_id'] ?? '' ) : '';

		$rest_path = $editing ? '/rules/' . $editing : '/rules';
		$actions   = function_exists( 'wb_gam_get_actions' ) ? wb_gam_get_actions() : array();
		?>
		<div class="wrap wbgam-wrap">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Point Multipliers', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Multiply the points earned for an action. A multiplier applies to every member and takes effect immediately, so a 2x rule doubles what everyone earns.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php echo $editing ? esc_html__( 'Edit Multiplier', 'wb-gamification' ) : esc_html__( 'Add Multiplier', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form
						data-wb-gam-rest-form="wbGamMultiplierSettings"
						data-wb-gam-rest-method="<?php echo $editing ? 'PATCH' : 'POST'; ?>"
						data-wb-gam-rest-path="<?php echo esc_attr( $rest_path ); ?>"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Multiplier saved.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to save the multiplier.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload"
					>
						<input type="hidden" name="rule_type" value="points_multiplier">
						<table class="form-table">
							<tr>
								<th><label for="wb-gam-mult-factor"><?php esc_html_e( 'Multiplier', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" step="0.1" min="0.1" name="rule_config[multiplier]" id="wb-gam-mult-factor"
										class="wbgam-input" style="max-width:8rem"
										value="<?php echo esc_attr( '' === $edit_mult ? '' : $edit_mult ); ?>" required>
									<p class="description"><?php esc_html_e( 'The factor to multiply points by. 2 = double, 1.5 = 50% more, 0.5 = half.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-mult-target"><?php esc_html_e( 'Applies to', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" name="target_id" id="wb-gam-mult-target" class="regular-text wbgam-input"
										list="wb-gam-actions" value="<?php echo esc_attr( $edit_target ); ?>"
										placeholder="<?php esc_attr_e( 'All actions', 'wb-gamification' ); ?>">
									<datalist id="wb-gam-actions">
										<?php foreach ( array_keys( $actions ) as $aid ) : ?>
											<option value="<?php echo esc_attr( $aid ); ?>"></option>
										<?php endforeach; ?>
									</datalist>
									<p class="description"><?php esc_html_e( 'Leave blank to multiply every action. Enter an action ID to scope the multiplier to one action only.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>
						<p>
							<button type="submit" class="wbgam-btn wbgam-btn--primary"><?php echo $editing ? esc_html__( 'Save Multiplier', 'wb-gamification' ) : esc_html__( 'Add Multiplier', 'wb-gamification' ); ?></button>
							<?php if ( $editing ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="wbgam-btn wbgam-btn--secondary"><?php esc_html_e( 'Cancel', 'wb-gamification' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</div>
			</div>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Active & Inactive Multipliers', 'wb-gamification' ); ?></h3>
				</div>
				<?php if ( $rules ) : ?>
				<div class="wbgam-card-body wbgam-card-body--flush">
					<table class="wbgam-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Multiplier', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Applies to', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Condition', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $rules as $rule ) :
								$rid    = (int) $rule['id'];
								$cfg    = (array) json_decode( $rule['rule_config'], true );
								$factor = isset( $cfg['multiplier'] ) ? (float) $cfg['multiplier'] : 1.0;
								$target = (string) ( $rule['target_id'] ?? '' );
								$active = (int) $rule['is_active'] === 1;
								// A condition authored over REST (e.g. day_of_week) is shown so
								// it is never invisible; editing it is out of scope here.
								$has_condition = ! empty( $cfg['condition'] ) && is_array( $cfg['condition'] );
								$condition_lbl = $has_condition ? (string) ( $cfg['condition']['type'] ?? __( 'Custom', 'wb-gamification' ) ) : '';
								?>
								<tr>
									<td><strong><?php echo esc_html( number_format_i18n( $factor, 2 ) ); ?>x</strong></td>
									<td><?php echo '' === $target ? esc_html__( 'All actions', 'wb-gamification' ) : '<code>' . esc_html( $target ) . '</code>'; ?></td>
									<td>
										<?php if ( $has_condition ) : ?>
											<code><?php echo esc_html( $condition_lbl ); ?></code>
										<?php else : ?>
											<span class="wbgam-text-muted">&mdash;</span>
										<?php endif; ?>
									</td>
									<td>
										<span class="wbgam-pill wbgam-pill--<?php echo $active ? 'active' : 'info'; ?>">
											<?php echo $active ? esc_html__( 'Active', 'wb-gamification' ) : esc_html__( 'Inactive', 'wb-gamification' ); ?>
										</span>
									</td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&edit=' . $rid ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary"><?php esc_html_e( 'Edit', 'wb-gamification' ); ?></a>
										<button
											type="button"
											class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary wbgam-ms-xs"
											data-wb-gam-rest-action="wbGamMultiplierSettings"
											data-wb-gam-rest-method="PATCH"
											data-wb-gam-rest-path="/rules/<?php echo $rid; ?>"
											data-wb-gam-rest-body='{"is_active":<?php echo $active ? '0' : '1'; ?>}'
											data-wb-gam-rest-after="reload"
											data-wb-gam-rest-success-toast="<?php echo $active ? esc_attr__( 'Multiplier deactivated.', 'wb-gamification' ) : esc_attr__( 'Multiplier activated.', 'wb-gamification' ); ?>">
											<?php echo $active ? esc_html__( 'Deactivate', 'wb-gamification' ) : esc_html__( 'Activate', 'wb-gamification' ); ?>
										</button>
										<button
											type="button"
											class="wbgam-btn wbgam-btn--sm wbgam-btn--danger wbgam-ms-xs"
											data-wb-gam-rest-action="wbGamMultiplierSettings"
											data-wb-gam-rest-method="DELETE"
											data-wb-gam-rest-path="/rules/<?php echo $rid; ?>"
											data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this multiplier?', 'wb-gamification' ); ?>"
											data-wb-gam-rest-after="reload"
											data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Multiplier deleted.', 'wb-gamification' ); ?>">
											<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php else : ?>
				<div class="wbgam-card-body">
					<p class="wbgam-text-muted"><?php esc_html_e( 'No multipliers yet. Points are awarded at their base value until you add one.', 'wb-gamification' ); ?></p>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
