<?php
/**
 * Setup Wizard — shown once on first activation.
 *
 * Guides the site owner through choosing a starter template so
 * points are pre-configured for their use-case before they ever
 * visit the main settings screen.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Displays the one-time setup wizard shown after first plugin activation.
 *
 * @package WB_Gamification
 */
final class SetupWizard {

	/**
	 * Register hooks — skipped entirely once the wizard has been completed.
	 */
	public static function init(): void {
		if ( get_option( 'wb_gam_wizard_complete' ) ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
	}

	/**
	 * Register a hidden submenu page for the wizard URL.
	 */
	public static function register_page(): void {
		add_submenu_page(
			null,
			__( 'Gamification Setup', 'wb-gamification' ),
			'',
			'manage_options',
			'wb-gamification-setup',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Redirect to the wizard immediately after plugin activation.
	 */
	public static function maybe_redirect(): void {
		if ( ! get_transient( 'wb_gam_do_redirect' ) ) {
			return;
		}
		delete_transient( 'wb_gam_do_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- activate-multi is a WP core flag, not user input.
		if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification-setup' ) );
			exit;
		}
	}

	/**
	 * Process the template selection form.
	 */
	public static function handle_submission(): void {
		if ( ! isset( $_POST['wb_gam_template'] ) ) {
			return;
		}
		check_admin_referer( 'wb_gam_setup_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wb-gamification' ) );
		}

		$template = sanitize_key( wp_unslash( $_POST['wb_gam_template'] ) );
		self::apply_template( $template );
		update_option( 'wb_gam_wizard_complete', true );
		wp_safe_redirect( admin_url( 'admin.php?page=wb-gamification&setup=complete' ) );
		exit;
	}

	/**
	 * Persist the chosen template's point values and leaderboard mode.
	 *
	 * @param string $template Template key (e.g. 'blog', 'community', 'course').
	 * @return void
	 */
	private static function apply_template( string $template ): void {
		$configs = self::get_template_configs();
		if ( ! isset( $configs[ $template ] ) ) {
			return;
		}
		foreach ( $configs[ $template ]['points'] as $action_id => $points ) {
			update_option( 'wb_gam_points_' . $action_id, (int) $points );
		}
		update_option( 'wb_gam_template', $template );
		update_option( 'wb_gam_leaderboard_mode', $configs[ $template ]['leaderboard'] );
	}

	/**
	 * Return all available starter template definitions.
	 *
	 * @return array<string, array{label: string, description: string, leaderboard: string, points: array<string, int>}>
	 */
	private static function get_template_configs(): array {
		return array(
			'blog'      => array(
				'label'       => __( 'Blog / Publisher', 'wb-gamification' ),
				'description' => __( 'Rewards writing and meaningful comments. For standalone WordPress blogs.', 'wb-gamification' ),
				'leaderboard' => 'monthly',
				'points'      => array(
					'wp_publish_post'          => 25,
					'wp_first_post'            => 20,
					'wp_leave_comment'         => 5,
					'wp_post_receives_comment' => 3,
				),
			),
			'community' => array(
				'label'       => __( 'Community Engagement', 'wb-gamification' ),
				'description' => __( 'Balanced — rewards posting, reactions, and social connection. Requires BuddyPress.', 'wb-gamification' ),
				'leaderboard' => 'weekly',
				'points'      => array(
					'bp_activity_update'    => 10,
					'bp_activity_comment'   => 5,
					'friends_accepted'      => 8,
					'groups_join'           => 8,
					'bp_reactions_received' => 3,
					'bp_give_kudos'         => 2,
					'bp_receive_kudos'      => 5,
				),
			),
			'course'    => array(
				'label'       => __( 'Online Course', 'wb-gamification' ),
				'description' => __( 'Course completion heavy — progress and credential badges.', 'wb-gamification' ),
				'leaderboard' => 'cohort',
				'points'      => array(
					'lesson_complete' => 20,
					'course_complete' => 100,
					'quiz_pass'       => 30,
					'wp_first_post'   => 10,
				),
			),
			'coaching'  => array(
				'label'       => __( 'Coaching Platform', 'wb-gamification' ),
				'description' => __( 'Private leaderboard by default — progress vs personal baseline, not peer comparison.', 'wb-gamification' ),
				'leaderboard' => 'private',
				'points'      => array(
					'check_in'      => 15,
					'goal_complete' => 50,
					'wp_first_post' => 10,
				),
			),
			'nonprofit' => array(
				'label'       => __( 'Nonprofit / Mission', 'wb-gamification' ),
				'description' => __( 'Mission-aligned language. Team leaderboards only — impact over individual competition.', 'wb-gamification' ),
				'leaderboard' => 'team-only',
				'points'      => array(
					'volunteer_hours'    => 30,
					'bp_activity_update' => 5,
					'groups_join'        => 10,
				),
			),
		);
	}

	/**
	 * Render the wizard page HTML.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wb-gamification' ) );
		}

		$configs = self::get_template_configs();
		?>
		<div class="wrap wb-gam-wizard-wrap">

			<div class="wb-gam-wizard-header">
				<h1>
					<?php esc_html_e( 'Welcome to WB Gamification', 'wb-gamification' ); ?>
				</h1>
				<p>
					<?php esc_html_e( 'Choose a starter template to pre-configure your point values. You can change everything later from the settings screen.', 'wb-gamification' ); ?>
				</p>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'wb_gam_setup_nonce' ); ?>

				<div class="wb-gam-wizard-grid">

					<?php foreach ( $configs as $key => $config ) : ?>
						<div class="wb-gam-wizard-card">
							<div>
								<h3 class="wb-gam-wizard-card__title">
									<?php echo esc_html( $config['label'] ); ?>
								</h3>
								<p class="wb-gam-wizard-card__desc">
									<?php echo esc_html( $config['description'] ); ?>
								</p>
								<p class="wb-gam-wizard-card__points">
									<?php
									$point_labels = array();
									foreach ( $config['points'] as $action => $pts ) {
										/* translators: 1: point value, 2: action name */
										$point_labels[] = sprintf( '%d pts &mdash; %s', (int) $pts, esc_html( $action ) );
									}
									echo implode( '<br>', $point_labels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</p>
							</div>
							<button
								type="submit"
								name="wb_gam_template"
								value="<?php echo esc_attr( $key ); ?>"
								class="button button-primary wb-gam-wizard-card__btn"
							>
								<?php esc_html_e( 'Use this template', 'wb-gamification' ); ?>
							</button>
						</div>
					<?php endforeach; ?>

				</div>

				<div class="wb-gam-wizard-skip-row">
					<button
						type="submit"
						name="wb_gam_template"
						value="skip"
						class="button button-link wb-gam-wizard-skip-btn"
					>
						<?php esc_html_e( 'Skip &amp; configure manually', 'wb-gamification' ); ?>
					</button>
					<p class="description wbgam-mt-sm">
						<?php esc_html_e( 'Default values are already set — you can always change them later.', 'wb-gamification' ); ?>
					</p>
				</div>

			</form>
		</div>
		<?php
	}
}
