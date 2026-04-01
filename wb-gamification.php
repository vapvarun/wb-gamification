<?php
/**
 * Plugin Name: WB Gamification
 * Plugin URI:  https://wbcomdesigns.com/
 * Description: Complete gamification plugin for BuddyPress and WordPress. Part of the Reign Stack. Points, badges, levels, leaderboards, challenges, and streaks — zero config, works out of the box.
 * Version:     1.0.1
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wb-gamification
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

define( 'WB_GAM_VERSION', '1.0.1' );
define( 'WB_GAM_FILE', __FILE__ );
define( 'WB_GAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WB_GAM_URL', plugin_dir_url( __FILE__ ) );
define( 'WB_GAM_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader — PSR-4 for all WBGam\ classes + functions.php.
require_once WB_GAM_PATH . 'vendor/autoload.php';

// Action Scheduler — must be loaded after autoloader, before plugins_loaded.
require_once WB_GAM_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// EDD SL SDK — free plugin auto-updates with preset key.
$wbgam_sdk_file = WB_GAM_PATH . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
if ( file_exists( $wbgam_sdk_file ) ) {
	require_once $wbgam_sdk_file;
}
$wbgam_edd_item_id = 0; // Placeholder — set when EDD product created on wbcomdesigns.com.
if ( $wbgam_edd_item_id > 0 ) {
	add_action(
		'edd_sl_sdk_registry',
		function ( $registry ) use ( $wbgam_edd_item_id ) {
			$registry->register(
				array(
					'id'      => 'wb-gamification',
					'url'     => 'https://wbcomdesigns.com',
					'item_id' => $wbgam_edd_item_id,
					'version' => WB_GAM_VERSION,
					'file'    => WB_GAM_FILE,
					'license' => 'wbcomfree4b7c2d9e1a5f8c3b6d0e7a2f9c1b5e33',
				)
			);
		}
	);
}

// Auto-activate preset key on first load (only when SDK is present and item_id is set).
if ( file_exists( $wbgam_sdk_file ) ) {
	add_action(
		'admin_init',
		function () {
			$preset_key = 'wbcomfree4b7c2d9e1a5f8c3b6d0e7a2f9c1b5e33';
			$item_id    = 0; // Placeholder — set when EDD product created.
			$option     = 'wb_gamification_license_key';
			$activated  = 'wb_gamification_preset_activated';

			if ( ! $item_id || get_option( $activated ) ) {
				return;
			}

			update_option( $option, $preset_key, false );

			$response = wp_remote_post(
				'https://wbcomdesigns.com',
				array(
					'timeout' => 15,
					'body'    => array(
						'edd_action' => 'activate_license',
						'license'    => $preset_key,
						'item_id'    => $item_id,
						'url'        => home_url(),
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( 'valid' === ( $body['license'] ?? '' ) ) {
					update_option( $activated, 1, false );
				}
			}
		}
	);
}

use WBGam\Engine\Registry;
use WBGam\Engine\Engine;
use WBGam\Engine\ManifestLoader;
use WBGam\Engine\FeatureFlags;
use WBGam\Engine\AsyncEvaluator;
use WBGam\Engine\LogPruner;
use WBGam\Engine\LeaderboardNudge;
use WBGam\Engine\Installer;
use WBGam\Engine\BadgeSharePage;
use WBGam\Engine\DbUpgrader;
use WBGam\Engine\TenureBadgeEngine;
use WBGam\Engine\WeeklyEmailEngine;
use WBGam\API\MembersController;
use WBGam\API\PointsController;
use WBGam\API\BadgesController;
use WBGam\API\LeaderboardController;
use WBGam\API\ActionsController;
use WBGam\API\KudosController;
use WBGam\API\BadgeShareController;
use WBGam\API\ChallengesController;
use WBGam\API\EventsController;
use WBGam\API\WebhooksController;
use WBGam\API\RulesController;
use WBGam\API\RecapController;
use WBGam\BuddyPress\HooksIntegration as BPHooks;
use WBGam\BuddyPress\ProfileIntegration;
use WBGam\BuddyPress\DirectoryIntegration;
use WBGam\BuddyPress\ActivityIntegration as BPActivity;
use WBGam\Integrations\WordPress\HooksIntegration as WPHooks;
use WBGam\Admin\SettingsPage;
use WBGam\Admin\SetupWizard;
use WBGam\Admin\AnalyticsDashboard;
use WBGam\Engine\CohortEngine;
use WBGam\Engine\StatusRetentionEngine;
use WBGam\Admin\BadgeAdminPage;
use WBGam\Admin\ChallengeManagerPage;
use WBGam\Admin\ManualAwardPage;
use WBGam\Admin\ApiKeysPage;
use WBGam\API\CredentialController;
use WBGam\API\RedemptionController;
use WBGam\API\LevelsController;
use WBGam\API\CapabilitiesController;
use WBGam\API\AbilitiesRegistration;
use WBGam\API\ApiKeyAuth;
use WBGam\Engine\CredentialExpiryEngine;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\ShortcodeHandler;

/**
 * Main plugin class — singleton loader.
 */
final class WB_Gamification {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'handle_unsubscribe' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'init', array( ShortcodeHandler::class, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// DB schema upgrades run first.
		add_action( 'plugins_loaded', array( DbUpgrader::class, 'init' ), 1 );

		// Boot sequence: ManifestLoader (5) → Registry (6) → AsyncEvaluator + Engine (8) → FeatureFlags (10).
		add_action( 'plugins_loaded', array( ManifestLoader::class, 'scan' ), 5 );
		add_action( 'plugins_loaded', array( Registry::class, 'init' ), 6 );
		add_action( 'plugins_loaded', array( ApiKeyAuth::class, 'init' ), 8 );
		add_action( 'plugins_loaded', array( AsyncEvaluator::class, 'init' ), 8 );
		add_action( 'plugins_loaded', array( Engine::class, 'init' ), 8 );
		add_action( 'plugins_loaded', array( WPHooks::class, 'init' ), 8 );
		add_action( 'plugins_loaded', array( BPHooks::class, 'init' ), 10 );

		// Leaderboard snapshot cron + object cache layer.
		add_action( 'plugins_loaded', array( LeaderboardEngine::class, 'init' ), 10 );

		// WP Abilities API registration + fallback REST endpoint for AI agent discovery.
		add_action( 'plugins_loaded', array( AbilitiesRegistration::class, 'init' ), 10 );

		// All remaining engines boot via FeatureFlags (core = always, pro = flag-gated).
		add_action( 'plugins_loaded', array( FeatureFlags::class, 'boot_engines' ), 10 );

		// Async award pipeline — collects events at priority 50 (after sync listeners
		// BadgeEngine@10, ChallengeEngine@15; before NotificationBridge@99).
		add_action( 'wb_gamification_points_awarded', array( AsyncEvaluator::class, 'enqueue' ), 50, 3 );

		// BuddyPress integrations — must boot on bp_loaded, not plugins_loaded.
		add_action( 'bp_loaded', array( ProfileIntegration::class, 'init' ) );
		add_action( 'bp_loaded', array( DirectoryIntegration::class, 'init' ) );
		add_action( 'bp_loaded', array( BPActivity::class, 'init' ) );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'plugins_loaded', array( SettingsPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( SetupWizard::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( AnalyticsDashboard::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( BadgeAdminPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( ChallengeManagerPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( ManualAwardPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( ApiKeysPage::class, 'init' ), 10 );
		}
	}

	public function handle_unsubscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- CSRF protection via hash_equals( wp_hash(...), $tok ) below.
		if ( empty( $_GET['wb_gam_unsub'] ) ) {
			return;
		}

		$uid  = (int) wp_unslash( $_GET['uid'] ?? 0 );
		$tok  = sanitize_text_field( wp_unslash( $_GET['tok'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$user = $uid ? get_userdata( $uid ) : null;

		if ( ! $user || ! hash_equals( wp_hash( 'unsub_' . $uid . $user->user_email ), $tok ) ) {
			return;
		}

		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'wb_gam_member_prefs',
			array(
				'user_id'           => $uid,
				'notification_mode' => 'none',
			),
			array( '%d', '%s' )
		);

		wp_safe_redirect( add_query_arg( 'wb_gam_unsub_done', '1', home_url() ) );
		exit;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wb-gamification', false, WB_GAM_PATH . 'languages' );
	}

	public function register_routes(): void {
		( new MembersController() )->register_routes();
		( new PointsController() )->register_routes();
		( new BadgesController() )->register_routes();
		( new LeaderboardController() )->register_routes();
		( new ActionsController() )->register_routes();
		( new KudosController() )->register_routes();
		( new BadgeShareController() )->register_routes();
		( new ChallengesController() )->register_routes();
		( new EventsController() )->register_routes();
		( new WebhooksController() )->register_routes();
		( new RulesController() )->register_routes();
		( new RecapController() )->register_routes();
		( new CredentialController() )->register_routes();
		( new RedemptionController() )->register_routes();
		( new LevelsController() )->register_routes();
		( new CapabilitiesController() )->register_routes();
	}

	public function register_blocks(): void {
		$blocks = array( 'leaderboard', 'member-points', 'badge-showcase', 'level-progress', 'challenges', 'streak', 'top-members', 'kudos-feed', 'year-recap', 'points-history' );
		foreach ( $blocks as $block ) {
			$path = WB_GAM_PATH . 'blocks/' . $block;
			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type( $path );
			}
		}
	}

	public function enqueue_assets(): void {
		// Register but don't enqueue — blocks and shortcodes will enqueue as needed.
		wp_register_style(
			'wb-gamification',
			WB_GAM_URL . 'assets/css/frontend.css',
			array(),
			WB_GAM_VERSION
		);
		wp_register_script_module(
			'wb-gamification-interactivity',
			WB_GAM_URL . 'assets/interactivity/index.js',
			array(),
			WB_GAM_VERSION
		);

		// Toast notification poller for logged-in users.
		if ( is_user_logged_in() ) {
			wp_enqueue_script(
				'wb-gamification-toast',
				WB_GAM_URL . 'assets/js/toast.js',
				array(),
				WB_GAM_VERSION,
				true
			);
			wp_localize_script(
				'wb-gamification-toast',
				'wbGamToast',
				array(
					'restUrl' => rest_url( 'wb-gamification/v1/' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	/**
	 * Enqueue shared admin CSS on all WB Gamification admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( false === strpos( $hook, 'wb-gamification' ) && false === strpos( $hook, 'wb-gam' ) ) {
			return;
		}

		// Suppress third-party admin notices on our pages for clean UX.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		// Re-add only our own notices.
		add_action( 'admin_notices', array( $this, 'render_own_notices' ) );
		wp_enqueue_style(
			'wb-gam-admin',
			WB_GAM_URL . 'assets/css/admin.css',
			array(),
			WB_GAM_VERSION
		);
		wp_enqueue_style(
			'wb-gamification-admin-premium',
			WB_GAM_URL . 'assets/css/admin-premium.css',
			array(),
			WB_GAM_VERSION
		);
	}

	/**
	 * Render only our own admin notices (suppresses third-party noise).
	 */
	public function render_own_notices(): void {
		// Only show WB Gamification notices (class="wb-gam-notice").
		// All third-party notices are suppressed via remove_all_actions + CSS.
	}
}

/**
 * Activation / deactivation hooks.
 */
register_activation_hook(
	__FILE__,
	function () {
		Installer::install();
		set_transient( 'wb_gam_do_redirect', true, 30 );
		LogPruner::activate();
		LeaderboardNudge::activate();
		LeaderboardEngine::activate();
		TenureBadgeEngine::activate();
		WeeklyEmailEngine::activate();
		CohortEngine::activate();
		StatusRetentionEngine::activate();
		CredentialExpiryEngine::activate();
		BadgeSharePage::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		LogPruner::deactivate();
		LeaderboardNudge::deactivate();
		LeaderboardEngine::deactivate();
		TenureBadgeEngine::deactivate();
		WeeklyEmailEngine::deactivate();
		CohortEngine::deactivate();
		StatusRetentionEngine::deactivate();
		CredentialExpiryEngine::deactivate();
		BadgeSharePage::deactivate();
		flush_rewrite_rules();
	}
);

/**
 * Boot.
 */
add_action(
	'plugins_loaded',
	function () {
		WB_Gamification::instance();
	},
	0
);

/**
 * Fires after the free plugin is fully loaded. Pro plugin hooks here.
 *
 * @since 1.0.0
 */
add_action(
	'plugins_loaded',
	function () {
		do_action( 'wb_gam_free_loaded' );
	},
	20
);

/**
 * WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'cli_init',
		function () {
			WP_CLI::add_command( 'wb-gamification points',  WBGam\CLI\PointsCommand::class );
			WP_CLI::add_command( 'wb-gamification member',  WBGam\CLI\MemberCommand::class );
			WP_CLI::add_command( 'wb-gamification actions', WBGam\CLI\ActionsCommand::class );
			WP_CLI::add_command( 'wb-gamification logs',    WBGam\CLI\LogsCommand::class );
			WP_CLI::add_command( 'wb-gamification export',  WBGam\CLI\ExportCommand::class );
		}
	);
}
