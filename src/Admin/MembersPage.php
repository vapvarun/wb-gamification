<?php
/**
 * WB Gamification - Members roster admin page.
 *
 * A searchable, paginated table of every member with their points, level, and
 * badge count, plus per-member management actions (exclude from earning, reset
 * points, award). Backed entirely by the plugin's own REST collection endpoint
 * (GET wb-gamification/v1/members) so it never touches WP core or BuddyPress
 * REST routes.
 *
 * @package WB_Gamification
 * @since   1.5.3
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Members roster page.
 *
 * @package WB_Gamification
 */
final class MembersPage {

	private const PAGE_SLUG = 'wb-gamification-members';
	private const HOOK      = 'gamification_page_wb-gamification-members';

	/**
	 * Hook the admin menu + asset enqueue.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the Members submenu under WB Gamification.
	 */
	public static function add_submenu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Members', 'wb-gamification' ),
			__( 'Members', 'wb-gamification' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the roster table script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wb-gam-page-members',
			plugins_url( 'assets/css/admin/pages/members.css', WB_GAM_FILE ),
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
			'wb-gam-admin-members',
			plugins_url( 'assets/js/admin-members.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-members',
			'wbGamMembers',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'awardUrl' => esc_url_raw( admin_url( 'admin.php?page=wb-gamification-award' ) ),
				'i18n'     => array(
					'search'        => __( 'Search members by name, username, or email', 'wb-gamification' ),
					'member'        => __( 'Member', 'wb-gamification' ),
					'points'        => __( 'Points', 'wb-gamification' ),
					'level'         => __( 'Level', 'wb-gamification' ),
					'badges'        => __( 'Badges', 'wb-gamification' ),
					'status'        => __( 'Status', 'wb-gamification' ),
					'actions'       => __( 'Actions', 'wb-gamification' ),
					'active'        => __( 'Earning', 'wb-gamification' ),
					'excluded'      => __( 'Excluded', 'wb-gamification' ),
					'exclude'       => __( 'Exclude', 'wb-gamification' ),
					'include'       => __( 'Include', 'wb-gamification' ),
					'reset'         => __( 'Reset points', 'wb-gamification' ),
					'award'         => __( 'Award', 'wb-gamification' ),
					'resetConfirm'  => __( 'Reset this member\'s points to zero? Their ledger keeps a full audit trail.', 'wb-gamification' ),
					'loading'       => __( 'Loading members...', 'wb-gamification' ),
					'empty'         => __( 'No members found.', 'wb-gamification' ),
					'error'         => __( 'Could not load members.', 'wb-gamification' ),
					'prev'          => __( 'Previous', 'wb-gamification' ),
					'next'          => __( 'Next', 'wb-gamification' ),
					/* translators: 1: current page, 2: total pages */
					'pageOf'        => __( 'Page %1$d of %2$d', 'wb-gamification' ),
					'excludedToast' => __( 'Member excluded from earning.', 'wb-gamification' ),
					'includedToast' => __( 'Member can earn again.', 'wb-gamification' ),
					'resetToast'    => __( 'Points reset.', 'wb-gamification' ),
					'actionError'   => __( 'Action failed.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Render the page shell. The table itself is built by admin-members.js.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}
		?>
		<div class="wrap wbgam-wrap">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Members', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Find any member to see their points, level, and badges, and to award, reset, or exclude them from earning. For points ranking, see the Leaderboard.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-body">
					<div id="wb-gam-members-app" class="wb-gam-members">
						<div class="wb-gam-members__toolbar">
							<label for="wb-gam-members-search" class="screen-reader-text"><?php esc_html_e( 'Search members', 'wb-gamification' ); ?></label>
								<input type="search" id="wb-gam-members-search" class="wbgam-input wb-gam-members__search" placeholder="<?php esc_attr_e( 'Search members…', 'wb-gamification' ); ?>" aria-label="<?php esc_attr_e( 'Search members', 'wb-gamification' ); ?>" />
						</div>
						<div id="wb-gam-members-table" class="wb-gam-members__table" aria-live="polite"></div>
						<div id="wb-gam-members-pager" class="wb-gam-members__pager"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
