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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		// admin_post_wb_gam_save_cohort_settings removed in 1.0.0:
		// page now consumes /wb-gamification/v1/cohort-settings (POST) directly
		// via assets/js/admin-cohort.js. See Tier 0.C migration.
	}

	/**
	 * Enqueue REST-driven JS bundle on the Cohort Leagues admin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// Cohort settings now live as a tab inside the main Settings page
		// (toplevel_page_wb-gamification) since 1.0.0. The legacy submenu
		// hook is preserved so any third-party that still routes to it
		// works during the transition.
		$is_cohort_surface = 'toplevel_page_wb-gamification' === $hook_suffix
			|| 'gamification_page_wb-gam-cohort' === $hook_suffix;
		if ( ! $is_cohort_surface ) {
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
			'wb-gam-admin-cohort',
			plugins_url( 'assets/js/admin-cohort.js', WB_GAM_FILE ),
			array( 'wb-gam-admin-rest-utils' ),
			WB_GAM_VERSION,
			true
		);

		wp_localize_script(
			'wb-gam-admin-cohort',
			'wbGamCohortSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wb-gamification/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saved'  => __( 'Cohort league settings saved.', 'wb-gamification' ),
					'failed' => __( 'Failed to save cohort settings.', 'wb-gamification' ),
				),
			)
		);
	}

	/**
	 * Register the Cohort Leagues submenu page under WB Gamification.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		// Cohort Leagues moved into the main Settings page as a tab in 1.0.0
		// (admin-ux-rulebook Rule 1 — CONFIG-only pages are settings tabs,
		// not separate submenus). Kept method as a no-op so any third-party
		// plugin that hooks 'admin_menu' depending on this submenu doesn't
		// 500 on the first request after upgrade.
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
		?>
		<div class="wrap wbgam-wrap">
			<header class="wbgam-page-header">
				<div class="wbgam-page-header__main">
					<h1 class="wbgam-page-header__title"><?php esc_html_e( 'Cohort Leagues', 'wb-gamification' ); ?></h1>
					<p class="wbgam-page-header__desc"><?php esc_html_e( 'Duolingo-style weekly leagues where members compete in tiers. Top performers promote, bottom performers demote each cycle.', 'wb-gamification' ); ?></p>
				</div>
			</header>
			<?php self::render_inline(); ?>
		</div>
		<?php
	}

	/**
	 * Render only the body of the cohort settings page — the cards, no
	 * outer `<div class="wrap">` and no page header. Designed for embedding
	 * inside the main Settings sidebar shell as a tab section.
	 *
	 * @since 1.0.0
	 */
	public static function render_inline(): void {
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
		<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
			<div class="wbgam-banner wbgam-banner--<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> wbgam-stack-block" role="status" aria-live="polite"><span class="wbgam-banner__icon icon-check-circle" aria-hidden="true"></span><div class="wbgam-banner__body"><p class="wbgam-banner__desc"><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p></div></div>
		<?php endif; ?>

			<!-- Settings Card -->
			<div class="wbgam-card wbgam-stack-block">
				<div class="wbgam-card-header">
					<h3 class="wbgam-card-title"><?php esc_html_e( 'League Settings', 'wb-gamification' ); ?></h3>
				</div>
				<div class="wbgam-card-body">
					<form data-wb-gam-cohort-form>
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
									<div class="wbgam-grid-2col">
										<div>
											<label for="wb-gam-tier-1" class="screen-reader-text"><?php esc_html_e( 'Tier 1', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_1" id="wb-gam-tier-1" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_1'] ); ?>"
												placeholder="<?php esc_attr_e( 'Bronze', 'wb-gamification' ); ?>">
											<small class="wbgam-text-muted"><?php esc_html_e( 'Tier 1 (lowest)', 'wb-gamification' ); ?></small>
										</div>
										<div>
											<label for="wb-gam-tier-2" class="screen-reader-text"><?php esc_html_e( 'Tier 2', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_2" id="wb-gam-tier-2" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_2'] ); ?>"
												placeholder="<?php esc_attr_e( 'Silver', 'wb-gamification' ); ?>">
											<small class="wbgam-text-muted"><?php esc_html_e( 'Tier 2', 'wb-gamification' ); ?></small>
										</div>
										<div>
											<label for="wb-gam-tier-3" class="screen-reader-text"><?php esc_html_e( 'Tier 3', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_3" id="wb-gam-tier-3" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_3'] ); ?>"
												placeholder="<?php esc_attr_e( 'Gold', 'wb-gamification' ); ?>">
											<small class="wbgam-text-muted"><?php esc_html_e( 'Tier 3', 'wb-gamification' ); ?></small>
										</div>
										<div>
											<label for="wb-gam-tier-4" class="screen-reader-text"><?php esc_html_e( 'Tier 4', 'wb-gamification' ); ?></label>
											<input type="text" name="tier_4" id="wb-gam-tier-4" class="regular-text wbgam-input"
												value="<?php echo esc_attr( $settings['tier_4'] ); ?>"
												placeholder="<?php esc_attr_e( 'Diamond', 'wb-gamification' ); ?>">
											<small class="wbgam-text-muted"><?php esc_html_e( 'Tier 4 (highest)', 'wb-gamification' ); ?></small>
										</div>
									</div>
									<p class="description wbgam-mt-sm"><?php esc_html_e( 'Customize the display names for each league tier.', 'wb-gamification' ); ?></p>
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
							<button type="submit" class="wbgam-btn" data-wb-gam-cohort-save>
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
					<ol class="wbgam-list-narrow">
						<li><?php esc_html_e( 'Active members are grouped into cohorts of ~30 users at similar tiers each cycle.', 'wb-gamification' ); ?></li>
						<li><?php esc_html_e( 'Members earn points through normal actions during the cycle.', 'wb-gamification' ); ?></li>
						<li><?php esc_html_e( 'At the end of each cycle, top performers promote and bottom performers demote.', 'wb-gamification' ); ?></li>
						<li><?php esc_html_e( 'Tiers are displayed on member profiles and the leaderboard.', 'wb-gamification' ); ?></li>
					</ol>
				</div>
			</div>
		<?php
	}

	// handle_save() removed in 1.0.0 (Tier 0.C). Cohort settings are now
	// written by CohortSettingsController::update_item() (POST
	// /wb-gamification/v1/cohort-settings). The legacy
	// `wb_gam_cohort_settings_saved` hook still fires from the REST
	// path for back-compat (kept until 1.1.0); new listeners should subscribe
	// to `wb_gam_after_save_cohort_settings`.
}
