<?php
/**
 * WB Gamification - Levels admin page.
 *
 * The level ladder: names and minimum-point thresholds. It has its own page so a delegated staff
 * member holding `wb_gam_manage_levels` can edit levels without being given the whole Settings
 * screen (which stays `manage_options`). Every write goes through /wb-gamification/v1/levels
 * (assets/js/admin-levels.js), which accepts the same capability.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Admin;

use WBGam\Engine\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Levels page.
 *
 * @package WB_Gamification
 */
final class LevelsPage {

	private const PAGE_SLUG = 'wb-gam-levels';
	private const HOOK      = 'gamification_page_wb-gam-levels';

	/**
	 * Hook the admin menu and asset enqueue.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the Levels submenu under WB Gamification.
	 */
	public static function add_submenu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Levels', 'wb-gamification' ),
			__( 'Levels', 'wb-gamification' ),
			'wb_gam_manage_levels',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the page CSS and the REST-driven Levels script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wb-gam-page-settings',
			plugins_url( 'assets/css/admin/pages/settings.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
		wp_enqueue_script(
			'wb-gam-admin-rest-utils',
			plugins_url( 'assets/js/admin-rest-utils.js', WB_GAM_FILE ),
			array( 'wb-gam-dialog', 'wb-gam-toast-core' ),
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
	 * Render the page.
	 */
	public static function render_page(): void {
		if ( ! Capabilities::user_can( 'wb_gam_manage_levels' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}
		?>
		<div class="wrap wbgam-wrap">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Levels', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'The ladder members climb as they earn points.', 'wb-gamification' ); ?></p>
				</div>
			</header>
			<?php self::render_levels(); ?>
		</div>
		<?php
	}

	private static function render_levels(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- admin page, infrequent, small table.
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
				<p class="wbgam-settings-card__desc"><?php esc_html_e( 'Rename a level or change the points it needs. Members move up automatically when they cross a threshold.', 'wb-gamification' ); ?></p>
			</div>
			<div class="wbgam-settings-card__body">
				<form data-wb-gam-levels-bulk-form>
					<div class="wbgam-table-scroll">
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
												class="wbgam-btn wbgam-btn--sm wbgam-btn--danger"
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
					</div>

					<div class="wbgam-settings-section__footer wbgam-section__footer--flat">
						<button type="submit" class="wbgam-btn wbgam-btn--primary" data-wb-gam-levels-save>
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
						<button type="submit" class="wbgam-btn wbgam-btn--secondary" data-wb-gam-levels-add>
							<?php esc_html_e( 'Add Level', 'wb-gamification' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		</div>
		<?php
	}
}
