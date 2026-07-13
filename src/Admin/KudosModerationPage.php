<?php
/**
 * WB Gamification - Kudos moderation admin page.
 *
 * A server-rendered, paginated roster of kudos (giver -> receiver, message,
 * date, status) with a per-row Revoke action and a status filter. This is the
 * moderation surface for the kudos data store: abusive or mistaken kudos can be
 * revoked, which reverses both point awards and hides the row from the public
 * feed. Revoke uses the DELETE /kudos/{id} REST endpoint via the shared admin
 * REST wrapper (accessible inline reason editor - no native dialog).
 *
 * A lightweight abuse signal flags giver<->receiver pairs that exchange kudos
 * repeatedly within the current page (kudo-trading rings).
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Admin;

use WBGam\Engine\KudosEngine;
use WBGam\Engine\ModuleToggles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Kudos moderation page.
 *
 * @package WB_Gamification
 */
final class KudosModerationPage {

	private const PAGE_SLUG = 'wb-gamification-kudos-moderation';
	private const HOOK      = 'gamification_page_wb-gamification-kudos-moderation';
	private const PER_PAGE  = 20;

	/**
	 * Hook the admin menu + asset enqueue.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the Kudos moderation submenu, unless the module is off.
	 */
	public static function add_submenu(): void {
		if ( ! self::module_enabled() ) {
			return;
		}
		add_submenu_page(
			'wb-gamification',
			__( 'Kudos Moderation', 'wb-gamification' ),
			__( 'Kudos Moderation', 'wb-gamification' ),
			'wb_gam_manage_members',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Whether the kudos module is enabled.
	 *
	 * @return bool
	 */
	private static function module_enabled(): bool {
		return ! class_exists( ModuleToggles::class ) || ModuleToggles::enabled( 'kudos' );
	}

	/**
	 * Enqueue the revoke-action script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wb-gam-page-kudos-moderation',
			plugins_url( 'assets/css/admin/pages/kudos-moderation.css', WB_GAM_FILE ),
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
			'wb-gam-admin-kudos',
			plugins_url( 'assets/js/admin-kudos.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);

		// The SHARED searchable member picker — the same component Award Points uses.
		//
		// The obvious thing here was wp_dropdown_users(), and it is the wrong thing: it renders one
		// <option> per member, which is precisely what took the Award Points page down on a large site
		// and had to be fixed once already. Two moderator filters is not a reason to reintroduce it.
		wp_enqueue_script(
			'wb-gam-admin-user-picker',
			plugins_url( 'assets/js/admin-user-picker.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-admin-user-picker',
			'wbGamUserPicker',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'typeToSearch' => __( 'Type at least 2 characters to search', 'wb-gamification' ),
					'searching'    => __( 'Searching…', 'wb-gamification' ),
					'noResults'    => __( 'No members found', 'wb-gamification' ),
					'selectUser'   => __( 'Anyone', 'wb-gamification' ),
					/* translators: %d: number of matching members found. */
					'resultsFound' => __( '%d members found', 'wb-gamification' ),
				),
			)
		);
		wp_localize_script(
			'wb-gam-admin-kudos',
			'wbGamKudos',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'reasonPrompt' => __( 'Reason (recorded in the audit log):', 'wb-gamification' ),
					'revokeNote'   => __( 'Revoke this kudos? Both members lose the points it awarded, and it is hidden from the feed.', 'wb-gamification' ),
					'confirm'      => __( 'Confirm revoke', 'wb-gamification' ),
					'cancel'       => __( 'Cancel', 'wb-gamification' ),
					'revoked'      => __( 'Kudos revoked.', 'wb-gamification' ),
					'failed'       => __( 'Action failed.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Display name for a member id, for re-rendering a chosen filter after reload.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	private static function member_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : null;

		return $user ? (string) $user->display_name : sprintf(
			/* translators: %d: user ID */
			__( 'User #%d', 'wb-gamification' ),
			$user_id
		);
	}

	/**
	 * Render the kudos moderation roster.
	 */
	public static function render_page(): void {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		// Read-only list navigation via GET (no state change → no nonce needed).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$status = in_array( $status, array( 'all', 'active', 'revoked' ), true ) ? $status : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;

		// A moderator's job here is to FIND something: a member being buried in kudos, a pair trading
		// them back and forth, a specific afternoon. With only a status tab that job is "read every
		// page", which stops being possible on the second screenful.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'giver_id'    => isset( $_GET['giver_id'] ) ? absint( wp_unslash( $_GET['giver_id'] ) ) : 0,
			'receiver_id' => isset( $_GET['receiver_id'] ) ? absint( wp_unslash( $_GET['receiver_id'] ) ) : 0,
			'date_from'   => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'     => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// A date that is not a date is not a filter.
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			if ( '' !== $filters[ $key ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters[ $key ] ) ) {
				$filters[ $key ] = '';
			}
		}

		$total  = KudosEngine::admin_count( $status, $filters );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$rows   = KudosEngine::admin_list( self::PER_PAGE, $offset, $status, $filters );

		/** Abuse threshold — same giver->receiver pair N+ times. */
		$threshold = (int) apply_filters( 'wb_gam_kudos_abuse_pair_threshold', 2 );

		// Computed across the WHOLE table, not the current page. A pair trading kudos back and forth
		// over a week only ever landed on one screen by luck, so the flag fired for the rings you had
		// already found and stayed silent for the ones you had not.
		$abuse = KudosEngine::abuse_pairs( $threshold );
		?>
		<div class="wrap wbgam-wrap">
			<hr class="wp-header-end" />
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Kudos Moderation', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Browse kudos and revoke abusive or mistaken ones. Revoking reverses the points both members received and hides the kudos from the feed - the row is kept and the change is audited. Kudos limits and point values are set under Settings > Kudos.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-body">
					<nav class="wb-gam-kudos-filter" aria-label="<?php esc_attr_e( 'Filter kudos by status', 'wb-gamification' ); ?>">
						<?php
						foreach ( array(
							'all'     => __( 'All', 'wb-gamification' ),
							'active'  => __( 'Active', 'wb-gamification' ),
							'revoked' => __( 'Revoked', 'wb-gamification' ),
						) as $key => $label ) :
							// Switching tab must KEEP the filters. Dropping them means every status change
							// throws away the search the moderator just built.
							$url = add_query_arg(
								array_filter(
									array(
										'page'        => self::PAGE_SLUG,
										'status'      => $key,
										'giver_id'    => $filters['giver_id'] ?: null,
										'receiver_id' => $filters['receiver_id'] ?: null,
										'date_from'   => $filters['date_from'] ?: null,
										'date_to'     => $filters['date_to'] ?: null,
									)
								),
								admin_url( 'admin.php' )
							);
							?>
							<a class="wb-gam-kudos-filter__tab<?php echo $status === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>

					<form method="get" class="wb-gam-kudos-search" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
						<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />

						<label class="wb-gam-kudos-search__field">
							<span><?php esc_html_e( 'From member', 'wb-gamification' ); ?></span>
							<div data-wb-gam-user-picker>
								<input type="search" class="regular-text" autocomplete="off" data-picker-search
									placeholder="<?php esc_attr_e( 'Search by name, username or email...', 'wb-gamification' ); ?>" />
								<select name="giver_id" data-picker-select>
									<?php if ( $filters['giver_id'] ) : ?>
										<option value="<?php echo esc_attr( (string) $filters['giver_id'] ); ?>" selected>
											<?php echo esc_html( self::member_name( $filters['giver_id'] ) ); ?>
										</option>
									<?php else : ?>
										<option value="0"><?php esc_html_e( 'Anyone', 'wb-gamification' ); ?></option>
									<?php endif; ?>
								</select>
								<p class="description" aria-live="polite" data-picker-status></p>
							</div>
						</label>

						<label class="wb-gam-kudos-search__field">
							<span><?php esc_html_e( 'To member', 'wb-gamification' ); ?></span>
							<div data-wb-gam-user-picker>
								<input type="search" class="regular-text" autocomplete="off" data-picker-search
									placeholder="<?php esc_attr_e( 'Search by name, username or email...', 'wb-gamification' ); ?>" />
								<select name="receiver_id" data-picker-select>
									<?php if ( $filters['receiver_id'] ) : ?>
										<option value="<?php echo esc_attr( (string) $filters['receiver_id'] ); ?>" selected>
											<?php echo esc_html( self::member_name( $filters['receiver_id'] ) ); ?>
										</option>
									<?php else : ?>
										<option value="0"><?php esc_html_e( 'Anyone', 'wb-gamification' ); ?></option>
									<?php endif; ?>
								</select>
								<p class="description" aria-live="polite" data-picker-status></p>
							</div>
						</label>

						<label class="wb-gam-kudos-search__field">
							<span><?php esc_html_e( 'From date', 'wb-gamification' ); ?></span>
							<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
						</label>

						<label class="wb-gam-kudos-search__field">
							<span><?php esc_html_e( 'To date', 'wb-gamification' ); ?></span>
							<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
						</label>

						<div class="wb-gam-kudos-search__actions">
							<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wb-gamification' ); ?></button>
							<?php if ( $filters['giver_id'] || $filters['receiver_id'] || $filters['date_from'] || $filters['date_to'] ) : ?>
								<a class="button-link" href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'page'   => self::PAGE_SLUG,
											'status' => $status,
										),
										admin_url( 'admin.php' )
									)
								);
								?>
																">
									<?php esc_html_e( 'Clear', 'wb-gamification' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</form>

					<?php if ( empty( $rows ) ) : ?>
						<div class="wbgam-empty">
							<p class="wbgam-empty-title"><?php esc_html_e( 'No kudos to show', 'wb-gamification' ); ?></p>
							<p><?php esc_html_e( 'When members give each other kudos, they appear here for moderation.', 'wb-gamification' ); ?></p>
						</div>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped wb-gam-kudos-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Giver', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Receiver', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Message', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Date', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<?php
									$pair_key = $row['giver_id'] . '-' . $row['receiver_id'];
									$flagged  = ( ( $abuse[ $pair_key ] ?? 0 ) >= $threshold );
									?>
									<tr data-kudos-id="<?php echo esc_attr( (string) $row['id'] ); ?>"<?php echo $flagged ? ' class="wb-gam-kudos-table__flagged"' : ''; ?>>
										<td><?php echo esc_html( $row['giver_name'] ? $row['giver_name'] : sprintf( /* translators: %d: user ID */ __( 'User #%d', 'wb-gamification' ), $row['giver_id'] ) ); ?></td>
										<td><?php echo esc_html( $row['receiver_name'] ? $row['receiver_name'] : sprintf( /* translators: %d: user ID */ __( 'User #%d', 'wb-gamification' ), $row['receiver_id'] ) ); ?></td>
										<td class="wb-gam-kudos-table__message"><?php echo esc_html( $row['message'] ? $row['message'] : '—' ); ?></td>
										<td class="wb-gam-kudos-table__date"><?php echo esc_html( $row['created_at'] ); ?></td>
										<td class="wb-gam-kudos-table__status">
											<?php if ( $row['revoked'] ) : ?>
												<span class="wbgam-badge wbgam-badge--danger"><?php esc_html_e( 'Revoked', 'wb-gamification' ); ?></span>
											<?php else : ?>
												<span class="wbgam-badge"><?php esc_html_e( 'Active', 'wb-gamification' ); ?></span>
												<?php if ( $flagged ) : ?>
													<span class="wbgam-badge wbgam-badge--warning" title="<?php esc_attr_e( 'This giver and receiver exchange kudos repeatedly.', 'wb-gamification' ); ?>"><?php esc_html_e( 'Pair', 'wb-gamification' ); ?></span>
												<?php endif; ?>
											<?php endif; ?>
										</td>
										<td class="wb-gam-kudos-table__actions">
											<?php if ( ! $row['revoked'] ) : ?>
												<button type="button" class="button button-small button-link-delete wb-gam-kudos-revoke"><?php esc_html_e( 'Revoke', 'wb-gamification' ); ?></button>
											<?php else : ?>
												<span class="wb-gam-kudos-table__muted">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php self::render_pager( $paged, $pages, $status, $total ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Render prev/next pagination.
	 *
	 * @param int    $paged  Current page.
	 * @param int    $pages  Total pages.
	 * @param string $status Active status filter.
	 * @param int    $total  Total row count.
	 */
	private static function render_pager( int $paged, int $pages, string $status, int $total ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$base = array(
			'page'   => self::PAGE_SLUG,
			'status' => $status,
		);
		echo '<nav class="wbgam-pager" aria-label="' . esc_attr__( 'Kudos pages', 'wb-gamification' ) . '">';
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
					/* translators: 1: current page, 2: total pages, 3: total kudos */
					__( 'Page %1$d of %2$d (%3$d kudos)', 'wb-gamification' ),
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
