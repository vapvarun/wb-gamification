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
			array( 'wb-gam-dialog', 'wb-gam-toast-core' ),
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
					'loading'         => __( 'Detecting sources...', 'wb-gamification' ),
					'noSources'       => __( 'No supported source plugin (GamiPress, myCred, BadgeOS) has data to import.', 'wb-gamification' ),
					'available'       => __( 'Data found', 'wb-gamification' ),
					'unavailable'     => __( 'No data', 'wb-gamification' ),
					'preview'         => __( 'Preview (dry run)', 'wb-gamification' ),
					'import'          => __( 'Run import', 'wb-gamification' ),
					'previewing'      => __( 'Previewing...', 'wb-gamification' ),
					'confirmBtn'      => __( 'Click again to confirm', 'wb-gamification' ),
					/* translators: 1: point rows, 2: badge awards, 3: rank tiers, 4: number of background jobs. */
					'previewSummary'  => __( 'A run would import %1$s point rows, %2$s badge awards and %3$s rank tiers, in about %4$s background jobs.', 'wb-gamification' ),
					/* translators: 1: events already imported, 2: badge awards already imported. */
					'alreadyImported' => __( 'An earlier run already imported %1$s events and %2$s badge awards. Those rows are skipped, so running again is safe.', 'wb-gamification' ),
					'sample'          => __( 'First rows the run would import', 'wb-gamification' ),
					'previewNote'     => __( 'Preview only: nothing was written. After the import, every member is checked against the source plugin\'s own totals.', 'wb-gamification' ),
					'progress'        => __( 'Import progress', 'wb-gamification' ),
					'queued'          => __( 'Waiting for the background queue to start. This can take up to a minute.', 'wb-gamification' ),
					/* translators: 1: what the import is doing, 2: percent complete. */
					'statusLine'      => __( '%1$s: %2$s%%', 'wb-gamification' ),
					/* translators: 1: rows done in this step, 2: rows in this step. */
					'phaseCounts'     => __( '%1$s of %2$s', 'wb-gamification' ),
					'phaseLevels'     => __( 'Creating levels', 'wb-gamification' ),
					'phasePoints'     => __( 'Importing points', 'wb-gamification' ),
					'phaseAwards'     => __( 'Importing badges', 'wb-gamification' ),
					'phaseRecompute'  => __( 'Working out badges and levels for each member', 'wb-gamification' ),
					'phaseReconcile'  => __( 'Checking every member against the source', 'wb-gamification' ),
					'phaseDone'       => __( 'Finished', 'wb-gamification' ),
					/* translators: %s: the error message. */
					'failed'          => __( 'The import paused at its last checkpoint: %1$s', 'wb-gamification' ),
					'stalled'         => __( 'The background queue has stopped moving. Resume to continue from where it stopped. If this keeps happening, WP-Cron may be disabled: run the import from WP-CLI with --sync.', 'wb-gamification' ),
					'resume'          => __( 'Resume import', 'wb-gamification' ),
					/* translators: 1: imported, 2: skipped, 3: failed, 4: badges awarded, 5: levels created. */
					'totals'          => __( 'Imported %1$s, skipped %2$s already imported, failed %3$s, badges awarded %4$s, levels created %5$s.', 'wb-gamification' ),
					'reconciled'      => __( 'Every member reconciles against the source: points, badges and ranks.', 'wb-gamification' ),
					/* translators: 1: points mismatches, 2: badge mismatches, 3: rank mismatches. */
					'mismatches'      => __( '%1$s points, %2$s badge and %3$s rank mismatches.', 'wb-gamification' ),
					/* translators: %s: number of examples shown. */
					'firstMismatches' => __( 'First %1$s mismatches', 'wb-gamification' ),
					'user'            => __( 'User', 'wb-gamification' ),
					'action'          => __( 'Action', 'wb-gamification' ),
					'points'          => __( 'Points', 'wb-gamification' ),
					'when'            => __( 'When (UTC)', 'wb-gamification' ),
					'kind'            => __( 'Kind', 'wb-gamification' ),
					'imported'        => __( 'Imported', 'wb-gamification' ),
					'source'          => __( 'Source', 'wb-gamification' ),
					'error'           => __( 'Request failed.', 'wb-gamification' ),
					'rankNote'        => __( 'Rank mismatches usually mean this site already has levels that collide with the imported tiers.', 'wb-gamification' ),
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
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Migrate points, badges/achievements, and ranks from GamiPress, myCred, or BadgeOS. Preview first to see what would import without writing anything. The import then runs in the background, so a very large community can migrate without timing out and you can leave this page. It is safe to run again, it backdates history, and afterwards every member is checked against the source plugin\'s own totals.', 'wb-gamification' ); ?></p>
				</div>
			</header>
			<div id="wb-gam-import-app" class="wb-gam-import__app" aria-live="polite"></div>
		</div>
		<?php
	}
}
