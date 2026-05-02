<?php
/**
 * Admin: Manual Point Award
 *
 * Lets admins grant or deduct points from any user directly from the
 * WordPress admin without writing code. Routes through PointsEngine::award()
 * and PointsEngine::debit() so all hooks fire normally.
 *
 * @package WB_Gamification
 * @since   0.5.0
 */

namespace WBGam\Admin;

use WBGam\Engine\PointsEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Award Points admin page — form, nonce handling, and recent award history.
 *
 * @package WB_Gamification
 */
final class ManualAwardPage {

	/**
	 * Maximum points grantable or deductible in a single manual award.
	 *
	 * @var int
	 */
	private const MAX_POINTS = 10000;

	/**
	 * Register admin_menu and admin_post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_post_wb_gam_manual_award', array( __CLASS__, 'handle_award' ) );
	}

	/**
	 * Register the Award Points submenu under WB Gamification.
	 *
	 * @return void
	 */
	public static function add_submenu(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Award Points', 'wb-gamification' ),
			__( 'Award Points', 'wb-gamification' ),
			'wb_gam_award_manual',
			'wb-gamification-award',
			array( __CLASS__, 'render_page' )
		);
	}

	// ── Page render ──────────────────────────────────────────────────────────

	/**
	 * Render the Award Points admin page with form and recent history table.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_award_manual' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; GET param indicates result of a prior POST.
		$notice = '';
		if ( ! empty( $_GET['wb_gam_award_done'] ) ) {
			$result = sanitize_key( $_GET['wb_gam_award_done'] );
			if ( 'ok' === $result ) {
				$notice = 'saved';
			} elseif ( 'fail' === $result ) {
				$notice = 'error';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notice_map = array(
			'saved' => array( 'success', __( 'Points awarded successfully.', 'wb-gamification' ) ),
			'error' => array( 'error', __( 'Award failed — check user and points value.', 'wb-gamification' ) ),
		);

		$recent = self::get_recent_manual_awards( 20 );

		?>
		<div class="wrap wbgam-wrap">
			<h1 class="wbgam-page-title"><?php esc_html_e( 'Award Points', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Manually grant or deduct points from any user. All awards go through the standard engine so hooks, badges, and streaks fire normally.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible wb-gam-notice">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Award Form Card -->
			<div class="wbgam-card" style="margin-bottom:24px;">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Award or Deduct Points', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wb_gam_manual_award" />
						<?php wp_nonce_field( 'wb_gam_manual_award', 'wb_gam_nonce' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="wb_gam_award_user"><?php esc_html_e( 'User', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<?php
									wp_dropdown_users(
										array(
											'name' => 'wb_gam_user_id',
											'id'   => 'wb_gam_award_user',
											'show_option_none' => __( '— Select a user —', 'wb-gamification' ),
											'option_none_value' => '0',
										)
									);
									?>
									<p class="description"><?php esc_html_e( 'Select the member who will receive (or lose) points.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wb_gam_award_points"><?php esc_html_e( 'Points', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<input
										type="number"
										id="wb_gam_award_points"
										name="wb_gam_points"
										class="small-text wbgam-input"
										value="0"
										min="-<?php echo esc_attr( self::MAX_POINTS ); ?>"
										max="<?php echo esc_attr( self::MAX_POINTS ); ?>"
										required
									/>
									<p class="description">
										<?php
										printf(
											/* translators: %d = max points per action */
											esc_html__( 'Positive to award, negative to deduct. Max ±%d per action.', 'wb-gamification' ),
											(int) self::MAX_POINTS // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer constant, no XSS risk.
										);
										?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="wb_gam_award_note"><?php esc_html_e( 'Reason / Note', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="wb_gam_award_note"
										name="wb_gam_note"
										class="regular-text wbgam-input"
										placeholder="<?php esc_attr_e( 'e.g. Contest winner, Support bonus, Policy violation', 'wb-gamification' ); ?>"
										maxlength="200"
									/>
									<p class="description"><?php esc_html_e( 'Optional. Visible in the award history below and stored as user meta.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p><button type="submit" class="wbgam-btn"><?php esc_html_e( 'Award Points', 'wb-gamification' ); ?></button></p>
					</form>
				</div>
			</div>

			<!-- Recent Awards History -->
			<?php if ( ! empty( $recent ) ) : ?>
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Recent Manual Awards', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body" style="padding:0;">
					<table class="wbgam-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'User', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Points', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Note', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Date', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $row ) : ?>
								<?php $user = get_userdata( (int) $row['user_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $user ? $user->display_name : '#' . $row['user_id'] ); ?></td>
								<td>
									<span class="wbgam-pill <?php echo (int) $row['points'] >= 0 ? 'wbgam-pill--active' : 'wbgam-pill--danger'; ?>">
										<?php echo esc_html( ( (int) $row['points'] >= 0 ? '+' : '' ) . number_format_i18n( (int) $row['points'] ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( (string) ( $row['note'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['created_at'] ) ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-star-filled" style="font-size:48px;width:48px;height:48px;color:var(--wbgam-text-muted);"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No manual awards yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Use the form above to grant or deduct points from any member.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Form handler ─────────────────────────────────────────────────────────

	/**
	 * Handle the wb_gam_manual_award admin-post form submission.
	 *
	 * Verifies nonce and capability before awarding or debiting points.
	 * Redirects back to the admin page with a success/fail notice parameter.
	 *
	 * @return void
	 */
	public static function handle_award(): void {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_award_manual' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wb-gamification' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'wb_gam_manual_award', 'wb_gam_nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handled by check_admin_referer above.
		$user_id = absint( wp_unslash( $_POST['wb_gam_user_id'] ?? 0 ) );
		$points  = self::normalize_points( intval( wp_unslash( $_POST['wb_gam_points'] ?? 0 ) ) );
		$note    = sanitize_text_field( wp_unslash( $_POST['wb_gam_note'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$success = false;

		if ( $user_id > 0 && 0 !== $points ) {
			if ( $points > 0 ) {
				$success = PointsEngine::award( $user_id, 'manual_admin', $points );
			} else {
				$success = PointsEngine::debit( $user_id, abs( $points ), 'manual_admin_deduct' );
			}

			if ( $success && '' !== $note ) {
				update_user_meta( $user_id, '_wb_gam_last_award_note', $note );
			}
		}

		$redirect = add_query_arg(
			'wb_gam_award_done',
			$success ? 'ok' : 'fail',
			admin_url( 'admin.php?page=wb-gamification-award' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Public helpers (used by tests) ────────────────────────────────────────

	/**
	 * Clamp a raw points value to ±MAX_POINTS.
	 *
	 * @param int $points Raw input value from the form.
	 * @return int Clamped value in the range [-MAX_POINTS, MAX_POINTS].
	 */
	public static function normalize_points( int $points ): int {
		if ( $points > self::MAX_POINTS ) {
			return self::MAX_POINTS;
		}
		if ( $points < -self::MAX_POINTS ) {
			return -self::MAX_POINTS;
		}
		return $points;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Fetch recent manual point awards from the ledger.
	 *
	 * Note: award notes are stored in user meta (last note per user), not in the
	 * points table, so the note shown may not match older rows for the same user.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, array{user_id: int, points: int, note: string, created_at: string}>
	 */
	private static function get_recent_manual_awards( int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list view, infrequent, no caching needed.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, points, created_at
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE action_id IN ('manual_admin', 'manual_admin_deduct')
				  ORDER BY created_at DESC
				  LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);

		$result = array();
		foreach ( ( $rows ? $rows : array() ) as $row ) {
			$uid         = (int) $row['user_id'];
			$row['note'] = (string) get_user_meta( $uid, '_wb_gam_last_award_note', true );
			$result[]    = $row;
		}

		return $result;
	}
}
