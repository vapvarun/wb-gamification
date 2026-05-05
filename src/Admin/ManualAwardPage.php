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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_manual_award removed in 1.0.0 — page now consumes
		// /wb-gamification/v1/points/award via the generic admin-rest-form driver.
	}

	/**
	 * Enqueue the REST-driven JS bundle on the Award Points page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gamification-award' !== $hook_suffix ) {
			return;
		}
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
			'wbGamManualAwardSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Points awarded.', 'wb-gamification' ),
					'failed' => __( 'Failed to award points.', 'wb-gamification' ),
				),
			)
		);
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
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Award Points', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Manually grant or deduct points from any user. All awards go through the standard engine so hooks, badges, and streaks fire normally.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="wbgam-banner wbgam-banner--<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> wbgam-stack-block" role="status" aria-live="polite">
					<span class="wbgam-banner__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<div class="wbgam-banner__body">
						<p class="wbgam-banner__desc"><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<!-- Award Form Card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'Award or Deduct Points', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form
						data-wb-gam-rest-form="wbGamManualAwardSettings"
						data-wb-gam-rest-method="POST"
						data-wb-gam-rest-path="/points/award"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Points awarded.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to award points.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload"
					>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="wb_gam_award_user"><?php esc_html_e( 'User', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<?php
									wp_dropdown_users(
										array(
											'name' => 'user_id',
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
										name="points"
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
									<label for="wb_gam_award_point_type"><?php esc_html_e( 'Currency', 'wb-gamification' ); ?></label>
								</th>
								<td>
									<?php
									$wb_gam_point_types = ( new \WBGam\Services\PointTypeService() )->list();
									$wb_gam_default_pt  = ( new \WBGam\Services\PointTypeService() )->default_slug();
									?>
									<select
										id="wb_gam_award_point_type"
										name="point_type"
										class="wbgam-select"
									>
										<?php foreach ( $wb_gam_point_types as $wb_gam_pt ) : ?>
											<option
												value="<?php echo esc_attr( (string) $wb_gam_pt['slug'] ); ?>"
												<?php selected( (string) $wb_gam_pt['slug'], $wb_gam_default_pt ); ?>
											>
												<?php echo esc_html( (string) $wb_gam_pt['label'] ); ?>
												<?php if ( (int) $wb_gam_pt['is_default'] === 1 ) : ?>
													<?php esc_html_e( '(default)', 'wb-gamification' ); ?>
												<?php endif; ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php
										printf(
											/* translators: %s URL of the Point Types admin page. */
											wp_kses(
												__( 'Which currency to award. <a href="%s">Manage point types</a>.', 'wb-gamification' ),
												array( 'a' => array( 'href' => array() ) )
											),
											esc_url( admin_url( 'admin.php?page=wb-gam-point-types' ) )
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
										name="note"
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
				<div class="wbgam-card-body wbgam-card-body--flush">
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
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-star-filled wbgam-icon-xl wbgam-icon-xl--muted"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No manual awards yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Use the form above to grant or deduct points from any member.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Form handler ─────────────────────────────────────────────────────────

	// handle_award() removed in 1.0.0 (Tier 0.C). Manual point awards are now
	// written by PointsController::award (POST /wb-gamification/v1/points/award).
	// The admin form uses the generic admin-rest-form driver via data-* attrs.

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
