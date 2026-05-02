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
		add_action( 'admin_post_wb_gam_save_challenge', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_delete_challenge', array( __CLASS__, 'handle_delete' ) );
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
			<h1 class="wbgam-page-title"><?php esc_html_e( 'Challenge Manager', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Create challenges to engage your community. Set an action, target, and bonus points.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Create/Edit Form Card -->
			<div class="wbgam-card" style="margin-bottom:24px;">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<?php echo $editing ? esc_html__( 'Edit Challenge', 'wb-gamification' ) : esc_html__( 'Create Challenge', 'wb-gamification' ); ?>
					</h3>
				</div>
				<div class="wbgam-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wb_gam_save_challenge', 'wb_gam_challenge_nonce' ); ?>
						<input type="hidden" name="action" value="wb_gam_save_challenge">
						<input type="hidden" name="challenge_id" value="<?php echo esc_attr( $editing ); ?>">

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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-challenges' ) ); ?>" class="wbgam-btn wbgam-btn--secondary" style="margin-left:8px;">
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
				<div class="wbgam-card-body" style="padding:0;">
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
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wb_gam_delete_challenge&challenge_id=' . $c['id'] ), 'wb_gam_delete_challenge_' . $c['id'] ) ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Delete this challenge?', 'wb-gamification' ); ?>')"
										class="wbgam-btn wbgam-btn--sm wbgam-btn--danger" style="margin-left:4px;">
										<?php esc_html_e( 'Delete', 'wb-gamification' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php else : ?>
			<div class="wbgam-empty">
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-flag" style="font-size:48px;width:48px;height:48px;color:var(--wb-gam-locked);"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No challenges yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Create your first challenge above to engage your community.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the challenge create/update form submission via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wb_gam_save_challenge', 'wb_gam_challenge_nonce' );

		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_challenges' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_challenges';
		$id    = absint( $_POST['challenge_id'] ?? 0 );

		$data = array(
			'title'        => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'action_id'    => sanitize_key( $_POST['action_id'] ?? '' ),
			'target'       => max( 1, absint( $_POST['target'] ?? 10 ) ),
			'bonus_points' => max( 0, absint( wp_unslash( $_POST['bonus_points'] ?? 50 ) ) ),
			'starts_at'    => sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) ),
			'ends_at'      => sanitize_text_field( wp_unslash( $_POST['ends_at'] ?? '' ) ),
			'status'       => 'active',
			'type'         => 'individual',
		);

		$formats = array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row update.
			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

			/**
			 * Fires after a challenge is updated by an admin.
			 *
			 * @since 1.0.0
			 * @param int   $challenge_id Challenge ID.
			 * @param array $data         Challenge data that was saved.
			 */
			do_action( 'wb_gamification_challenge_updated', $id, $data );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row insert.
			$wpdb->insert( $table, $data, $formats );
			$new_id = (int) $wpdb->insert_id;

			/**
			 * Fires after a new challenge is created by an admin.
			 *
			 * @since 1.0.0
			 * @param int   $challenge_id New challenge ID.
			 * @param array $data         Challenge data.
			 */
			do_action( 'wb_gamification_challenge_created', $new_id, $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-challenges&notice=saved' ) );
		exit;
	}

	/**
	 * Handle the challenge delete action via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = absint( $_GET['challenge_id'] ?? $_POST['challenge_id'] ?? 0 );
		check_admin_referer( 'wb_gam_delete_challenge_' . $id );

		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_challenges' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		global $wpdb;

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row delete.
			$wpdb->delete( $wpdb->prefix . 'wb_gam_challenges', array( 'id' => $id ), array( '%d' ) );

			/**
			 * Fires after a challenge is deleted by an admin.
			 *
			 * @since 1.0.0
			 * @param int $challenge_id The deleted challenge ID.
			 */
			do_action( 'wb_gamification_challenge_deleted', $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-challenges&notice=deleted' ) );
		exit;
	}
}
