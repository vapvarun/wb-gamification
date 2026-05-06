<?php
/**
 * Admin: Submissions Queue
 *
 * Lists pending achievement submissions and lets admins approve / reject
 * each one. Approval routes through SubmissionService → PointsEngine so
 * the standard pipeline (badges, levels, hooks, materialised totals)
 * stays consistent.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\Engine\Registry;
use WBGam\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

final class SubmissionsPage {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Submissions', 'wb-gamification' ),
			__( 'Submissions', 'wb-gamification' ),
			'manage_options',
			'wb-gam-submissions',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'gamification_page_wb-gam-submissions' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'wb-gam-submissions-admin',
			plugins_url( 'assets/js/admin-submissions.js', WB_GAM_FILE ),
			array( 'wp-api-fetch', 'wp-i18n' ),
			WB_GAM_VERSION,
			true
		);
		wp_localize_script(
			'wb-gam-submissions-admin',
			'wbGamSubmissions',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'approved' => __( 'Approved.', 'wb-gamification' ),
					'rejected' => __( 'Rejected.', 'wb-gamification' ),
					'failed'   => __( 'Could not save.', 'wb-gamification' ),
					'reason'   => __( 'Reason for rejection (optional)', 'wb-gamification' ),
				),
			)
		);
	}

	public static function render_page(): void {
		$repo    = new SubmissionRepository();
		$pending = $repo->list( 'pending', 200, 0 );
		$total   = $repo->count_pending();
		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Submission Queue', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc">
						<?php
						printf(
							/* translators: %d: pending count */
							esc_html( _n( '%d submission awaiting review.', '%d submissions awaiting review.', $total, 'wb-gamification' ) ),
							(int) $total
						);
						?>
					</p>
				</div>
			</header>

			<div class="wbgam-card">
				<div class="wbgam-card-body wbgam-card-body--flush">
					<?php if ( empty( $pending ) ) : ?>
						<div class="wbgam-empty">
							<div class="wbgam-empty-icon"><span class="icon-check-circle wbgam-icon-xl"></span></div>
							<h3><?php esc_html_e( 'No pending submissions.', 'wb-gamification' ); ?></h3>
							<p><?php esc_html_e( 'When members submit achievements that require approval, they\'ll appear here.', 'wb-gamification' ); ?></p>
						</div>
					<?php else : ?>
						<table class="wbgam-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Evidence', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Submitted', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Decision', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $pending as $row ) :
									$user = get_userdata( (int) $row['user_id'] );
									$action = Registry::get_action( (string) $row['action_id'] );
									$action_label = $action['label'] ?? $row['action_id'];
									?>
									<tr data-submission-id="<?php echo (int) $row['id']; ?>">
										<td><?php echo esc_html( $user ? $user->display_name : '#' . $row['user_id'] ); ?></td>
										<td><code><?php echo esc_html( $action_label ); ?></code></td>
										<td>
											<?php if ( ! empty( $row['evidence'] ) ) : ?>
												<p class="wbgam-text-muted"><?php echo esc_html( wp_trim_words( (string) $row['evidence'], 30 ) ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $row['evidence_url'] ) ) : ?>
												<a href="<?php echo esc_url( (string) $row['evidence_url'] ); ?>" target="_blank" rel="noopener">
													<?php esc_html_e( 'View link →', 'wb-gamification' ); ?>
												</a>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $row['created_at'] ) ) ); ?></td>
										<td>
											<button type="button" class="wbgam-btn wbgam-btn--sm" data-wb-gam-submission-approve>
												<?php esc_html_e( 'Approve', 'wb-gamification' ); ?>
											</button>
											<button type="button" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary" data-wb-gam-submission-reject>
												<?php esc_html_e( 'Reject', 'wb-gamification' ); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
