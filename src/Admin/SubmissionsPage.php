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
		wp_enqueue_style(
			'wb-gam-page-submissions',
			plugins_url( 'assets/css/admin/pages/submissions.css', WB_GAM_FILE ),
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);
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
			<hr class="wp-header-end" />
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

			<details class="wbgam-help-panel">
				<summary class="wbgam-help-panel__summary">
					<span class="icon-info" aria-hidden="true"></span>
					<?php esc_html_e( 'How this queue works', 'wb-gamification' ); ?>
				</summary>
				<div class="wbgam-help-panel__body">
					<p>
						<?php esc_html_e( 'Members submit evidence of an achievement (a description and an optional URL or attachment) using the Submit Achievement block. The submission lands here for your review. Approve to fire the standard earning event - the member receives points, may earn a badge, may level up, and any matching webhooks fire just like an automatic earn. Reject to record a reason for the member. There is no penalty for rejection.', 'wb-gamification' ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Where members submit:', 'wb-gamification' ); ?></strong>
						<?php
						printf(
							wp_kses(
								/* translators: %s: shortcode wrapped in code tags */
								__( 'Add the %s block (or shortcode) to a page on the front end. Logged-in members will see a form; everyone else sees a "log in to submit" prompt.', 'wb-gamification' ),
								array( 'code' => array() )
							),
							'<code>wb-gamification/submit-achievement</code>'
						);
						?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Anti-spam:', 'wb-gamification' ); ?></strong>
						<?php esc_html_e( 'Each member is rate-limited to 5 submissions per day by default. Filter `wb_gam_submission_daily_cap` to change.', 'wb-gamification' ); ?>
					</p>
				</div>
			</details>

			<div class="wbgam-card">
				<div class="wbgam-card-body wbgam-card-body--flush">
					<?php if ( empty( $pending ) ) : ?>
						<div class="wbgam-empty">
							<div class="wbgam-empty-icon"><span class="icon-circle-check wbgam-icon-xl"></span></div>
							<h3><?php esc_html_e( 'No pending submissions.', 'wb-gamification' ); ?></h3>
							<p>
								<?php esc_html_e( 'When members submit achievements that require approval, they\'ll appear here.', 'wb-gamification' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Tip: place the Submit Achievement block on a member-only page so people know where to share their wins.', 'wb-gamification' ); ?>
							</p>
						</div>
					<?php else : ?>
						<table class="wbgam-table">
							<thead>
								<tr>
									<th title="<?php esc_attr_e( 'The member who submitted the achievement.', 'wb-gamification' ); ?>"><?php esc_html_e( 'Member', 'wb-gamification' ); ?></th>
									<th title="<?php esc_attr_e( 'Which earnable action the member is claiming. Must match a registered action ID.', 'wb-gamification' ); ?>"><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
									<th title="<?php esc_attr_e( 'The text + optional URL the member submitted as proof.', 'wb-gamification' ); ?>"><?php esc_html_e( 'Evidence', 'wb-gamification' ); ?></th>
									<th><?php esc_html_e( 'Submitted', 'wb-gamification' ); ?></th>
									<th title="<?php esc_attr_e( 'Approve fires the standard earning event (points + badges + level). Reject lets you write a short reason.', 'wb-gamification' ); ?>"><?php esc_html_e( 'Decision', 'wb-gamification' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $pending as $row ) :
									$user         = get_userdata( (int) $row['user_id'] );
									$action       = Registry::get_action( (string) $row['action_id'] );
									$action_label = $action['label'] ?? $row['action_id'];
									?>
									<tr data-submission-id="<?php echo (int) $row['id']; ?>">
										<td><?php echo esc_html( $user ? $user->display_name : '#' . $row['user_id'] ); ?></td>
										<td><code><?php echo esc_html( $action_label ); ?></code></td>
										<td>
											<?php if ( ! empty( $row['evidence'] ) ) : ?>
												<?php
												// Submissions are stored as wp_kses_post-sanitised HTML
												// (SubmissionService::submit) because the front-end
												// editor is now wp_editor (commit 5928f95 — members
												// can embed images via the Add Media button when the
												// upload_files cap is granted).
												//
												// Pre-1.4.2 this cell stripped HTML for a trimmed text
												// excerpt and dropped every embedded image — admins
												// couldn't preview attached media (Basecamp #9927858743
												// reopen comment). Now the cell shows:
												// 1. Text excerpt (30 words, scanning-friendly)
												// 2. Thumbnail strip of every <img> in the submission
												// — each thumbnail links to the full-size image
												// 3. A <details> reveal of the full sanitised HTML
												// for the rare cases where the excerpt + thumbs
												// aren't enough.
												$wb_gam_evidence = (string) $row['evidence'];
												$wb_gam_excerpt  = wp_trim_words( wp_strip_all_tags( $wb_gam_evidence ), 30 );
												$wb_gam_img_urls = array();
												if ( false !== stripos( $wb_gam_evidence, '<img' )
													&& preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $wb_gam_evidence, $wb_gam_img_matches )
												) {
													$wb_gam_img_urls = array_slice( array_unique( $wb_gam_img_matches[1] ), 0, 8 );
												}
												?>
												<?php if ( '' !== $wb_gam_excerpt ) : ?>
													<p class="wbgam-text-muted"><?php echo esc_html( $wb_gam_excerpt ); ?></p>
												<?php endif; ?>
												<?php if ( ! empty( $wb_gam_img_urls ) ) : ?>
													<div class="wb-gam-submission-thumbs" role="list">
														<?php foreach ( $wb_gam_img_urls as $wb_gam_img_url ) : ?>
															<a href="<?php echo esc_url( $wb_gam_img_url ); ?>"
																target="_blank"
																rel="noopener"
																role="listitem"
																class="wb-gam-submission-thumb"
																aria-label="<?php esc_attr_e( 'Open attachment in a new tab', 'wb-gamification' ); ?>">
																<img src="<?php echo esc_url( $wb_gam_img_url ); ?>"
																	alt=""
																	loading="lazy"
																	decoding="async">
															</a>
														<?php endforeach; ?>
													</div>
												<?php endif; ?>
												<details class="wb-gam-submission-detail">
													<summary><?php esc_html_e( 'Show full submission', 'wb-gamification' ); ?></summary>
													<div class="wb-gam-submission-detail__body">
														<?php
														// $wb_gam_evidence was already passed through
														// wp_kses_post at insert time, so it's safe to
														// emit here. wp_kses_post() again as a
														// defence-in-depth wrapper — costs nothing
														// since the input is already conformant.
														echo wp_kses_post( $wb_gam_evidence );
														?>
													</div>
												</details>
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
