<?php
/**
 * Plugin Name: WB Gamification
 * Plugin URI:  https://wbcomdesigns.com/
 * Description: Complete gamification plugin for BuddyPress and WordPress. Part of the Reign Stack. Points, badges, levels, leaderboards, challenges, and streaks — zero config, works out of the box.
 * Version:     1.6.4
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wb-gamification
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP:      8.0
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

// Double-load guard. On Windows, include_once can be defeated by
// path-spelling differences (drive-letter case, slash direction), causing
// the same file to be parsed twice in one request and fataling on the
// class declaration below. If our version constant already exists, a
// first parse completed — bail out of the second one.
if ( defined( 'WB_GAM_VERSION' ) ) {
	return;
}
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

define( 'WB_GAM_VERSION', '1.6.4' );
define( 'WB_GAM_FILE', __FILE__ );
define( 'WB_GAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WB_GAM_URL', plugin_dir_url( __FILE__ ) );
define( 'WB_GAM_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader — PSR-4 for all WBGam\ classes + functions.php.
//
// Runtime is fully self-contained: the plugin's own classes load through a
// hand-written PSR-4 autoloader (no Composer at runtime), and bundled
// third-party code ships committed under libs/ (Action Scheduler, EDD SL
// SDK). Composer's vendor/ is dev tooling only (PHPUnit/PHPStan/WPCS); it is
// gitignored and never travels in the release zip. Clients run nothing.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WBGam\\';
		$len    = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
		$file = WB_GAM_PATH . 'src/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Non-namespaced helper functions (formerly the Composer `files` autoload entry).
require_once WB_GAM_PATH . 'src/Extensions/functions.php';

// Action Scheduler — bundled under libs/, loaded before plugins_loaded.
require_once WB_GAM_PATH . 'libs/woocommerce/action-scheduler/action-scheduler.php';

// EDD Software Licensing SDK — free plugin auto-updates with preset key.
//
// WB Gamification is GPLv2 free, but it's distributed from
// store.wbcomdesigns.com (not the wp.org repo), so without an updater
// users would never see "1 update available" in their WordPress admin.
// We bundle the EDD SL SDK + a preset license key (no charge, just a
// registration token) so update checks flow through the EDD store,
// the same way Jetonomy free does.
//
// item_id 1662147 = WB Gamification on EDD.
const WB_GAM_LICENSE_PRESET_KEY = 'wbcomfree6e2a9c1d7b4f3c8a0e5d9b2f1a7c6e11';
const WB_GAM_EDD_ITEM_ID        = 1662147;
const WB_GAM_EDD_STORE_URL      = 'https://wbcomdesigns.com';

add_action(
	'edd_sl_sdk_registry',
	static function ( $registry ): void {
		$registry->register(
			array(
				'id'      => 'wb-gamification',
				'url'     => WB_GAM_EDD_STORE_URL,
				'item_id' => WB_GAM_EDD_ITEM_ID,
				'version' => WB_GAM_VERSION,
				'file'    => WB_GAM_FILE,
				'license' => WB_GAM_LICENSE_PRESET_KEY,
			)
		);
	}
);

// Load the bundled EDD SL SDK only when BOTH its entrypoint AND its own
// autoloader are present. The SDK self-requires __DIR__/vendor/autoload.php;
// if a build ever strips that nested vendor/ (an unanchored exclude did once),
// requiring the entrypoint would hard-fatal on install. Guarding both paths
// degrades gracefully (license/update checks disabled) instead.
if ( file_exists( WB_GAM_PATH . 'libs/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' )
	&& file_exists( WB_GAM_PATH . 'libs/easy-digital-downloads/edd-sl-sdk/vendor/autoload.php' ) ) {
	require_once WB_GAM_PATH . 'libs/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}

// Preactivate the bundled preset license so EDD update checks
// authenticate without the site owner ever entering a key. The real work
// lives in WBGam\Engine\LicenseActivator::activate() (the main file
// declares a class, so it can't also hold a named function — WPCS
// Universal.Files.SeparateFunctionsFromOO). It runs on plugin activation
// (see register_activation_hook below) and on admin_init as an idempotent
// fallback for CLI/restore activation paths and transient-error retries.
add_action( 'admin_init', array( \WBGam\Engine\LicenseActivator::class, 'activate' ) );

use WBGam\Engine\Registry;
use WBGam\Engine\Engine;
use WBGam\Engine\HeartbeatChannel;
use WBGam\Engine\BootOrder;
use WBGam\Engine\ManifestLoader;
use WBGam\Engine\FeatureFlags;
use WBGam\Engine\AsyncEvaluator;
use WBGam\Engine\MemberData;
use WBGam\Engine\LogPruner;
use WBGam\Engine\ActionSchedulerCleaner;
use WBGam\Engine\LeaderboardNudge;
use WBGam\Engine\Installer;
use WBGam\Engine\BadgeSharePage;
use WBGam\Engine\DbUpgrader;
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
use WBGam\BuddyPress\ProfileIntegration;
use WBGam\BuddyPress\DirectoryIntegration;
use WBGam\BuddyPress\ActivityIntegration as BPActivity;
use WBGam\Integrations\WooCommerce\RefundHandler as WCRefundHandler;
use WBGam\Integrations\WooCommerce\AccountIntegration as WCAccountIntegration;
use WBGam\Integrations\LearnDash\ProfileIntegration as LearnDashProfile;
use WBGam\Integrations\Learnomy\ProfileIntegration as LearnomyProfile;
use WBGam\Integrations\Jetonomy\JetonomyIntegration as JetonomyHooks;
use WBGam\Integrations\Jetonomy\DisplayDefer as JetonomyDisplayDefer;
use WBGam\Admin\SettingsPage;
use WBGam\Admin\SetupWizard;
use WBGam\Admin\AnalyticsDashboard;
use WBGam\Engine\CohortEngine;
use WBGam\Engine\StatusRetentionEngine;
use WBGam\Admin\BadgeAdminPage;
use WBGam\Admin\ChallengeManagerPage;
use WBGam\Admin\ManualAwardPage;
use WBGam\Admin\MembersPage;
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
use WBGam\API\SSEController;
use WBGam\API\IntelligenceController;
use WBGam\API\ApiKeyAuth;
use WBGam\API\ApiKeysController;
use WBGam\API\CohortSettingsController;
use WBGam\API\CommunityChallengesController;
use WBGam\API\EmailSettingsController;
use WBGam\API\ToolsController;
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
		// load_plugin_textdomain() is no longer needed — WordPress 4.6+ auto-
		// loads translations for plugins hosted on WordPress.org based on the
		// Text Domain header. For Wbcom-distributed plugins the .pot ships in
		// languages/ and i18n falls back to the file-system loader.
		// (Leaving load_textdomain registered triggers PluginCheck's
		// DiscouragedFunctions sniff with no benefit.)
		add_action( 'init', array( $this, 'handle_unsubscribe' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		( new \WBGam\Blocks\Registrar( WB_GAM_PATH . 'build' ) )->init();
		add_action( 'init', array( ShortcodeHandler::class, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// The dialog utility is needed in the ADMIN too (the deactivation-feedback modal on
		// plugins.php). Priority 1 so the handle exists before any admin screen declares it.
		add_action( 'admin_enqueue_scripts', array( $this, 'register_dialog_script' ), 1 );
		// The editor canvas is an iframe: styles enqueued via
		// enqueue_block_editor_assets land in the OUTER admin document, not the
		// canvas. Block previews live inside the iframe, so the shared design
		// tokens + card surface + preview-card CSS must be injected through
		// block_editor_settings_all, which WP copies into the canvas iframe.
		add_filter( 'block_editor_settings_all', array( $this, 'inject_editor_canvas_styles' ) );

		// DB schema upgrades run first.
		// Boot sequence uses the SLOT_* constants from BootOrder so the
		// priority numbers stay explicit and the dependency graph is
		// declared (validated at plugins_loaded@99). See
		// plan/STABILITY-AND-ARCHITECTURE-V2.md § Finding A.
		BootOrder::bind_validator();

		BootOrder::register( 'db_upgrader', BootOrder::SLOT_SCHEMA );
		add_action( 'plugins_loaded', array( DbUpgrader::class, 'init' ), BootOrder::SLOT_SCHEMA );

		// Boot sequence: ManifestLoader → Registry → AsyncEvaluator + Engine → FeatureFlags.
		BootOrder::register( 'manifest_loader', BootOrder::SLOT_REGISTRY - 1 );
		add_action( 'plugins_loaded', array( ManifestLoader::class, 'scan' ), BootOrder::SLOT_REGISTRY - 1 );

		BootOrder::register( 'registry', BootOrder::SLOT_REGISTRY, array( 'manifest_loader' ) );
		add_action( 'plugins_loaded', array( Registry::class, 'init' ), BootOrder::SLOT_REGISTRY );

		BootOrder::register( 'api_key_auth', BootOrder::SLOT_CORE );
		add_action( 'plugins_loaded', array( ApiKeyAuth::class, 'init' ), BootOrder::SLOT_CORE );

		BootOrder::register( 'async_evaluator', BootOrder::SLOT_CORE, array( 'registry' ) );
		add_action( 'plugins_loaded', array( AsyncEvaluator::class, 'init' ), BootOrder::SLOT_CORE );

		// Deleting a member has to take their gamification data with it. Nothing listened for that,
		// so every points row, streak, badge and queued notification of every deleted member stayed
		// in the database for ever — and quietly corrupted every aggregate built on those tables.
		BootOrder::register( 'member_data', BootOrder::SLOT_CORE );
		add_action( 'plugins_loaded', array( MemberData::class, 'init' ), BootOrder::SLOT_CORE );

		BootOrder::register( 'engine', BootOrder::SLOT_CORE, array( 'registry', 'db_upgrader' ) );
		add_action( 'plugins_loaded', array( Engine::class, 'init' ), BootOrder::SLOT_CORE );

		// Member-facing accent color override (Settings > Appearance). Only
		// registers a wp_enqueue_scripts hook, so it has no boot-order deps.
		\WBGam\Engine\Appearance::init();

		BootOrder::register( 'wc_refund_handler', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( WCRefundHandler::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		BootOrder::register( 'wc_account', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( WCAccountIntegration::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		BootOrder::register( 'learndash_profile', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( LearnDashProfile::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		BootOrder::register( 'learnomy_profile', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( LearnomyProfile::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		BootOrder::register( 'jetonomy_hooks', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( JetonomyHooks::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		BootOrder::register( 'jetonomy_display_defer', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( JetonomyDisplayDefer::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		BootOrder::register( 'module_toggles', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( \WBGam\Engine\ModuleToggles::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		// Leaderboard snapshot cron + object cache layer.
		BootOrder::register( 'leaderboard_engine', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( LeaderboardEngine::class, 'init' ), BootOrder::SLOT_INTEGRATIONS );

		// v2.5 + AI v1 — read-side projection. Daily cron computes
		// engagement / churn / diversity signals from the event log.
		BootOrder::register( 'intelligence_projector', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( \WBGam\Engine\IntelligenceProjector::class, 'boot' ), BootOrder::SLOT_INTEGRATIONS );

		// GraphQL extension — registers types + root queries when
		// WPGraphQL is loaded. No-op otherwise.
		BootOrder::register( 'graphql', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( \WBGam\Integrations\GraphQL::class, 'boot' ), BootOrder::SLOT_INTEGRATIONS );

		// ActivityPub federation — listens for badge/level/challenge
		// events + publishes to the WP ActivityPub plugin's outbox.
		// Site-level + per-user opt-in (both off by default).
		BootOrder::register( 'activitypub', BootOrder::SLOT_INTEGRATIONS, array( 'engine' ) );
		add_action( 'plugins_loaded', array( \WBGam\Integrations\ActivityPub::class, 'boot' ), BootOrder::SLOT_INTEGRATIONS );

		// Realtime channel — Heartbeat-backed broker that the frontend
		// toast.js, leaderboard view modules, and user-status-bar block
		// all subscribe to. Single source of "live" data so each block
		// doesn't run its own setInterval poll loop.
		add_action( 'plugins_loaded', array( HeartbeatChannel::class, 'init' ), 10 );

		// WP Abilities API registration + fallback REST endpoint for AI agent discovery.
		add_action( 'plugins_loaded', array( AbilitiesRegistration::class, 'init' ), 10 );

		// All remaining engines boot via FeatureFlags (core = always, pro = flag-gated).
		add_action( 'plugins_loaded', array( FeatureFlags::class, 'boot_engines' ), 10 );

		// Async award pipeline — collects events at priority 50 (after sync listeners
		// BadgeEngine@10, ChallengeEngine@15; before NotificationBridge@99).
		add_action( 'wb_gam_points_awarded', array( AsyncEvaluator::class, 'enqueue' ), 50, 3 );

		// Clear hub-page option when the hub page is trashed/deleted so a stale
		// pointer doesn't suppress recreation on the next reactivation.
		add_action( 'wp_trash_post', array( Installer::class, 'on_hub_page_removed' ) );
		add_action( 'before_delete_post', array( Installer::class, 'on_hub_page_removed' ) );

		// BuddyPress integrations — must boot on bp_loaded, not plugins_loaded.
		add_action( 'bp_loaded', array( ProfileIntegration::class, 'init' ) );
		add_action( 'bp_loaded', array( DirectoryIntegration::class, 'init' ) );
		add_action( 'bp_loaded', array( BPActivity::class, 'init' ) );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			// Lucide font is required on every admin screen so the WB Gam menu
			// icon (painted via CSS pseudo-element) renders even when the
			// admin is viewing a non-WB-Gam page. Without this the menu icon
			// shows as a blank square on Posts, Pages, Tools, etc.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_global_admin_lucide' ) );
			// 3rd-party-notice suppression is body-class-scoped CSS in
			// assets/css/admin.css — no PHP needed, no inline <style>, and
			// the rule is active on every plugin admin page that loads
			// admin.css (which `enqueue_admin_assets` always does).

			// Boot admin modules directly. register_hooks() itself runs at
			// plugins_loaded@0, so calling ::init() here is equivalent to
			// (and strictly safer than) registering a nested
			// add_action('plugins_loaded', …, 10). The nested pattern was
			// the root cause of the chronic Setup Wizard reopen — fragile
			// timing when WP_Filter::do_action is already mid-iteration.
			SettingsPage::init();
			\WBGam\Admin\IntegrationsTab::init();
			SetupWizard::init();
			AnalyticsDashboard::init();
			BadgeAdminPage::init();
			ChallengeManagerPage::init();
			ManualAwardPage::init();
			MembersPage::init();
			\WBGam\Admin\StreaksPage::init();
			\WBGam\Admin\KudosModerationPage::init();
			ApiKeysPage::init();
			RedemptionStorePage::init();
			CommunityChallengesPage::init();
			CohortSettingsPage::init();
			WebhooksAdminPage::init();
			PointTypesPage::init();
			PointTypeConversionsPage::init();
			SubmissionsPage::init();
			\WBGam\Admin\DeactivationFeedback::init();
			\WBGam\Admin\ImportPage::init();
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

	// load_textdomain() removed — superseded by WP 4.6+ auto-loader. See
	// register_hooks() comment above for details.

	/**
	 * Register all REST API controllers.
	 *
	 * @return void
	 */
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
		( new \WBGam\API\ImportController() )->register_routes();
		( new WebhooksController() )->register_routes();
		( new RulesController() )->register_routes();
		( new RecapController() )->register_routes();
		( new CredentialController() )->register_routes();
		( new RedemptionController() )->register_routes();
		( new LevelsController() )->register_routes();
		( new CapabilitiesController() )->register_routes();
		( new OpenApiController() )->register_routes();
		( new SSEController() )->register_routes();
		( new IntelligenceController() )->register_routes();
		( new ApiKeysController() )->register_routes();
		( new CohortSettingsController() )->register_routes();
		( new CommunityChallengesController() )->register_routes();
		( new EmailSettingsController() )->register_routes();
		( new ToolsController() )->register_routes();
		( new SubmissionsController() )->register_routes();
	}

	public function register_blocks(): void {
		// All 15 blocks now live under `build/Blocks/<slug>/` and are registered by
		// `WBGam\Blocks\Registrar` on `init@20`. The legacy `blocks/<slug>/` directory
		// is deprecated and must NOT be re-registered here — older block.json files
		// in that path lack the standard editorScript declaration and would shadow
		// the migrated build/ output (silent editor "no support" failures).
	}

	/**
	 * Register the one dialog utility.
	 *
	 * Four overlay surfaces used to answer "does ESC close it, is focus trapped, does focus come
	 * back?" four different ways; the redemption confirm claimed to be a dialog and trapped nothing.
	 * Native <dialog> does the hard parts; this adds focus return.
	 *
	 * Registered on BOTH the front-end and the admin. It used to be registered only on
	 * wp_enqueue_scripts, which meant the deactivation-feedback modal on plugins.php could not have
	 * consumed it even if it had declared the dependency -- the handle did not exist in admin, so
	 * wp_enqueue_script would have silently dropped it. That is why that surface stayed a fifth
	 * hand-rolled dialog: the shared utility was not merely unused there, it was unreachable.
	 *
	 * Registration is idempotent; wp_register_script no-ops on a handle that already exists.
	 */
	public function register_dialog_script(): void {
		wp_register_script(
			'wb-gam-dialog',
			WB_GAM_URL . 'assets/js/dialog.js',
			array(),
			WB_GAM_VERSION,
			true
		);
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
			// @phpstan-ignore-next-line -- WP normalizes string module deps.
			array( '@wordpress/interactivity' ),
			WB_GAM_VERSION
		);

		// Currency-conversion modal — wires the per-tile Convert button on the
		// hub block. Plain script (not a module) so it can use wp.apiFetch + the
		// existing toast handle without dragging the IA bundle into every page.
		wp_register_script(
			'wb-gamification-hub-convert',
			WB_GAM_URL . 'assets/js/hub-convert.js',
			array( 'wp-api-fetch', 'wp-i18n', 'wb-gam-dialog' ),
			WB_GAM_VERSION,
			true
		);
		wp_set_script_translations( 'wb-gamification-hub-convert', 'wb-gamification', WB_GAM_PATH . 'languages' );

		// Notifications IA store — drives the level-up + streak-milestone
		// overlays and the toast stack rendered by NotificationBridge.
		// Without this module the overlay markup mounts but never binds,
		// leaving the streak overlay permanently visible with an inert
		// dismiss button (customer locked out of the page).
		wp_register_script_module(
			'wb-gamification-notifications',
			WB_GAM_URL . 'assets/interactivity/notifications.js',
			// @phpstan-ignore-next-line -- WP normalizes string module deps.
			array( '@wordpress/interactivity' ),
			WB_GAM_VERSION
		);

		// Realtime broker — WP Heartbeat client. Single subscription bus
		// the toast renderer, leaderboard live-update view module, and
		// the user-status-bar block all hook into. Always enqueued so
		// guests on a public leaderboard page still get tick updates.
		wp_enqueue_script( 'heartbeat' );
		wp_enqueue_script(
			'wb-gamification-realtime',
			WB_GAM_URL . 'assets/js/heartbeat.js',
			array( 'jquery', 'heartbeat' ),
			WB_GAM_VERSION,
			true
		);

		// SSE transport (scaffold; feature-flagged off by default). Loads
		// alongside heartbeat.js; probes the transport option and no-ops
		// when set to 'heartbeat'. Real streaming ships in stages 2-3 —
		// see plan/REAL-TIME-TRANSPORT.md.
		if ( is_user_logged_in() ) {
			wp_enqueue_script(
				'wb-gamification-sse',
				WB_GAM_URL . 'assets/js/sse.js',
				array( 'wb-gamification-realtime' ),
				WB_GAM_VERSION,
				true
			);
			wp_localize_script(
				'wb-gamification-sse',
				'wbGamSSEConfig',
				array(
					'streamUrl'   => esc_url_raw( rest_url( 'wb-gamification/v1/events/stream' ) ),
					// effective_transport() downgrades sse/auto → heartbeat unless
					// the host opted into SSE via the wb_gam_sse_allowed filter, so
					// the browser never opens a worker-pinning EventSource by default.
					'transport'   => SSEController::effective_transport(),
					'lastEventId' => 0,
				)
			);
		}

		// Shared top-strip measurement.
		//
		// Anything this plugin pins to the top of the viewport has to know what is ALREADY up there —
		// admin bar, theme header (however it is positioned), sticky nav, cookie bar. We have shipped
		// that bug twice: toasts behind the header, and a status bar hardcoded to `top: 48px` that
		// landed on BuddyX's nav. One measurement, one file, so the next fix cannot land in only one
		// of two copies. Registered (not enqueued) — consumers declare it as a dependency.
		$this->register_dialog_script();

		// The one mount utility. Our blocks bound themselves once, at DOMContentLoaded — which is
		// correct for a page the BROWSER loaded and wrong for a page a ROUTER loaded. Host themes
		// navigate client-side, and markup swapped in that way carries no listeners. Verified in a
		// browser: after a swap, the give-kudos form's submit handler is gone, so the browser performs
		// a NATIVE submit and navigates the member away mid-kudos. onMount() runs a block's setup when
		// its element appears, whenever that is, once per element.
		wp_register_script(
			'wb-gam-mount',
			WB_GAM_URL . 'assets/js/mount.js',
			array(),
			WB_GAM_VERSION,
			true
		);

		// The one REST client. Give-kudos, profile-visibility, toast, submit-achievement and
		// redemption-store each hand-rolled fetch + X-WP-Nonce and, with it, the same bug: a nonce
		// baked into the page at render time expires (~24h) and nothing ever refreshed it. wbGam.rest()
		// is the shared client with a retry-once-on-expired-nonce path. See assets/js/rest.js.
		wp_register_script(
			'wb-gam-rest',
			WB_GAM_URL . 'assets/js/rest.js',
			array(),
			WB_GAM_VERSION,
			true
		);

		// Core's OWN nonce endpoint, and deliberately not a route of ours.
		//
		// A refresh route under /wp-json/ cannot do this job: core decides who you are from the nonce
		// BEFORE any route runs, so an endpoint that mints nonces would have to be shown a valid nonce
		// first -- it can only help you when you did not need help. admin-ajax authenticates from the
		// session cookie, which is the credential that is still good when the nonce has died. This is
		// the same endpoint wp.apiFetch uses, for the same reason.
		wp_localize_script(
			'wb-gam-rest',
			'wbGamRest',
			array( 'nonceUrl' => admin_url( 'admin-ajax.php?action=rest-nonce' ) )
		);

		wp_register_script(
			'wb-gamification-top-offset',
			WB_GAM_URL . 'assets/js/top-offset.js',
			array(),
			WB_GAM_VERSION,
			true
		);

		// Toast notification renderer for logged-in users. The renderer
		// consumes wb-gamification-realtime instead of running its own
		// poll loop; the wbGamToast localisation is kept as a fallback
		// for third-party scripts that hit /members/me/toasts directly.
		if ( is_user_logged_in() ) {
			wp_enqueue_script(
				'wb-gamification-toast',
				WB_GAM_URL . 'assets/js/toast.js',
				array( 'wb-gamification-realtime', 'wb-gamification-top-offset', 'wb-gam-rest' ),
				WB_GAM_VERSION,
				true
			);
			wp_localize_script(
				'wb-gamification-toast',
				'wbGamToast',
				array(
					'restUrl'  => rest_url( 'wb-gamification/v1/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'position' => \WBGam\Engine\NotificationBridge::get_toast_position(),
					// Translated strings the renderer builds client-side (aria
					// labels + the last-resort points-unit fallback). Seeded here
					// because toast.js has no server-rendered markup to read.
					'i18n'     => array(
						'region'  => __( 'Notifications', 'wb-gamification' ),
						'dismiss' => __( 'Dismiss', 'wb-gamification' ),
						'points'  => __( 'points', 'wb-gamification' ),
					),
				)
			);
		}
	}

	/**
	 * Inject shared block styles into the editor canvas iframe.
	 *
	 * The block editor renders block previews inside an `<iframe>`. Styles
	 * registered through `enqueue_block_editor_assets` land in the OUTER admin
	 * document, NOT the iframe, so block previews there render unstyled. Block
	 * `style`/`editorStyle` from block.json ARE copied into the canvas, but
	 * shared cross-block CSS (design tokens, the canonical card surface, the
	 * BlockPreviewCard used by Interactivity-API blocks) is not declared per
	 * block. `block_editor_settings_all` is the supported seam: every entry in
	 * `$settings['styles']` is injected into the canvas iframe.
	 *
	 * Without this, every wb-gamification block preview renders with undefined
	 * `--wb-gam-*` variables and no card chrome — and IA blocks (which use a
	 * static BlockPreviewCard instead of ServerSideRender) show as raw text.
	 *
	 * @param array<string, mixed> $settings Block editor settings.
	 * @return array<string, mixed> Settings with the shared canvas styles added.
	 */
	public function inject_editor_canvas_styles( array $settings ): array {
		$files = array(
			WB_GAM_PATH . 'src/shared/design-tokens.css',
			WB_GAM_PATH . 'src/shared/block-card.css',
			WB_GAM_PATH . 'src/shared/block-preview-card.css',
			// give-kudos renders its form via ServerSideRender and styles it
			// from assets/css/ (shared with the shortcode) rather than a
			// block.json `style`, so its CSS isn't auto-copied into the canvas.
			WB_GAM_PATH . 'assets/css/give-kudos.css',
		);

		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = array();
		}

		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $css || '' === $css ) {
				continue;
			}
			$settings['styles'][] = array( 'css' => $css );
		}

		return $settings;
	}

	/**
	 * Enqueue shared admin CSS on all WB Gamification admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	/**
	 * Enqueue the Lucide icon font on every admin page.
	 *
	 * The WB Gamification top-level menu icon is painted via a CSS pseudo-
	 * element that consumes a Lucide glyph (see `admin.css` rule against
	 * `#adminmenu .toplevel_page_wb-gamification .wp-menu-image:before`).
	 * Without the font loaded globally, the icon renders as a blank square
	 * whenever the admin is viewing a page outside the plugin's own screens.
	 *
	 * The selector only matches the WB Gamification menu node, so the font
	 * isn't visually applied elsewhere — only the font file is loaded.
	 *
	 * @return void
	 */
	public function enqueue_global_admin_lucide(): void {
		if ( ! wp_style_is( 'lucide-icons', 'registered' ) ) {
			wp_register_style(
				'lucide-icons',
				WB_GAM_URL . 'assets/fonts/lucide.css',
				array(),
				'0.469.0'
			);
		}
		wp_enqueue_style( 'lucide-icons' );

		// Menu-icon paint rule — depends on Lucide font; tiny dedicated
		// stylesheet so the full admin.css (gated to plugin pages) stays
		// out of every wp-admin page load.
		wp_enqueue_style(
			'wb-gam-admin-menu-icon',
			WB_GAM_URL . 'assets/css/admin-menu-icon.css',
			array( 'lucide-icons' ),
			WB_GAM_VERSION
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( false === strpos( $hook, 'wb-gamification' ) && false === strpos( $hook, 'wb-gam' ) ) {
			return;
		}

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

		// Admin stylesheet — refactored 2026-05-27 from the 4069-line
		// admin-core + admin-pages pair into a four-layer cascade so the
		// foundation (tokens / components / utilities / suppression) is
		// shared across every admin page and per-page styling lives in
		// dedicated files under assets/css/admin/pages/ that each admin
		// page class enqueues for itself. Cascade order matters:
		//
		// 1. wb-gam-admin-tokens       — design tokens (:root only).
		// 2. wb-gam-admin-components   — reusable UI primitives that
		// consume the tokens.
		// 3. wb-gam-admin-utilities    — atomic helpers, skeletons,
		// responsive grid, print rules.
		// 4. wb-gam-admin-suppression  — body-scoped third-party
		// notice suppression.
		//
		// Per-page CSS is enqueued by each admin page's own enqueue_assets
		// method against `wb-gam-admin-utilities` as its dependency.
		wp_enqueue_style(
			'wb-gam-admin-tokens',
			WB_GAM_URL . 'assets/css/admin/tokens.css',
			array( 'lucide-icons' ),
			WB_GAM_VERSION
		);
		wp_enqueue_style(
			'wb-gam-admin-components',
			WB_GAM_URL . 'assets/css/admin/components.css',
			array( 'wb-gam-admin-tokens' ),
			WB_GAM_VERSION
		);
		wp_enqueue_style(
			'wb-gam-admin-utilities',
			WB_GAM_URL . 'assets/css/admin/utilities.css',
			array( 'wb-gam-admin-components' ),
			WB_GAM_VERSION
		);
		wp_enqueue_style(
			'wb-gam-admin-suppression',
			WB_GAM_URL . 'assets/css/admin/third-party-suppression.css',
			array( 'wb-gam-admin-utilities' ),
			WB_GAM_VERSION
		);

		// Back-compat aliases — any third-party code or per-page admin
		// module that did `wp_enqueue_style( 'wb-gam-admin' )`,
		// `'wb-gam-admin-core'`, or `'wb-gam-admin-pages'` keeps working.
		// The aliases have no `src` and depend on the new four-layer
		// stack, so requesting any of them resolves the full bundle.
		$alias_deps = array(
			'wb-gam-admin-tokens',
			'wb-gam-admin-components',
			'wb-gam-admin-utilities',
			'wb-gam-admin-suppression',
		);
		wp_register_style( 'wb-gam-admin', false, $alias_deps, WB_GAM_VERSION );
		wp_register_style( 'wb-gam-admin-core', false, $alias_deps, WB_GAM_VERSION );
		wp_register_style( 'wb-gam-admin-pages', false, $alias_deps, WB_GAM_VERSION );
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
		// Preactivate the bundled license the instant the plugin is switched
		// on, so updates flow with zero manual steps from the site owner.
		\WBGam\Engine\LicenseActivator::activate();
		// Wizard redirect — only on first install, never on re-activation post-setup.
		// The option persists until SetupWizard::maybe_redirect() consumes it on
		// the first subsequent admin page load, regardless of activation-to-admin
		// gap. Pre-1.0.0 used a 30s transient that silently dropped the redirect
		// any time the gap exceeded 30s (typical with WP-CLI activation flows).
		if ( ! get_option( 'wb_gam_wizard_complete' ) ) {
			update_option( 'wb_gam_pending_setup_redirect', '1' );
		}
		LogPruner::activate();
		\WBGam\Engine\PointsExpiry::activate();
		ActionSchedulerCleaner::activate();
		LeaderboardNudge::activate();
		LeaderboardEngine::activate();
		WeeklyEmailEngine::activate();
		CohortEngine::activate();
		StatusRetentionEngine::activate();
		CredentialExpiryEngine::activate();
		// ProfilePage's /u/{username} rewrite rule is registered on `init`,
		// which does NOT fire during CLI activation (wp plugin activate). Without
		// this line its rule is absent when the flush below runs, so every
		// member's public profile 404s until an admin manually flushes permalinks.
		// Register it here so the BadgeSharePage::activate() flush persists it.
		\WBGam\Engine\ProfilePage::register_rewrite();
		BadgeSharePage::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		LogPruner::deactivate();
		\WBGam\Engine\PointsExpiry::deactivate();
		ActionSchedulerCleaner::deactivate();
		LeaderboardNudge::deactivate();
		LeaderboardEngine::deactivate();
		WeeklyEmailEngine::deactivate();
		CohortEngine::deactivate();
		StatusRetentionEngine::deactivate();
		CredentialExpiryEngine::deactivate();
		BadgeSharePage::deactivate();
		\WBGam\Engine\BadgeEngine::deactivate();

		// v2.x engines (SideEffectDispatcher, IntelligenceProjector,
		// NotificationBridge) schedule wp-cron hooks but predate the per-engine
		// deactivate() convention above, so their events survived deactivation
		// (smoke: D.action-scheduler-orphan — 3 wb_gam_ hooks left registered).
		// Clear them here so deactivation leaves no orphaned scheduled events.
		wp_clear_scheduled_hook( \WBGam\Engine\SideEffectDispatcher::RECONCILE_CRON );
		wp_clear_scheduled_hook( \WBGam\Engine\IntelligenceProjector::COMPUTE_CRON );
		wp_clear_scheduled_hook( \WBGam\Engine\NotificationBridge::PRUNE_CRON );

		flush_rewrite_rules();
	}
);

/**
 * Boot.
 */
add_action(
	'plugins_loaded',
	function () {
		// Self-heal: re-runs the activation payload (tables + caps + wizard
		// redirect) when the canonical hook never fired or its effects were
		// lost. Covers CLI activation, site restores, container clones, and
		// dev resets. No-op on healthy sites (single fast SHOW TABLES probe).
		// See src/Engine/Installer::maybe_install() for the full rationale.
		Installer::maybe_install();

		WB_Gamification::instance();
		// Idempotent — only writes when CAPS_VERSION moves forward.
		WBGam\Engine\Capabilities::sync();
	},
	0
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
			WP_CLI::add_command( 'wb-gamification share', WBGam\CLI\ShareCommand::class );
			WP_CLI::add_command( 'wb-gamification openapi', WBGam\CLI\OpenApiCommand::class );
			WP_CLI::add_command( 'wb-gamification import', WBGam\CLI\ImportCommand::class );
			WP_CLI::add_command( 'wb-gamification email-test', array( WBGam\CLI\EmailCommand::class, 'test' ) );
		}
	);
}
