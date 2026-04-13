<?php
/**
 * Admin: Cohort Leagues Settings
 *
 * Adds "Cohort Leagues" submenu under WB Gamification.
 * Lets admins configure Duolingo-style weekly league settings:
 * enable/disable, tier names, promotion/demotion percentages,
 * and league duration.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Admin;

use WBGam\Engine\FeatureFlags;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Cohort Leagues settings admin page.
 *
 * @package WB_Gamification
 */
final class CohortSettingsPage {

	/**
	 * Option key for cohort league settings.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'wb_gam_cohort_settings';

	/**
	 * Default settings values.
	 *
	 * @var array
	 */
	private const DEFAULTS = array(
		'tier_1'     => 'Bronze',
		'tier_2'     => 'Silver',
		'tier_3'     => 'Gold',
		'tier_4'     => 'Diamond',
		'promote_pct' => 20,
		'demote_pct'  => 20,
		'duration'    => 'weekly',
	);

	/**
	 * Register admin_menu and admin-post action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_wb_gam_save_cohort_settings', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Register the Cohort Leagues submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'wb-gamification',
			__( 'Cohort Leagues', 'wb-gamification' ),
			__( 'Cohort Leagues', 'wb-gamification' ),
			'manage_options',
			'wb-gam-cohort',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Get the current cohort settings merged with defaults.
	 *
	 * @return array
	 */
	private static function get_settings(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION_KEY, array() ),
			self::DEFAULTS
		);
	}

	/**
	 * Render the cohort leagues settings page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		$settings = self::get_settings();
		$features = FeatureFlags::get_all();
		$enabled  = ! empty( $features['cohort_leagues'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET param for notice routing only.
		$notice = sanitize_key( $_GET['notice'] ?? '' );

		$notice_map = array(
			'saved' => array( 'success', __( 'Cohort league settings saved.', 'wb-gamification' ) ),
			'error' => array( 'error', __( 'Something went wrong. Please try again.', 'wb-gamification' ) ),
		);

		?>
		<div class="wrap wbgam-wrap">
			<h1 class="wbgam-page-title"><?php esc_html_e( 'Cohort Leagues', 'wb-gamification' ); ?></h1>
			<p class="wbgam-page-desc"><?php esc_html_e( 'Duolingo-style weekly leagues where members compete in tiers. Top performers promote, bottom performers demote each cycle.', 'wb-gamification' ); ?></p>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Settings Card -->
			<div class="wbgam-card" style="margin-bottom:24px;">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'League Settings', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wb_gam_save_cohort_settings', 'wb_gam_cohort_nonce' ); ?>
						<input type="hidden" name="action" value="wb_gam_save_cohort_settings">

						<table class="form-table">
							<tr>
								<th><label for="wb-gam-cohort-enabled"><?php esc_html_e( 'Enable Cohort Leagues', 'wb-gamification' ); ?></label></th>
								<td>
									<select name="cohort_enabled" id="wb-gam-cohort-enabled" class="wbgam-select">
										<option value="1" <?php selected( $enabled, true ); ?>>
											<?php esc_html_e( 'Enabled', 'wb-gamification' ); ?>
										</option>
										<option value="0" <?php selected( $enabled, false ); ?>>
											<?php esc_html_e( 'Disabled', 'wb-gamification' ); ?>
										</option>
									</select>
									<p class="description"><?php esc_html_e( 'Toggle the cohort league system on or off site-wide.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Tier Names', 'wb-gamification' ); ?></th>
								<td>
									<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:480px;">
										<div>
											<label for="wb-gam-tier-1" class="screen-reader-text"><?php esc_html_e( 'Tier 1', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_1" id="wb-gam-tier-1" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_1'] ); ?>"
												placeholder="<?php esc_attr_e( 'Bronze', 'wb-gamification' ); ?>">
											<small style="color:var(--wb-gam-muted);"><?php esc_html_e( 'Tier 1 (lowest)', 'wb-gamification' ); ?></small>
										</div>
										<div>
											<label for="wb-gam-tier-2" class="screen-reader-text"><?php esc_html_e( 'Tier 2', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_2" id="wb-gam-tier-2" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_2'] ); ?>"
												placeholder="<?php esc_attr_e( 'Silver', 'wb-gamification' ); ?>">
											<small style="color:var(--wb-gam-muted);"><?php esc_html_e( 'Tier 2', 'wb-gamification' ); ?></small>
										</div>
										<div>
											<label for="wb-gam-tier-3" class="screen-reader-text"><?php esc_html_e( 'Tier 3', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_3" id="wb-gam-tier-3" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_3'] ); ?>"
												placeholder="<?php esc_attr_e( 'Gold', 'wb-gamification' ); ?>">
											<small style="color:var(--wb-gam-muted);"><?php esc_html_e( 'Tier 3', 'wb-gamification' ); ?></small>
										</div>
										<div>
											<label for="wb-gam-tier-4" class="screen-reader-text"><?php esc_html_e( 'Tier 4', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_4" id="wb-gam-tier-4" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_4'] ); ?>"
												placeholder="<?php esc_attr_e( 'Diamond', 'wb-gamification' ); ?>">
											<small style="color:var(--wb-gam-muted);"><?php esc_html_e( 'Tier 4 (highest)', 'wb-gamification' ); ?></small>
										</div>
									</div>
									<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Customize the display names for each league tier.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-promote-pct"><?php esc_html_e( 'Promotion %', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="promote_pct" id="wb-gam-promote-pct" class="small-text wbgam-input"
										value="<?php echo esc_attr( $settings['promote_pct'] ); ?>" min="1" max="50">
									<span>%</span>
									<p class="description"><?php esc_html_e( 'Top percentage of members in each cohort who get promoted to the next tier each cycle.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-demote-pct"><?php esc_html_e( 'Demotion %', 'wb-gamification' ); ?></label></th>
								<td>
									<input type="number" name="demote_pct" id="wb-gam-demote-pct" class="small-text wbgam-input"
										value="<?php echo esc_attr( $settings['demote_pct'] ); ?>" min="1" max="50">
									<span>%</span>
									<p class="description"><?php esc_html_e( 'Bottom percentage of members in each cohort who get demoted to a lower tier each cycle.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="wb-gam-duration"><?php esc_html_e( 'League Duration', 'wb-gamification' ); ?></label></th>
								<td>
									<select name="duration" id="wb-gam-duration" class="wbgam-select">
										<option value="weekly" <?php selected( $settings['duration'], 'weekly' ); ?>>
											<?php esc_html_e( 'Weekly', 'wb-gamification' ); ?>
										</option>
										<option value="monthly" <?php selected( $settings['duration'], 'monthly' ); ?>>
											<?php esc_html_e( 'Monthly', 'wb-gamification' ); ?>
										</option>
									</select>
									<p class="description"><?php esc_html_e( 'How often league standings reset and promotions/demotions are processed.', 'wb-gamification' ); ?></p>
								</td>
							</tr>
						</table>

						<p>
							<button type="submit" class="wbgam-btn">
								<?php esc_html_e( 'Save Settings', 'wb-gamification' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>

			<!-- How It Works Card -->
			<div class="wbgam-card">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'How Cohort Leagues Work', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<ol style="margin:0;padding-left:20px;line-height:1.8;">
						<li><?php esc_html_e( 'Active members are grouped into cohorts of ~30 users at similar tiers each cycle.', 'wb-gamification' ); ?></li>
						<li><?php esc_html_e( 'Members earn points through normal actions during the cycle.', 'wb-gamification' ); ?></li>
						<li><?php esc_html_e( 'At the end of each cycle, top performers promote and bottom performers demote.', 'wb-gamification' ); ?></li>
						<li><?php esc_html_e( 'Tiers are displayed on member profiles and the leaderboard.', 'wb-gamification' ); ?></li>
					</ol>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the cohort settings form submission via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		check_admin_referer( 'wb_gam_save_cohort_settings', 'wb_gam_cohort_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wb-gamification' ) );
		}

		// Save cohort-specific settings.
		$settings = array(
			'tier_1'      => sanitize_text_field( wp_unslash( $_POST['tier_1'] ?? 'Bronze' ) ),
			'tier_2'      => sanitize_text_field( wp_unslash( $_POST['tier_2'] ?? 'Silver' ) ),
			'tier_3'      => sanitize_text_field( wp_unslash( $_POST['tier_3'] ?? 'Gold' ) ),
			'tier_4'      => sanitize_text_field( wp_unslash( $_POST['tier_4'] ?? 'Diamond' ) ),
			'promote_pct' => max( 1, min( 50, absint( $_POST['promote_pct'] ?? 20 ) ) ),
			'demote_pct'  => max( 1, min( 50, absint( $_POST['demote_pct'] ?? 20 ) ) ),
			'duration'    => in_array( ( $_POST['duration'] ?? 'weekly' ), array( 'weekly', 'monthly' ), true )
				? sanitize_key( $_POST['duration'] )
				: 'weekly',
		);

		update_option( self::OPTION_KEY, $settings );

		// Update the feature flag for cohort_leagues.
		$features                    = FeatureFlags::get_all();
		$features['cohort_leagues']  = (bool) absint( $_POST['cohort_enabled'] ?? 1 );
		FeatureFlags::update( $features );

		/**
		 * Fires after cohort league settings are saved.
		 *
		 * @since 1.0.0
		 * @param array $settings The saved settings.
		 * @param bool  $enabled  Whether cohort leagues are enabled.
		 */
		do_action( 'wb_gamification_cohort_settings_saved', $settings, $features['cohort_leagues'] );

		wp_safe_redirect( admin_url( 'admin.php?page=wb-gam-cohort&notice=saved' ) );
		exit;
	}
}
