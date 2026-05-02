<?php
/**
 * Admin: Community Challenges
 *
 * Adds "Community Challenges" submenu under WB Gamification.
 * Lets admins create, edit, and delete community-wide challenges
 * where the whole community works toward a shared goal.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Community Challenges admin page for creating, editing, and deleting group challenges.
 *
 * @package WB_Gamification
 */
final class CommunityChallengesPage {

	/**
	 * Register admin_menu and admin-post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_wb_gam_save_community_challenge', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wb_gam_delete_community_challenge', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the Community Challenges submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Community Challenges', 'wb-gamification' ),
			__( 'Community Challenges', 'wb-gamification' ),
			'wb_gam_manage_challenges',
			'wb-gam-community-challenges',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the community challenges page with create/edit form and challenge list.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_community_challenges';

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
			'saved'   => array( 'success', __( 'Community challenge saved.', 'wb-gamification' ) ),
			'deleted' => array( 'success', __( 'Community challenge deleted.', 'wb-gamification' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		?>
		<div class="wrap wbgam-wrap">
			<h1 class="wbgam-page-title"><?php esc_html_e( 'Community Challenges', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Create group goals where the whole community works together toward a shared target.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Create/Edit Form Card -->
			<div class="wbgam-card" style="margin-bottom:24px;">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title">
						<?php echo $editing ? esc_html__( 'Edit Community Challenge', 'wb-gamification' ) : esc_html__( 'Create Community Challenge', 'wb-gamification' ); ?>
					</h3>
				</div>
				<div class="wbgam-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wb_gam_save_community_challenge', 'wb_gam_community_challenge_nonce' ); ?>
						<input type="hidden" name="action" value="wb_gam_save_community_challenge">
						<input type="hidden" name="challenge_id" value="<?php echo esc_attr( $editing ); ?>">

						<table class="form-table">
							<tr>
								<th><label for="wb-gam-cc-title"><?php esc_html_e( 'Title', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="text" name="title" id="wb-gam-cc-title" class="regular-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['title'] ?? '' ); ?>"
										required placeholder="<?php esc_attr_e( 'e.g. Community Sprint: 1,000 Posts', 'wb-gamification' ); ?>">
									<p class="description"><?php esc_html_e( 'A motivating name for the community-wide challenge.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-cc-desc"><?php esc_html_e( 'Description', 'wb-gamification' ); ?></label></th>
								<td>
									<textarea name="description" id="wb-gam-cc-desc" class="large-text wbgam-input" rows="3"
										placeholder="<?php esc_attr_e( 'Describe the goal and what happens when the community reaches it.', 'wb-gamification' ); ?>"><?php echo esc_textarea( $edit_data['description'] ?? '' ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Optional description shown alongside the progress bar.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-cc-target"><?php esc_html_e( 'Target Count', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="target_count" id="wb-gam-cc-target" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['target_count'] ?? '100' ); ?>" min="1">
									<p class="description"><?php esc_html_e( 'Total number of actions the community must collectively reach.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-cc-action"><?php esc_html_e( 'Action', 'wb-gamification' ); ?></label></th>
								<td>
									<select name="target_action" id="wb-gam-cc-action" class="wbgam-select">
										<option value="*" <?php selected( $edit_data['target_action'] ?? '', '*' ); ?>>
											<?php esc_html_e( 'Any action', 'wb-gamification' ); ?>
										</option>
										<?php foreach ( $actions as $id => $action ) : ?>
											<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $edit_data['target_action'] ?? '', $id ); ?>>
												<?php echo esc_html( $action['label'] ?? $id ); ?>
											</option>
										<?php endforeach; ?>
										<?php if ( empty( $actions ) ) : ?>
											<option value="" disabled><?php esc_html_e( 'No actions registered', 'wb-gamification' ); ?></option>
										<?php endif; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Which user action counts toward this challenge. Choose "Any action" to count everything.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-cc-starts"><?php esc_html_e( 'Start Date', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="datetime-local" name="starts_at" id="wb-gam-cc-starts" class="wbgam-input"
										value="<?php echo esc_attr( $edit_data['starts_at'] ?? gmdate( 'Y-m-d\TH:i' ) ); ?>">
									<p class="description"><?php esc_html_e( 'When this community challenge becomes active. Actions before this date will not count.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-cc-ends"><?php esc_html_e( 'End Date', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="datetime-local" name="ends_at" id="wb-gam-cc-ends" class="wbgam-input"
										value="<?php echo esc_attr( $edit_data['ends_at'] ?? gmdate( 'Y-m-d\TH:i', strtotime( '+14 days' ) ) ); ?>">
									<p class="description"><?php esc_html_e( 'Deadline for the challenge. Defaults to 14 days from now.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-cc-bonus"><?php esc_html_e( 'Bonus Points', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="bonus_points" id="wb-gam-cc-bonus" class="small-text wbgam-input"
										value="<?php echo esc_attr( $edit_data['bonus_points'] ?? '100' ); ?>" min="0">
									<p class="description"><?php esc_html_e( 'Points awarded to every contributor when the challenge is completed. Set to 0 for no bonus.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p>
							<button type="submit" class="wbgam-btn">
								<?php echo $editing ? esc_html__( 'Update Challenge', 'wb-gamification' ) : esc_html__( 'Create Challenge', 'wb-gamification' ); ?>
							</button>
							<?php if ( $editing ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-community-challenges' ) ); ?>" class="wbgam-btn wbgam-btn--secondary" style="margin-left:8px;">
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
					<h3 class="wbgam-card-title"><?php esc_html_e( 'All Community Challenges', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body" style="padding:0;">
					<table class="wbgam-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Action', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Progress', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Bonus', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Dates', 'wb-gamification' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wb-gamification' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $challenges as $c ) : ?>
							<?php
							$action_label = '*' === $c['target_action'] ? __( 'Any action', 'wb-gamification' ) : $c['target_action'];
							if ( '*' !== $c['target_action'] && isset( $actions[ $c['target_action'] ]['label'] ) ) {
								$action_label = $actions[ $c['target_action'] ]['label'];
							}
							$progress     = (int) $c['global_progress'];
							$target       = max( 1, (int) $c['target_count'] );
							$pct          = min( 100, round( ( $progress / $target ) * 100 ) );
							$status_class = 'completed' === $c['status'] ? 'active' : ( 'active' === $c['status'] ? 'info' : 'info' );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $c['title'] ); ?></strong>
									<?php if ( ! empty( $c['description'] ) ) : ?>
										<br><small style="color:var(--wb-gam-muted);"><?php echo esc_html( wp_trim_words( $c['description'], 10 ) ); ?></small>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $action_label ); ?></code></td>
								<td style="min-width:160px;">
									<div style="display:flex;align-items:center;gap:8px;">
										<div style="flex:1;background:var(--wb-gam-bg-alt,#eee);border-radius:6px;height:10px;overflow:hidden;">
											<div style="width:<?php echo esc_attr( $pct ); ?>%;height:100%;background:var(--wb-gam-accent,#6366f1);border-radius:6px;transition:width .3s;"></div>
										</div>
										<span style="font-size:12px;white-space:nowrap;"><?php echo esc_html( number_format_i18n( $progress ) . ' / ' . number_format_i18n( $target ) ); ?></span>
									</div>
								</td>
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
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wb-gam-community-challenges&edit=' . $c['id'] ) ); ?>" class="wbgam-btn wbgam-btn--sm wbgam-btn--secondary">
										<?php esc_html_e( 'Edit', 'wb-gamification' ); ?>
									</a>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wb_gam_delete_community_challenge&challenge_id=' . $c['id'] ), 'wb_gam_delete_community_challenge_' . $c['id'] ) ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Delete this community challenge?', 'wb-gamification' ); ?>')"
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
				<div class="wbgam-empty-icon"><span class="dashicons dashicons-groups" style="font-size:48px;width:48px;height:48px;color:var(--wb-gam-locked);"></span></div>
				<div class="wbgam-empty-title"><?php esc_html_e( 'No community challenges yet', 'wb-gamification' ); ?></div>
				<p><?php esc_html_e( 'Create your first community challenge above to rally your members around a shared goal.', 'wb-gamification' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the community challenge create/update form submission via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wb_gam_save_community_challenge', 'wb_gam_community_challenge_nonce' );

		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_challenges' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_community_challenges';
		$id    = absint( $_POST['challenge_id'] ?? 0 );

		$data = array(
			'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'target_count'  => max( 1, absint( $_POST['target_count'] ?? 100 ) ),
			'target_action' => sanitize_key( $_POST['target_action'] ?? '*' ),
			'starts_at'     => sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) ),
			'ends_at'       => sanitize_text_field( wp_unslash( $_POST['ends_at'] ?? '' ) ),
			'bonus_points'  => max( 0, absint( wp_unslash( $_POST['bonus_points'] ?? 100 ) ) ),
			'status'        => 'active',
		);

		$formats = array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row update.
			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

			/**
			 * Fires after a community challenge is updated by an admin.
			 *
			 * @since 1.0.0
			 * @param int   $challenge_id Challenge ID.
			 * @param array $data         Challenge data that was saved.
			 */
			do_action( 'wb_gamification_community_challenge_updated', $id, $data );
		} else {
			$data['global_progress'] = 0;
			$formats[]               = '%d';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row insert.
			$wpdb->insert( $table, $data, $formats );
			$new_id = (int) $wpdb->insert_id;

			/**
			 * Fires after a new community challenge is created by an admin.
			 *
			 * @since 1.0.0
			 * @param int   $challenge_id New challenge ID.
			 * @param array $data         Challenge data.
			 */
			do_action( 'wb_gamification_community_challenge_created', $new_id, $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-community-challenges&notice=saved' ) );
		exit;
	}

	/**
	 * Handle the community challenge delete action via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$id = absint( $_GET['challenge_id'] ?? $_POST['challenge_id'] ?? 0 );
		check_admin_referer( 'wb_gam_delete_community_challenge_' . $id );

		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_challenges' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		global $wpdb;

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, single row delete.
			$wpdb->delete( $wpdb->prefix . 'wb_gam_community_challenges', array( 'id' => $id ), array( '%d' ) );

			// Also clean up contributions.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin form handler, cascade delete.
			$wpdb->delete( $wpdb->prefix . 'wb_gam_community_challenge_contributions', array( 'challenge_id' => $id ), array( '%d' ) );

			/**
			 * Fires after a community challenge is deleted by an admin.
			 *
			 * @since 1.0.0
			 * @param int $challenge_id The deleted challenge ID.
			 */
			do_action( 'wb_gamification_community_challenge_deleted', $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-community-challenges&notice=deleted' ) );
		exit;
	}
}
