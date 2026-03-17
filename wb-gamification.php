<?php
/**
 * Plugin Name: WB Gamification
 * Plugin URI:  https://wbcomdesigns.com/
 * Description: Complete gamification plugin for BuddyPress and WordPress. Part of the Reign Stack. Points, badges, levels, leaderboards, challenges, and streaks — zero config, works out of the box.
 * Version:     0.3.0
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

define( 'WB_GAM_VERSION', '0.3.0' );
define( 'WB_GAM_FILE', __FILE__ );
define( 'WB_GAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WB_GAM_URL', plugin_dir_url( __FILE__ ) );
define( 'WB_GAM_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader — PSR-4 for all WBGam\ classes + functions.php.
require_once WB_GAM_PATH . 'vendor/autoload.php';

// Action Scheduler — must be loaded after autoloader, before plugins_loaded.
require_once WB_GAM_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

use WBGam\Engine\Registry;
use WBGam\Engine\Engine;
use WBGam\Engine\ManifestLoader;
use WBGam\Engine\LogPruner;
use WBGam\Engine\LeaderboardNudge;
use WBGam\Engine\Installer;
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\ChallengeEngine;
use WBGam\Engine\RankAutomation;
use WBGam\Engine\PersonalRecordEngine;
use WBGam\Engine\NotificationBridge;
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
use WBGam\Engine\KudosEngine;
use WBGam\Abilities\AbilitiesRegistrar;
use WBGam\BuddyPress\HooksIntegration as BPHooks;
use WBGam\BuddyPress\ProfileIntegration;
use WBGam\BuddyPress\DirectoryIntegration;
use WBGam\BuddyPress\ActivityIntegration as BPActivity;
use WBGam\Integrations\WordPress\HooksIntegration as WPHooks;
use WBGam\Admin\SettingsPage;
use WBGam\Admin\SetupWizard;
use WBGam\Admin\AnalyticsDashboard;
use WBGam\Engine\Privacy;
use WBGam\Engine\CommunityChallengeEngine;
use WBGam\Engine\SiteFirstBadgeEngine;
use WBGam\Engine\CohortEngine;
use WBGam\Engine\StatusRetentionEngine;
use WBGam\Engine\CosmeticEngine;
use WBGam\Admin\BadgeAdminPage;
use WBGam\API\CredentialController;
use WBGam\API\RedemptionController;
use WBGam\Engine\CredentialExpiryEngine;

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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// DB schema upgrades run first.
		add_action( 'plugins_loaded', array( DbUpgrader::class, 'init' ), 1 );

		// Boot sequence: ManifestLoader (5) → Registry (6) → Engine (8) → display (10).
		add_action( 'plugins_loaded', array( ManifestLoader::class, 'scan' ), 5 );
		add_action( 'plugins_loaded', array( Registry::class, 'init' ), 6 );
		add_action( 'plugins_loaded', array( Engine::class, 'init' ), 8 );
		add_action( 'plugins_loaded', array( WPHooks::class, 'init' ), 8 );
		add_action( 'plugins_loaded', array( BPHooks::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( BadgeEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( ChallengeEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( LogPruner::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( LeaderboardNudge::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( AbilitiesRegistrar::class, 'register' ), 10 );
		add_action( 'plugins_loaded', array( RankAutomation::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( PersonalRecordEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( TenureBadgeEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( WeeklyEmailEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( NotificationBridge::class, 'init' ), 12 );
		add_action( 'plugins_loaded', array( Privacy::class, 'init' ), 15 );
		add_action( 'plugins_loaded', array( CommunityChallengeEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( SiteFirstBadgeEngine::class, 'init' ), 20 );
		add_action( 'plugins_loaded', array( CohortEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( StatusRetentionEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( CosmeticEngine::class, 'init' ), 10 );
		add_action( 'plugins_loaded', array( CredentialExpiryEngine::class, 'init' ), 10 );

		// BuddyPress integrations — must boot on bp_loaded, not plugins_loaded.
		add_action( 'bp_loaded', array( ProfileIntegration::class, 'init' ) );
		add_action( 'bp_loaded', array( DirectoryIntegration::class, 'init' ) );
		add_action( 'bp_loaded', array( BPActivity::class, 'init' ) );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( SettingsPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( SetupWizard::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( AnalyticsDashboard::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( BadgeAdminPage::class, 'init' ), 10 );
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
	}

	public function register_blocks(): void {
		$blocks = array( 'leaderboard', 'member-points', 'badge-showcase', 'level-progress', 'challenges', 'streak', 'top-members', 'kudos-feed', 'year-recap' );
		foreach ( $blocks as $block ) {
			$path = WB_GAM_PATH . 'blocks/' . $block;
			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type( $path );
			}
		}
	}

	public function enqueue_assets(): void {
		wp_enqueue_style( 'wb-gamification', WB_GAM_URL . 'assets/css/frontend.css', array(), WB_GAM_VERSION );
		wp_enqueue_script_module( 'wb-gamification-interactivity', WB_GAM_URL . 'assets/interactivity/index.js', array(), WB_GAM_VERSION );
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
		TenureBadgeEngine::activate();
		WeeklyEmailEngine::activate();
		CohortEngine::activate();
		StatusRetentionEngine::activate();
		CredentialExpiryEngine::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		LogPruner::deactivate();
		LeaderboardNudge::deactivate();
		TenureBadgeEngine::deactivate();
		WeeklyEmailEngine::deactivate();
		CohortEngine::deactivate();
		StatusRetentionEngine::deactivate();
		CredentialExpiryEngine::deactivate();
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
