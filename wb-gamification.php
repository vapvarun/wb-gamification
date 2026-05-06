<?php
/**
 * Plugin Name: WB Gamification
 * Plugin URI:  https://wbcomdesigns.com/
 * Description: Complete gamification plugin for BuddyPress and WordPress. Part of the Reign Stack. Points, badges, levels, leaderboards, challenges, and streaks — zero config, works out of the box.
 * Version:     1.0.0
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

define( 'WB_GAM_VERSION', '1.0.0' );
define( 'WB_GAM_FILE', __FILE__ );
define( 'WB_GAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WB_GAM_URL', plugin_dir_url( __FILE__ ) );
define( 'WB_GAM_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader — PSR-4 for all WBGam\ classes + functions.php.
require_once WB_GAM_PATH . 'vendor/autoload.php';

// Action Scheduler — must be loaded after autoloader, before plugins_loaded.
require_once WB_GAM_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// Note: WB Gamification is 100% free. No license SDK needed.

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
use WBGam\API\PointTypesController;
use WBGam\API\PointTypeConversionsController;
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
use WBGam\Integrations\WooCommerce\RefundHandler as WCRefundHandler;
use WBGam\Admin\SettingsPage;
use WBGam\Admin\SetupWizard;
use WBGam\Admin\AnalyticsDashboard;
use WBGam\Engine\CohortEngine;
use WBGam\Engine\StatusRetentionEngine;
use WBGam\Admin\BadgeAdminPage;
use WBGam\Admin\ChallengeManagerPage;
use WBGam\Admin\ManualAwardPage;
use WBGam\Admin\ApiKeysPage;
use WBGam\Admin\RedemptionStorePage;
use WBGam\Admin\CommunityChallengesPage;
use WBGam\Admin\CohortSettingsPage;
use WBGam\Admin\WebhooksAdminPage;
use WBGam\Admin\PointTypesPage;
use WBGam\Admin\PointTypeConversionsPage;
use WBGam\Admin\SubmissionsPage;
use WBGam\API\CredentialController;
use WBGam\API\RedemptionController;
use WBGam\API\LevelsController;
use WBGam\API\CapabilitiesController;
use WBGam\API\AbilitiesRegistration;
use WBGam\API\OpenApiController;
use WBGam\API\ApiKeyAuth;
use WBGam\API\ApiKeysController;
use WBGam\API\CohortSettingsController;
use WBGam\API\CommunityChallengesController;
use WBGam\API\EmailSettingsController;
use WBGam\API\SubmissionsController;
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
		( new \WBGam\Blocks\Registrar( WB_GAM_PATH . 'build' ) )->init();
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
		add_action( 'plugins_loaded', array( WCRefundHandler::class, 'init' ), 10 );

		// Leaderboard snapshot cron + object cache layer.
		add_action( 'plugins_loaded', array( LeaderboardEngine::class, 'init' ), 10 );

		// WP Abilities API registration + fallback REST endpoint for AI agent discovery.
		add_action( 'plugins_loaded', array( AbilitiesRegistration::class, 'init' ), 10 );

		// All remaining engines boot via FeatureFlags (core = always, pro = flag-gated).
		add_action( 'plugins_loaded', array( FeatureFlags::class, 'boot_engines' ), 10 );

		// Async award pipeline — collects events at priority 50 (after sync listeners
		// BadgeEngine@10, ChallengeEngine@15; before NotificationBridge@99).
		add_action( 'wb_gam_points_awarded', array( AsyncEvaluator::class, 'enqueue' ), 50, 3 );

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
			add_action( 'plugins_loaded', array( RedemptionStorePage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( CommunityChallengesPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( CohortSettingsPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( WebhooksAdminPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( PointTypesPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( PointTypeConversionsPage::class, 'init' ), 10 );
			add_action( 'plugins_loaded', array( SubmissionsPage::class, 'init' ), 10 );
		}
	}

	public function handle_unsubscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- CSRF protection via hash_equals( wp_hash(...), $tok ) below.
		if ( empty( $_GET['wb_gam_unsub'] ) ) {
			return;
		}

		$uid = absint( wp_unslash( $_GET['uid'] ?? 0 ) );
		$tok = sanitize_text_field( wp_unslash( $_GET['tok'] ?? '' ) );
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
		( new PointTypesController() )->register_routes();
		( new PointTypeConversionsController() )->register_routes();
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
		( new OpenApiController() )->register_routes();
		( new ApiKeysController() )->register_routes();
		( new CohortSettingsController() )->register_routes();
		( new CommunityChallengesController() )->register_routes();
		( new EmailSettingsController() )->register_routes();
		( new SubmissionsController() )->register_routes();
	}

	public function register_blocks(): void {
		// All 15 blocks now live under `build/Blocks/<slug>/` and are registered by
		// `WBGam\Blocks\Registrar` on `init@20`. The legacy `blocks/<slug>/` directory
		// is deprecated and must NOT be re-registered here — older block.json files
		// in that path lack the standard editorScript declaration and would shadow
		// the migrated build/ output (silent editor "no support" failures).
	}

	public function enqueue_assets(): void {
		// Wbcom Block Quality Standard — design tokens that every standardised
		// block consumes. Registered standalone so blocks can declare it as a
		// dependency without importing from JS (CSS @import would land tokens
		// in the editor chunk via wp-scripts' style split).
		wp_register_style(
			'wb-gam-tokens',
			WB_GAM_URL . 'src/shared/design-tokens.css',
			array(),
			WB_GAM_VERSION
		);

		// Phase G.2 — shared block-card stylesheet. Single canonical
		// `.wb-gam-card` family that every block consumes so the 15
		// frontends share the hub's premium card UX (white surface,
		// 10px radius, soft shadow, accent-tinted icon chip, hover
		// lift). Blocks add this handle as a render-time dependency
		// alongside their per-block style.
		wp_register_style(
			'wb-gam-block-card',
			WB_GAM_URL . 'src/shared/block-card.css',
			array( 'wb-gam-tokens' ),
			WB_GAM_VERSION
		);

		// Register but don't enqueue — blocks and shortcodes will enqueue as needed.
		wp_register_style(
			'wb-gamification',
			WB_GAM_URL . 'assets/css/frontend.css',
			array( 'wb-gam-tokens' ),
			WB_GAM_VERSION
		);
		// `wb-gamification-interactivity` removed in Phase F. The legacy
		// IA store at `assets/interactivity/index.js` was orphaned once
		// every block migrated to `viewScriptModule` (Phases C → D.5);
		// no remaining code path enqueued the handle. The hub block
		// continues to use its own dedicated module
		// (`assets/interactivity/hub.js` / `wb-gamification-hub`).

		// Lucide icon font — bundled locally for hub page.
		wp_register_style(
			'lucide-icons',
			WB_GAM_URL . 'assets/fonts/lucide.css',
			array(),
			'0.469.0'
		);

		// Hub block assets.
		wp_register_style(
			'wb-gamification-hub',
			WB_GAM_URL . 'assets/css/hub.css',
			array( 'lucide-icons' ),
			WB_GAM_VERSION
		);
		wp_register_script_module(
			'wb-gamification-hub',
			WB_GAM_URL . 'assets/interactivity/hub.js',
			array( '@wordpress/interactivity' ),
			WB_GAM_VERSION
		);

		// Currency-conversion modal — wires the per-tile Convert button on the
		// hub block. Plain script (not a module) so it can use wp.apiFetch + the
		// existing toast handle without dragging the IA bundle into every page.
		wp_register_script(
			'wb-gamification-hub-convert',
			WB_GAM_URL . 'assets/js/hub-convert.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			WB_GAM_VERSION,
			true
		);
		wp_set_script_translations( 'wb-gamification-hub-convert', 'wb-gamification' );

		// Notifications IA store — drives the level-up + streak-milestone
		// overlays and the toast stack rendered by NotificationBridge.
		// Without this module the overlay markup mounts but never binds,
		// leaving the streak overlay permanently visible with an inert
		// dismiss button (customer locked out of the page).
		wp_register_script_module(
			'wb-gamification-notifications',
			WB_GAM_URL . 'assets/interactivity/notifications.js',
			array( '@wordpress/interactivity' ),
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
		// Lucide icon font — register inline if not already (the frontend
		// register_styles only fires on wp_enqueue_scripts which doesn't
		// run for admin pages). Mandatory dependency for every admin
		// surface so banners, empty-states, sidebar nav, accordion
		// chevrons all render the Lucide set.
		if ( ! wp_style_is( 'lucide-icons', 'registered' ) ) {
			wp_register_style(
				'lucide-icons',
				WB_GAM_URL . 'assets/fonts/lucide.css',
				array(),
				'0.469.0'
			);
		}
		wp_enqueue_style( 'lucide-icons' );
		wp_enqueue_style(
			'wb-gam-admin',
			WB_GAM_URL . 'assets/css/admin.css',
			array( 'lucide-icons' ),
			WB_GAM_VERSION
		);
		wp_enqueue_style(
			'wb-gamification-admin-premium',
			WB_GAM_URL . 'assets/css/admin-premium.css',
			array( 'lucide-icons' ),
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
		WBGam\Engine\Capabilities::register();
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
		// Idempotent — only writes when CAPS_VERSION moves forward.
		WBGam\Engine\Capabilities::sync();
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
			WP_CLI::add_command( 'wb-gamification points', WBGam\CLI\PointsCommand::class );
			WP_CLI::add_command( 'wb-gamification member', WBGam\CLI\MemberCommand::class );
			WP_CLI::add_command( 'wb-gamification actions', WBGam\CLI\ActionsCommand::class );
			WP_CLI::add_command( 'wb-gamification logs', WBGam\CLI\LogsCommand::class );
			WP_CLI::add_command( 'wb-gamification export', WBGam\CLI\ExportCommand::class );
			WP_CLI::add_command( 'wb-gamification doctor', WBGam\CLI\DoctorCommand::class );
			WP_CLI::add_command( 'wb-gamification replay', WBGam\CLI\ReplayCommand::class );
			WP_CLI::add_command( 'wb-gamification qa', WBGam\CLI\QASeedCommand::class );
			WP_CLI::add_command( 'wb-gamification scale', WBGam\CLI\ScaleCommand::class );
			WP_CLI::add_command( 'wb-gamification email-test', array( WBGam\CLI\EmailCommand::class, 'test' ) );
		}
	);
}
