<?php
/**
 * WB Gamification — competitor import admin page.
 *
 * Detects installed source plugins with data, lets a manager preview the
 * migration (dry-run reconciliation, no writes) and then run it. The heavy
 * lifting is the ImportController REST routes + the importer classes.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Admin;

use WBGam\Engine\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Import screen shell + enqueues its assets.
 *
 * @package WB_Gamification
 */
final class ImportPage {

	private const PAGE_SLUG = 'wb-gamification-import';
	private const HOOK      = 'gamification_page_wb-gamification-import';

	/**
	 * Hook the submenu + assets.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the Import submenu.
	 */
	public static function add_submenu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Import', 'wb-gamification' ),
			__( 'Import', 'wb-gamification' ),
			'wb_gam_manage_members',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wb-gam-page-import',
			plugins_url( 'assets/css/admin/pages/import.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
		wp_enqueue_script(
			'wb-gam-admin-rest-utils',
			plugins_url( 'assets/js/admin-rest-utils.js', WB_GAM_FILE ),
			array(),
			WB_GAM_VERSION,
			true
		);
		wp_enqueue_script(
			'wb-gam-admin-import',
			plugins_url( 'assets/js/admin-import.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-import',
			'wbGamImport',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'loading'     => __( 'Detecting sources...', 'wb-gamification' ),
					'noSources'   => __( 'No supported source plugin (GamiPress, myCred, BadgeOS) has data to import.', 'wb-gamification' ),
					'available'   => __( 'Data found', 'wb-gamification' ),
					'unavailable' => __( 'No data', 'wb-gamification' ),
					'preview'     => __( 'Preview (dry run)', 'wb-gamification' ),
					'import'      => __( 'Run import', 'wb-gamification' ),
					'previewing'  => __( 'Previewing...', 'wb-gamification' ),
					'importing'   => __( 'Importing...', 'wb-gamification' ),
					'confirmBtn'  => __( 'Click again to confirm', 'wb-gamification' ),
					'points'      => __( 'Points', 'wb-gamification' ),
					'badges'      => __( 'Badges / Achievements', 'wb-gamification' ),
					'ranks'       => __( 'Ranks to Levels', 'wb-gamification' ),
					'match'       => __( 'Reconciles', 'wb-gamification' ),
					'mismatch'    => __( 'MISMATCH', 'wb-gamification' ),
					'user'        => __( 'User', 'wb-gamification' ),
					'imported'    => __( 'Imported', 'wb-gamification' ),
					'source'      => __( 'Source', 'wb-gamification' ),
					'done'        => __( 'Import complete.', 'wb-gamification' ),
					'error'       => __( 'Request failed.', 'wb-gamification' ),
					'rankNote'    => __( 'Rank mismatches usually mean this site already has levels that collide with the imported tiers.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Render the page shell (populated by admin-import.js).
	 */
	public static function render_page(): void {
		if ( ! Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}
		?>
		<div class="wrap wbgam-wrap wb-gam-import">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Import from another plugin', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Migrate points, badges/achievements, and ranks from GamiPress, myCred, or BadgeOS. Always Preview first: it reconciles what would import against the source plugin\'s own totals without writing anything. The real import is idempotent and backdates history.', 'wb-gamification' ); ?></p>
				</div>
			</header>
			<div id="wb-gam-import-app" class="wb-gam-import__app" aria-live="polite"></div>
		</div>
		<?php
	}
}
