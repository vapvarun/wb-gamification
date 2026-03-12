<?php
/**
 * Plugin Name: WB Gamification
 * Plugin URI:  https://wbcomdesigns.com/
 * Description: Complete gamification plugin for BuddyPress and WordPress. Part of the Reign Stack. Points, badges, levels, leaderboards, challenges, and streaks — zero config, works out of the box.
 * Version:     0.1.0
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

define( 'WB_GAM_VERSION', '0.1.0' );
define( 'WB_GAM_FILE', __FILE__ );
define( 'WB_GAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WB_GAM_URL', plugin_dir_url( __FILE__ ) );
define( 'WB_GAM_BASENAME', plugin_basename( __FILE__ ) );

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
		$this->includes();
		$this->init_hooks();
	}

	private function includes(): void {
		// Engine
		require_once WB_GAM_PATH . 'src/Engine/Registry.php';
		require_once WB_GAM_PATH . 'src/Engine/PointsEngine.php';
		require_once WB_GAM_PATH . 'src/Engine/BadgeEngine.php';
		require_once WB_GAM_PATH . 'src/Engine/LevelEngine.php';
		require_once WB_GAM_PATH . 'src/Engine/StreakEngine.php';
		require_once WB_GAM_PATH . 'src/Engine/ChallengeEngine.php';

		// API
		require_once WB_GAM_PATH . 'src/API/PointsController.php';
		require_once WB_GAM_PATH . 'src/API/BadgesController.php';
		require_once WB_GAM_PATH . 'src/API/LeaderboardController.php';
		require_once WB_GAM_PATH . 'src/API/ActionsController.php';

		// Abilities
		require_once WB_GAM_PATH . 'src/Abilities/AbilitiesRegistrar.php';

		// BuddyPress integration
		require_once WB_GAM_PATH . 'src/BuddyPress/HooksIntegration.php';

		// WordPress-native integration (standalone + always-on triggers)
		require_once WB_GAM_PATH . 'src/Integrations/WordPress/HooksIntegration.php';

		// Admin
		require_once WB_GAM_PATH . 'src/Admin/SettingsPage.php';

		// Public extension API
		require_once WB_GAM_PATH . 'src/Extensions/functions.php';
	}

	private function init_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Boot engines
		add_action( 'plugins_loaded', [ WB_Gam_Registry::class, 'init' ], 5 );
		add_action( 'plugins_loaded', [ WB_Gam_WordPress_Hooks::class, 'init' ], 8 );
		add_action( 'plugins_loaded', [ WB_Gam_BuddyPress_Hooks::class, 'init' ], 10 );
		add_action( 'plugins_loaded', [ WB_Gam_Abilities::class, 'register' ], 10 );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', [ WB_Gam_Settings_Page::class, 'init' ], 10 );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wb-gamification', false, WB_GAM_PATH . 'languages' );
	}

	public function register_routes(): void {
		( new WB_Gam_Points_Controller() )->register_routes();
		( new WB_Gam_Badges_Controller() )->register_routes();
		( new WB_Gam_Leaderboard_Controller() )->register_routes();
		( new WB_Gam_Actions_Controller() )->register_routes();
	}

	public function register_blocks(): void {
		$blocks = [ 'leaderboard', 'member-points', 'badge-showcase', 'level-progress', 'challenges', 'top-members' ];
		foreach ( $blocks as $block ) {
			$path = WB_GAM_PATH . 'blocks/' . $block;
			if ( file_exists( $path . '/block.json' ) ) {
				register_block_type( $path );
			}
		}
	}

	public function enqueue_assets(): void {
		wp_enqueue_style( 'wb-gamification', WB_GAM_URL . 'assets/css/frontend.css', [], WB_GAM_VERSION );
		wp_enqueue_script_module( 'wb-gamification-interactivity', WB_GAM_URL . 'assets/interactivity/index.js', [], WB_GAM_VERSION );
	}
}

/**
 * Activation / deactivation hooks.
 */
register_activation_hook( __FILE__, function () {
	require_once WB_GAM_PATH . 'src/Engine/Installer.php';
	WB_Gam_Installer::install();
} );

register_deactivation_hook( __FILE__, function () {
	// Flush rewrite rules, clear caches.
	flush_rewrite_rules();
} );

/**
 * Boot.
 */
add_action( 'plugins_loaded', function () {
	WB_Gamification::instance();
}, 0 );
