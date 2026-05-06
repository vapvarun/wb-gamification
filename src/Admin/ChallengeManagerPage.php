<?php
/**
 * Admin: Challenge Manager
 *
 * Adds "Challenges" submenu under WB Gamification.
 * Lets admins create, edit, and delete challenge definitions
 * with smart defaults and minimal options.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Challenge Manager admin page for creating, editing, and deleting challenges.
 *
 * @package WB_Gamification
 */
final class ChallengeManagerPage {

	/**
	 * Register admin_menu and admin-post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_{save,delete}_challenge removed in 1.0.0 — page now
		// consumes /wb-gamification/v1/challenges (POST + DELETE) via the
		// generic admin-rest-form driver. See Tier 0.C migration.
	}

	/**
	 * Enqueue REST-driven JS bundle on the Challenges admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'gamification_page_wb-gam-challenges' !== $hook_suffix ) {
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
			'wbGamChallengesSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Challenge saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save the challenge.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Register the Challenges submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Challenges', 'wb-gamification' ),
			__( 'Challenges', 'wb-gamification' ),
			'wb_gam_manage_challenges',
			'wb-gam-challenges',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the challenge manager page with create/edit form and challenge list.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_challenges';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin list view, infrequent.
		$challenges = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed constant.
			"SELECT * FROM {$table} ORDER BY id DESC",
			ARRAY_A
		) ?: array();

		$actions = Registry::get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET params for routing only.
		$editing = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$notice  = sanitize_key( $_GET['notice'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$edit_data = null;
		if ( $editing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Single row edit lookup.
			$edit_data = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed constant.
					"SELECT * FROM {$table} WHERE id = %d",
					$editing
				),
				ARRAY_A
			);
		}

		$notice_map = array(
			'saved'   => array( 'success', __( 'Challenge saved.', 'wb-gamification' ) ),
			'deleted' => array( 'success', __( 'Challenge deleted.', 'wb-gamification' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Challenge Manager', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Create challenges to engage your community. Set an action, target, and bonus points.', 'wb-gamification' ); ?></p>
				</div>
			</header>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="wbgam-banner wbgam-banner--<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> wbgam-stack-block" role="status" aria-live="polite"><span class="wbgam-banner__icon dashicons dashicons-yes-alt" aria-hidden="true"></span><div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p></div></div>
			<?php endif; ?>

			<!-- Create/Edit Form Card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<?php echo $editing ? esc_html__( 'Edit Challenge', 'wb-gamification' ) : esc_html__( 'Create Challenge', 'wb-gamification' ); ?>
					</h3>
				</div>
				<div class="wbgam-card-body">
					<?php
					$challenge_rest_path = $editing ? '/challenges/' . (int) $editing : '/challenges';
					?>
					<form
						data-wb-gam-rest-form="wbGamChallengesSettings"
						data-wb-gam-rest-method="POST"
						data-wb-gam-rest-path="<?php echo esc_attr( $challenge_rest_path ); ?>"
						data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Challenge saved.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to save the challenge.', 'wb-gamification' ); ?>"
						data-wb-gam-rest-after="reload"
					>
						<table class="form-table">
							<tr>
								<th><label for="wb-gam-challenge-title"><?php esc_html_e( 'Title', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" name="title" id="wb-gam-challenge-title" class="regular-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['title'] ?? '' ); ?>"
										required placeholder="<?php esc_attr_e( 'e.g. Post 10 photos this week', 'wb-gamification' ); ?>">
									<p class="description"><?php esc_html_e( 'A short, descriptive name shown to members on the challenge card.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-challenge-action"><?php esc_html_e( 'Action', 'wb-gamification' ); ?></label></th>
								<td>
									<select name="action_id" id="wb-gam-challenge-action" class="wbgam-select">
										<?php foreach ( $actions as $id => $action ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $edit_data['action_id'] ?? '', $id ); ?>>
												<?php echo esc_html( $action['label'] ?? $id ); ?>
											</option>
										<?php endforeach; ?>
										<?php if ( empty( $actions ) ) : ?>
											<option value=""><?php esc_html_e( 'No actions registered', 'wb-gamification' ); ?></option>
										<?php endif; ?>
									</select>
									<p class="description"><?php esc_html_e( 'The user action that counts toward completing this challenge (e.g. publish a post, upload media).', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-challenge-target"><?php esc_html_e( 'Target Count', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="target" id="wb-gam-challenge-target" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['target'] ?? '10' ); ?>" min="1">
									<p class="description"><?php esc_html_e( 'How many times the member must perform the action to complete the challenge.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-challenge-bonus"><?php esc_html_e( 'Bonus Points', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="bonus_points" id="wb-gam-challenge-bonus" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['bonus_points'] ?? '50' ); ?>" min="0">
									<p class="description"><?php esc_html_e( 'Extra points awarded when the challenge is completed. Set to 0 for no bonus.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-challenge-starts"><?php esc_html_e( 'Start Date', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="datetime-local" name="starts_at" id="wb-gam-challenge-starts" class="wbgam-input"
										value="<?php echo esc_attr( $edit_data['starts_at'] ?? gmdate( 'Y-m-d\TH:i' ) ); ?>">
									<p class="description"><?php esc_html_e( 'When this challenge becomes available to members. Actions before this date will not count.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-challenge-ends"><?php esc_html_e( 'End Date', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="datetime-local" name="ends_at" id="wb-gam-challenge-ends" class="wbgam-input"
										value="<?php echo esc_attr( $edit_data['ends_at'] ?? gmdate( 'Y-m-d\TH:i', strtotime( '+7 days' ) ) ); ?>">
									<p class="description"><?php esc_html_e( 'Deadline for the challenge. Members must reach the target before this date. Defaults to 7 days from now.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p>
							<button type="submit" class="wbgam-btn">
								<?php echo $editing ? esc_html__( 'Update Challenge', 'wb-gamification' ) : esc_html__( 'Create Challenge', 'wb-gamification' ); ?>
							</button>
							<?php if ( $editing ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>" class="wbgam-btn wbgam-btn--secondary wbgam-ms-sm">
									<?php esc_html_e( 'Cancel', 'wb-gamification' ); ?>
								</a>
							<?php endif; ?>
						</p>
					</form>
				</div>
			</div>

			<!-- Challenge List -->
			<?php if ( ! empty( $challenges ) ) : ?>
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'All Challenges', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body wbgam-card-body--flush">
					<table class="wbgam-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Target', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Bonus', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Dates', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $challenges as $c ) : ?>
							<?php
							$action_label = $c['action_id'];
							if ( isset( $actions[ $c['action_id'] ]['label'] ) ) {
								$action_label = $actions[ $c['action_id'] ]['label'];
							}
							$status_class = 'active' === $c['status'] ? 'active' : 'info';
							?>
							<tr>
								<td><strong><?php echo esc_html( $c['title'] ); ?></strong></td>
								<td><code><?php echo esc_html( $action_label ); ?></code></td>
								<td><?php echo esc_html( $c['target'] ); ?></td>
								<td><?php echo esc_html( $c['bonus_points'] ); ?></td>
								<td>
									<span class="wbgam-pill wbgam-pill--<?php echo esc_attr( $status_class ); ?>">
										<?php echo esc_html( ucfirst( $c['status'] ) ); ?>
									</span>
								</td>
								<td>
									<?php
									$start = ! empty( $c['starts_at'] ) ? substr( $c['starts_at'], 0, 10 ) : '—';
									$end   = ! empty( $c['ends_at'] ) ? substr( $c['ends_at'], 0, 10 ) : '—';
									echo esc_html( $start . ' → ' . $end );
									?>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges&edit=' . $c['id'] ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary">
										<?php esc_html_e( 'Edit', 'wb-gamification' ); ?>
									</a>
									<button
										type="button"
										class="wbgam-btn wbgam-btn--sm wbgam-btn--danger"
										class="wbgam-ms-xs"
										data-wb-gam-rest-action="wbGamChallengesSettings"
										data-wb-gam-rest-method="DELETE"
										data-wb-gam-rest-path="/challenges/<?php echo (int) $c['id']; ?>"
										data-wb-gam-rest-confirm="<?php esc_attr_e( 'Delete this challenge?', 'wb-gamification' ); ?>"
										data-wb-gam-rest-success-toast="<?php esc_attr_e( 'Challenge deleted.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-error-toast="<?php esc_attr_e( 'Failed to delete challenge.', 'wb-gamification' ); ?>"
										data-wb-gam-rest-after="remove-row"
									>
										<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-flag wbgam-icon-xl"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No challenges yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Create your first challenge above to engage your community.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// handle_save() / handle_delete() removed in 1.0.0 (Tier 0.C). Challenges
	// are now written by ChallengesController (POST /challenges and POST
	// /challenges/{id}; DELETE /challenges/{id}). Backwards-compatible legacy
	// hooks (wb_gam_challenge_{created,updated,deleted}) still fire
	// from the REST controller until 1.1.0.
}
