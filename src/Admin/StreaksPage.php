<?php
/**
 * WB Gamification - Streaks moderation admin page.
 *
 * A server-rendered, paginated, sortable roster of members with an activity
 * streak, plus per-member Adjust / Reset actions. This is the admin surface
 * for the streak data store (closing the three-entry-point gap: frontend
 * `streak` block + this admin UI + the POST/DELETE REST on
 * /members/{id}/streak). Rendering is SSR so it works without JS and paginates
 * / sorts at the database; the two write actions call the REST endpoints via
 * the shared admin REST wrapper.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Admin;

use WBGam\Engine\StreakEngine;
use WBGam\Engine\ModuleToggles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Streaks moderation page.
 *
 * @package WB_Gamification
 */
final class StreaksPage {

	private const PAGE_SLUG = 'wb-gamification-streaks';
	private const HOOK      = 'gamification_page_wb-gamification-streaks';
	private const PER_PAGE  = 20;

	/**
	 * Hook the admin menu + asset enqueue.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the Streaks submenu under WB Gamification, unless the module is off.
	 */
	public static function add_submenu(): void {
		if ( ! self::module_enabled() ) {
			return;
		}
		add_submenu_page(
			'wb-gamification',
			__( 'Streaks', 'wb-gamification' ),
			__( 'Streaks', 'wb-gamification' ),
			'wb_gam_manage_members',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Whether the streaks module is enabled (mirrors the frontend toggle).
	 *
	 * @return bool
	 */
	private static function module_enabled(): bool {
		return ! class_exists( ModuleToggles::class ) || ModuleToggles::enabled( 'streaks' );
	}

	/**
	 * Enqueue the streak action script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wb-gam-page-streaks',
			plugins_url( 'assets/css/admin/pages/streaks.css', WB_GAM_FILE ),
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
			'wb-gam-admin-streaks',
			plugins_url( 'assets/js/admin-streaks.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-streaks',
			'wbGamStreaks',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'adjustPrompt' => __( 'New current streak (days):', 'wb-gamification' ),
					'reasonPrompt' => __( 'Reason (recorded in the audit log):', 'wb-gamification' ),
					'resetConfirm' => __( 'Reset this member\'s current streak to 0? Their longest-streak record is kept and the change is audited.', 'wb-gamification' ),
					'adjusted'     => __( 'Streak updated.', 'wb-gamification' ),
					'reset'        => __( 'Streak reset.', 'wb-gamification' ),
					'failed'       => __( 'Action failed.', 'wb-gamification' ),
					'save'         => __( 'Save', 'wb-gamification' ),
					'confirmReset' => __( 'Confirm reset', 'wb-gamification' ),
					'cancel'       => __( 'Cancel', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Render the streak roster (server-side, sortable, paginated).
	 */
	public static function render_page(): void {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		// Read-only list navigation via GET; sanitized + whitelisted, so no nonce
		// is required (no state change happens here — the write actions are
		// separately nonce-gated REST calls).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'current_streak';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'asc' : 'desc';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		$total    = StreakEngine::admin_count();
		$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged    = min( $paged, $pages );
		$offset   = ( $paged - 1 ) * self::PER_PAGE;
		$rows     = StreakEngine::admin_list( self::PER_PAGE, $offset, $orderby, $order );
		$user_map = self::prime_users( $rows );
		?>
		<div class="wrap wbgam-wrap">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Streaks', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Every member with an activity streak, ranked. Adjust or reset a member\'s streak here when they report a broken or wrong count - every change is recorded in the audit log. Streak length and grace rules are configured under Settings > Engagement.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-body">
					<?php if ( empty( $rows ) ) : ?>
						<div class="wbgam-empty">
							<p class="wbgam-empty-title"><?php esc_html_e( 'No streaks yet', 'wb-gamification' ); ?></p>
							<p><?php esc_html_e( 'Members build a streak by earning points on consecutive days. Once someone is active, they will appear here.', 'wb-gamification' ); ?></p>
						</div>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped wb-gam-streaks-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
									<?php
									self::sortable_th( __( 'Current', 'wb-gamification' ), 'current_streak', $orderby, $order, $paged );
									self::sortable_th( __( 'Longest', 'wb-gamification' ), 'longest_streak', $orderby, $order, $paged );
									self::sortable_th( __( 'Last active', 'wb-gamification' ), 'last_active', $orderby, $order, $paged );
									?>
									<th scope="col"><?php esc_html_e( 'Grace', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<?php
									$user = $user_map[ $row['user_id'] ] ?? null;
									$name = $user ? $user->display_name : sprintf( /* translators: %d: user ID */ __( 'User #%d', 'wb-gamification' ), $row['user_id'] );
									?>
									<tr data-user-id="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
										<td class="wb-gam-streaks-table__member">
											<?php echo get_avatar( $row['user_id'], 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar returns safe <img> markup. ?>
											<span class="wb-gam-streaks-table__name"><?php echo esc_html( $name ); ?></span>
										</td>
										<td class="wb-gam-streaks-table__current" data-col="current"><?php echo esc_html( (string) $row['current_streak'] ); ?></td>
										<td class="wb-gam-streaks-table__longest"><?php echo esc_html( (string) $row['longest_streak'] ); ?></td>
										<td class="wb-gam-streaks-table__last"><?php echo esc_html( $row['last_active'] ? $row['last_active'] : '—' ); ?></td>
										<td class="wb-gam-streaks-table__grace">
											<?php if ( $row['grace_used'] ) : ?>
												<span class="wbgam-badge wbgam-badge--warning"><?php esc_html_e( 'Used', 'wb-gamification' ); ?></span>
											<?php else : ?>
												<span class="wbgam-badge"><?php esc_html_e( 'Available', 'wb-gamification' ); ?></span>
											<?php endif; ?>
										</td>
										<td class="wb-gam-streaks-table__actions">
											<button type="button" class="button button-small wb-gam-streak-adjust" data-current="<?php echo esc_attr( (string) $row['current_streak'] ); ?>"><?php esc_html_e( 'Adjust', 'wb-gamification' ); ?></button>
											<button type="button" class="button button-small button-link-delete wb-gam-streak-reset"><?php esc_html_e( 'Reset', 'wb-gamification' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php self::render_pager( $paged, $pages, $orderby, $order, $total ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Batch-fetch WP_User objects for the visible rows (avoids N+1).
	 *
	 * @param array<int, array{user_id:int}> $rows Streak rows.
	 * @return array<int, \WP_User> Keyed by user ID.
	 */
	private static function prime_users( array $rows ): array {
		$ids = array_map( static fn( $r ) => (int) $r['user_id'], $rows );
		if ( empty( $ids ) ) {
			return array();
		}
		$map = array();
		foreach ( get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		) as $u ) {
			$map[ (int) $u->ID ] = $u;
		}
		return $map;
	}

	/**
	 * Render a sortable <th> that toggles order on the active column.
	 *
	 * @param string $label   Column label.
	 * @param string $col      Sort key.
	 * @param string $orderby Active sort key.
	 * @param string $order   Active direction ('asc'|'desc').
	 * @param int    $paged   Current page (reset to 1 on a sort change).
	 */
	private static function sortable_th( string $label, string $col, string $orderby, string $order, int $paged ): void {
		$is_active = ( $orderby === $col );
		$next      = ( $is_active && 'asc' === $order ) ? 'desc' : 'asc';
		$url       = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'orderby' => $col,
				'order'   => $next,
			),
			admin_url( 'admin.php' )
		);
		$indicator = $is_active ? ( 'asc' === $order ? ' ↑' : ' ↓' ) : '';
		printf(
			'<th scope="col" class="%s"><a href="%s">%s%s</a></th>',
			$is_active ? 'sorted ' . esc_attr( $order ) : 'sortable',
			esc_url( $url ),
			esc_html( $label ),
			esc_html( $indicator )
		);
	}

	/**
	 * Render prev/next pagination.
	 *
	 * @param int    $paged   Current page.
	 * @param int    $pages   Total pages.
	 * @param string $orderby Active sort key.
	 * @param string $order   Active direction.
	 * @param int    $total   Total row count.
	 */
	private static function render_pager( int $paged, int $pages, string $orderby, string $order, int $total ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$base = array(
			'page'    => self::PAGE_SLUG,
			'orderby' => $orderby,
			'order'   => $order,
		);
		echo '<nav class="wbgam-pager" aria-label="' . esc_attr__( 'Streak roster pages', 'wb-gamification' ) . '">';
		if ( $paged > 1 ) {
			printf(
				'<a class="button" href="%s">%s</a> ',
				esc_url( add_query_arg( array_merge( $base, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Previous', 'wb-gamification' )
			);
		}
		printf(
			'<span class="wbgam-pager__status">%s</span> ',
			esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages, 3: total members */
					__( 'Page %1$d of %2$d (%3$d members)', 'wb-gamification' ),
					$paged,
					$pages,
					$total
				)
			)
		);
		if ( $paged < $pages ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( add_query_arg( array_merge( $base, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ),
				esc_html__( 'Next', 'wb-gamification' )
			);
		}
		echo '</nav>';
	}
}
