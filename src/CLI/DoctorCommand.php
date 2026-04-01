<?php
/**
 * WP-CLI: Doctor — dry-run readiness check for WB Gamification.
 *
 * Validates database, actions, badges, levels, integrations, settings,
 * REST API, and pro/free split. Reports pass/warn/fail for each check.
 *
 * Usage:
 *   wp wb-gamification doctor            # Run all checks
 *   wp wb-gamification doctor --verbose  # Show details for passing checks too
 *   wp wb-gamification doctor --fix      # Auto-fix what can be fixed
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\CLI;

use WBGam\Engine\Registry;
use WBGam\Engine\FeatureFlags;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Run diagnostic checks on the gamification system.
 *
 * @package WB_Gamification
 */
class DoctorCommand {

	/**
	 * Counters for the summary.
	 *
	 * @var array{pass: int, warn: int, fail: int}
	 */
	private array $counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ];

	/**
	 * Whether to show passing check details.
	 *
	 * @var bool
	 */
	private bool $verbose = false;

	/**
	 * Whether to auto-fix issues.
	 *
	 * @var bool
	 */
	private bool $fix = false;

	/**
	 * Run all diagnostic checks on the gamification system.
	 *
	 * ## OPTIONS
	 *
	 * [--verbose]
	 * : Show details for passing checks, not just warnings and failures.
	 *
	 * [--fix]
	 * : Auto-fix issues that can be repaired (re-seed levels, badges, etc.).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification doctor
	 *     wp wb-gamification doctor --verbose
	 *     wp wb-gamification doctor --fix
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->verbose = WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$this->fix     = WP_CLI\Utils\get_flag_value( $assoc_args, 'fix', false );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BWWB Gamification Doctor v' . WB_GAM_VERSION . '%n' ) );
		WP_CLI::line( str_repeat( '─', 60 ) );

		$this->check_database();
		$this->check_default_levels();
		$this->check_default_badges();
		$this->check_actions();
		$this->check_duplicate_hooks();
		$this->check_settings();
		$this->check_kudos_options();
		$this->check_feature_flags();
		$this->check_integrations();
		$this->check_rest_api();
		$this->check_cron();
		$this->check_pro_addon();
		$this->check_market_readiness();

		WP_CLI::line( '' );
		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line( sprintf(
			'Results: %s pass, %s warn, %s fail',
			WP_CLI::colorize( '%G' . $this->counts['pass'] . '%n' ),
			WP_CLI::colorize( '%Y' . $this->counts['warn'] . '%n' ),
			WP_CLI::colorize( '%R' . $this->counts['fail'] . '%n' )
		) );

		if ( $this->counts['fail'] > 0 ) {
			WP_CLI::error( 'Plugin has failures that must be fixed before release.', false );
		} elseif ( $this->counts['warn'] > 0 ) {
			WP_CLI::warning( 'Plugin has warnings — review before release.' );
		} else {
			WP_CLI::success( 'All checks passed. Plugin is release-ready.' );
		}
	}

	// ── Database ────────────────────────────────────────────────────────────────

	/**
	 * Verify all expected database tables exist with correct structure.
	 */
	private function check_database(): void {
		$this->section( 'Database Tables' );

		global $wpdb;

		$expected_tables = [
			'wb_gam_events',
			'wb_gam_points',
			'wb_gam_user_badges',
			'wb_gam_badge_defs',
			'wb_gam_levels',
			'wb_gam_streaks',
			'wb_gam_challenges',
			'wb_gam_challenge_log',
			'wb_gam_kudos',
			'wb_gam_member_prefs',
			'wb_gam_rules',
			'wb_gam_webhooks',
			'wb_gam_community_challenges',
			'wb_gam_community_challenge_contributions',
			'wb_gam_cohort_members',
			'wb_gam_redemption_items',
			'wb_gam_redemptions',
			'wb_gam_cosmetics',
			'wb_gam_user_cosmetics',
			'wb_gam_leaderboard_cache',
		];

		$missing = [];
		foreach ( $expected_tables as $table ) {
			$full = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			if ( ! $exists ) {
				$missing[] = $table;
			}
		}

		if ( empty( $missing ) ) {
			$this->pass( count( $expected_tables ) . ' tables present' );
		} else {
			$this->fail( count( $missing ) . ' tables missing: ' . implode( ', ', $missing ) );
			if ( $this->fix ) {
				WP_CLI::line( '  → Running Installer::install() to create missing tables...' );
				\WBGam\Engine\Installer::install();
				WP_CLI::success( '  Tables created.' );
			}
		}

		// Check db version.
		$db_version = get_option( 'wb_gam_db_version', '0.0.0' );
		if ( version_compare( $db_version, WB_GAM_VERSION, '>=' ) ) {
			$this->pass( 'DB version: ' . $db_version );
		} else {
			$this->warn( 'DB version ' . $db_version . ' behind plugin version ' . WB_GAM_VERSION );
		}
	}

	// ── Default Levels ──────────────────────────────────────────────────────────

	/**
	 * Verify default levels are seeded.
	 */
	private function check_default_levels(): void {
		$this->section( 'Default Levels' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_levels" );

		if ( $count >= 5 ) {
			$this->pass( $count . ' levels defined' );
		} elseif ( $count > 0 ) {
			$this->warn( 'Only ' . $count . ' levels — default is 5 (Newcomer→Champion)' );
		} else {
			$this->fail( 'No levels defined — members cannot progress' );
			if ( $this->fix ) {
				WP_CLI::line( '  → Re-seeding default levels...' );
				\WBGam\Engine\Installer::install();
				WP_CLI::success( '  Default levels seeded.' );
			}
		}

		// Check that level 0 (starting level) exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_zero = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_levels WHERE min_points = 0" );
		if ( (int) $has_zero > 0 ) {
			$this->pass( 'Starting level (0 points) exists' );
		} else {
			$this->fail( 'No starting level with min_points=0 — new members have no level' );
		}
	}

	// ── Default Badges ──────────────────────────────────────────────────────────

	/**
	 * Verify default badges are seeded and have valid conditions.
	 */
	private function check_default_badges(): void {
		$this->section( 'Default Badges' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_badge_defs" );

		if ( $count >= 20 ) {
			$this->pass( $count . ' badges defined' );
		} elseif ( $count > 0 ) {
			$this->warn( 'Only ' . $count . ' badges — default library has 30' );
		} else {
			$this->fail( 'No badges defined — members cannot earn badges' );
		}

		// Check badges with auto-award conditions have valid action_ids.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$rules = $wpdb->get_results(
			"SELECT target_id, rule_config FROM {$wpdb->prefix}wb_gam_rules WHERE rule_type = 'badge_condition' AND is_active = 1",
			ARRAY_A
		) ?: [];

		$actions    = Registry::get_actions();
		$orphaned   = [];
		$auto_count = 0;

		foreach ( $rules as $rule ) {
			$config = json_decode( $rule['rule_config'], true );
			if ( ! $config ) {
				continue;
			}

			if ( 'action_count' === ( $config['condition_type'] ?? '' ) ) {
				++$auto_count;
				$action_id = $config['action_id'] ?? '';
				if ( $action_id && ! isset( $actions[ $action_id ] ) ) {
					$orphaned[] = $rule['target_id'] . ' → ' . $action_id;
				}
			} elseif ( 'point_milestone' === ( $config['condition_type'] ?? '' ) ) {
				++$auto_count;
			}
		}

		if ( $auto_count > 0 ) {
			$this->pass( $auto_count . ' badges with auto-award conditions' );
		} else {
			$this->warn( 'No auto-award badge conditions — all badges are manual-only' );
		}

		if ( ! empty( $orphaned ) ) {
			$this->warn( 'Badge conditions reference unregistered actions: ' . implode( ', ', $orphaned ) );
		}
	}

	// ── Actions ─────────────────────────────────────────────────────────────────

	/**
	 * Verify registered actions are valid and have sensible defaults.
	 */
	private function check_actions(): void {
		$this->section( 'Registered Actions' );

		$actions = Registry::get_actions();
		$count   = count( $actions );

		if ( $count > 0 ) {
			$this->pass( $count . ' actions registered' );
		} else {
			$this->fail( 'No actions registered — gamification cannot award points' );
			return;
		}

		// Check for actions with 0 default points.
		$zero_pts = [];
		foreach ( $actions as $id => $action ) {
			$pts = (int) get_option( 'wb_gam_points_' . $id, $action['default_points'] ?? 0 );
			if ( 0 === $pts ) {
				$zero_pts[] = $id;
			}
		}

		if ( ! empty( $zero_pts ) ) {
			$this->warn( count( $zero_pts ) . ' actions award 0 points: ' . implode( ', ', array_slice( $zero_pts, 0, 5 ) ) );
		}

		// Check for disabled actions.
		$disabled = [];
		foreach ( $actions as $id => $action ) {
			if ( ! (bool) get_option( 'wb_gam_enabled_' . $id, true ) ) {
				$disabled[] = $id;
			}
		}

		if ( ! empty( $disabled ) ) {
			$this->warn( count( $disabled ) . ' actions disabled: ' . implode( ', ', array_slice( $disabled, 0, 5 ) ) );
		} else {
			$this->pass( 'All actions enabled' );
		}

		// Category breakdown.
		$cats = [];
		foreach ( $actions as $action ) {
			$cat          = $action['category'] ?? 'uncategorized';
			$cats[ $cat ] = ( $cats[ $cat ] ?? 0 ) + 1;
		}
		$this->pass( 'Categories: ' . implode( ', ', array_map(
			fn( $cat, $n ) => "$cat($n)",
			array_keys( $cats ),
			array_values( $cats )
		) ) );
	}

	// ── Duplicate Hooks ─────────────────────────────────────────────────────────

	/**
	 * Check for multiple actions listening on the same WordPress hook
	 * that could cause double-awarding to the same user.
	 */
	private function check_duplicate_hooks(): void {
		$this->section( 'Duplicate Hook Check' );

		$actions  = Registry::get_actions();
		$hook_map = []; // hook => [ action_id, ... ]

		foreach ( $actions as $id => $action ) {
			$hook = $action['hook'] ?? '';
			if ( $hook ) {
				$hook_map[ $hook ][] = $id;
			}
		}

		$multi = array_filter( $hook_map, fn( $ids ) => count( $ids ) > 1 );

		if ( empty( $multi ) ) {
			$this->pass( 'No hooks have multiple action registrations' );
			return;
		}

		foreach ( $multi as $hook => $ids ) {
			// Known intentional duplicates: first-time bonuses and give/receive patterns
			// (different users awarded on same hook — giver vs receiver).
			$known_ok = [
				'woocommerce_order_status_completed' => 'first-time bonus',
				'mepr-event-signup-completed'        => 'first-time bonus',
				'give_complete_purchase'              => 'first-time bonus',
				'publish_post'                       => 'standalone/BP split or first-post bonus',
				'comment_post'                       => 'author/commenter split or product review',
				'mvs_comment_created'                => 'give/receive pattern (different users)',
				'mvs_user_followed'                  => 'give/receive pattern (different users)',
				'mvs_favorite_toggled'               => 'give/receive pattern (different users)',
				'mvs_challenge_finalized'            => 'placement awards (1st/2nd/3rd)',
			];

			if ( isset( $known_ok[ $hook ] ) ) {
				$this->pass( $hook . ' → ' . implode( ', ', $ids ) . ' (' . $known_ok[ $hook ] . ')' );
			} else {
				$this->warn( $hook . ' → ' . implode( ', ', $ids ) . ' — verify no double-award' );
			}
		}
	}

	// ── Settings ────────────────────────────────────────────────────────────────

	/**
	 * Verify key settings have values (not just defaults).
	 */
	private function check_settings(): void {
		$this->section( 'Settings' );

		$retention = (int) get_option( 'wb_gam_log_retention_months', 6 );
		$this->pass( 'Log retention: ' . $retention . ' months' );

		$wizard = get_option( 'wb_gam_wizard_complete' );
		if ( $wizard ) {
			$this->pass( 'Setup wizard completed' );
		} else {
			$this->warn( 'Setup wizard not completed — admin will see wizard on first visit' );
		}
	}

	// ── Kudos ───────────────────────────────────────────────────────────────────

	/**
	 * Verify kudos options use correct names (not the old mismatched ones).
	 */
	private function check_kudos_options(): void {
		$this->section( 'Kudos Configuration' );

		$daily   = (int) get_option( 'wb_gam_kudos_daily_limit', 5 );
		$recv    = (int) get_option( 'wb_gam_kudos_receiver_points', 5 );
		$giver   = (int) get_option( 'wb_gam_kudos_giver_points', 2 );

		$this->pass( "Daily limit: $daily, Receiver pts: $recv, Giver pts: $giver" );

		// Check for orphaned old option names.
		$old_names = [ 'wb_gam_kudos_cooldown', 'wb_gam_kudos_max_per_day', 'wb_gam_kudos_points' ];
		$orphans   = [];
		foreach ( $old_names as $old ) {
			if ( false !== get_option( $old ) ) {
				$orphans[] = $old;
			}
		}

		if ( ! empty( $orphans ) ) {
			$this->warn( 'Orphaned old kudos options found: ' . implode( ', ', $orphans ) );
			if ( $this->fix ) {
				foreach ( $orphans as $old ) {
					delete_option( $old );
				}
				WP_CLI::success( '  Cleaned up orphaned options.' );
			}
		}
	}

	// ── Feature Flags ───────────────────────────────────────────────────────────

	/**
	 * Check feature flag state vs pro addon status.
	 */
	private function check_feature_flags(): void {
		$this->section( 'Feature Flags' );

		$flags    = FeatureFlags::get_all();
		$pro      = FeatureFlags::is_pro_active();
		$enabled  = array_filter( $flags );
		$disabled = array_diff_key( $flags, $enabled );

		if ( $pro ) {
			$this->pass( 'Pro addon active' );
			if ( ! empty( $disabled ) ) {
				$this->warn( count( $disabled ) . ' pro features disabled: ' . implode( ', ', array_keys( $disabled ) ) );
			} else {
				$this->pass( 'All ' . count( $enabled ) . ' pro features enabled' );
			}
		} else {
			$this->pass( 'Free mode (pro addon not active)' );
			if ( ! empty( $enabled ) ) {
				$this->warn( count( $enabled ) . ' pro flags set to true but pro addon not active (no effect): ' . implode( ', ', array_keys( $enabled ) ) );
			} else {
				$this->pass( 'All pro feature flags correctly set to false' );
			}
		}
	}

	// ── Integrations ────────────────────────────────────────────────────────────

	/**
	 * Check which integrations are detected and loaded.
	 */
	private function check_integrations(): void {
		$this->section( 'Integrations' );

		$integrations = [
			'BuddyPress'           => function_exists( 'buddypress' ),
			'bbPress'              => function_exists( 'bbpress' ),
			'WooCommerce'          => class_exists( 'WooCommerce' ),
			'LearnDash'            => defined( 'LEARNDASH_VERSION' ),
			'LifterLMS'            => defined( 'LLMS_PLUGIN_FILE' ),
			'MemberPress'          => defined( 'MEPR_VERSION' ),
			'GiveWP'               => class_exists( 'Give' ),
			'The Events Calendar'  => class_exists( 'Tribe__Events__Main' ),
			'WPMediaVerse'         => defined( 'MVS_VERSION' ),
		];

		$active   = [];
		$inactive = [];

		foreach ( $integrations as $name => $detected ) {
			if ( $detected ) {
				$active[] = $name;
			} else {
				$inactive[] = $name;
			}
		}

		if ( ! empty( $active ) ) {
			$this->pass( 'Active: ' . implode( ', ', $active ) );
		}

		if ( ! empty( $inactive ) ) {
			$this->pass( 'Not installed: ' . implode( ', ', $inactive ) );
		}

		// Check if WPMediaVerse has a gamification manifest.
		if ( defined( 'MVS_VERSION' ) ) {
			$mvs_manifest = WP_PLUGIN_DIR . '/wpmediaverse/wb-gamification.php';
			if ( file_exists( $mvs_manifest ) ) {
				$this->pass( 'WPMediaVerse manifest found' );
			} else {
				$this->warn( 'WPMediaVerse active but no wb-gamification.php manifest — media actions not registered' );
			}
		}

		// Verify manifest files load without error.
		$manifest_dir = WB_GAM_PATH . 'integrations/';
		$manifests    = glob( $manifest_dir . '*.php' ) ?: [];
		$this->pass( count( $manifests ) . ' first-party manifests: ' . implode( ', ', array_map( fn( $f ) => basename( $f, '.php' ), $manifests ) ) );

		$contrib = glob( $manifest_dir . 'contrib/*.php' ) ?: [];
		if ( ! empty( $contrib ) ) {
			$this->pass( count( $contrib ) . ' contrib manifests: ' . implode( ', ', array_map( fn( $f ) => basename( $f, '.php' ), $contrib ) ) );
		}
	}

	// ── REST API ────────────────────────────────────────────────────────────────

	/**
	 * Verify REST API routes are registered.
	 */
	private function check_rest_api(): void {
		$this->section( 'REST API' );

		$server = rest_get_server();
		$routes = $server->get_routes( 'wb-gamification/v1' );

		if ( empty( $routes ) ) {
			$this->fail( 'No REST routes registered under wb-gamification/v1' );
			return;
		}

		$count = count( $routes );
		$this->pass( $count . ' REST routes registered' );

		// Check key endpoints exist.
		$required = [
			'/wb-gamification/v1/members',
			'/wb-gamification/v1/points',
			'/wb-gamification/v1/badges',
			'/wb-gamification/v1/leaderboard',
			'/wb-gamification/v1/actions',
			'/wb-gamification/v1/challenges',
			'/wb-gamification/v1/kudos',
		];

		$missing = [];
		foreach ( $required as $route ) {
			$found = false;
			foreach ( array_keys( $routes ) as $r ) {
				if ( str_starts_with( $r, $route ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $route;
			}
		}

		if ( empty( $missing ) ) {
			$this->pass( 'All core endpoints present' );
		} else {
			$this->fail( 'Missing endpoints: ' . implode( ', ', $missing ) );
		}
	}

	// ── Cron ────────────────────────────────────────────────────────────────────

	/**
	 * Verify WP-Cron events are scheduled.
	 */
	private function check_cron(): void {
		$this->section( 'Cron Jobs' );

		$expected = [
			'wb_gam_prune_logs'         => 'Log pruner',
			'wb_gam_leaderboard_snapshot' => 'Leaderboard snapshot',
		];

		foreach ( $expected as $hook => $label ) {
			$next = wp_next_scheduled( $hook );
			if ( $next ) {
				$when = human_time_diff( time(), $next );
				$this->pass( $label . ' scheduled (next: ' . $when . ')' );
			} else {
				$this->warn( $label . ' (' . $hook . ') not scheduled' );
			}
		}
	}

	// ── Pro Addon ───────────────────────────────────────────────────────────────

	/**
	 * Check pro addon status and compatibility.
	 */
	private function check_pro_addon(): void {
		$this->section( 'Pro Addon' );

		if ( ! FeatureFlags::is_pro_active() ) {
			$this->pass( 'Running in free mode' );

			// Verify pro plugin directory exists.
			$pro_path = WP_PLUGIN_DIR . '/wb-gamification-pro/wb-gamification-pro.php';
			if ( file_exists( $pro_path ) ) {
				$this->warn( 'Pro plugin files exist but not activated' );
			} else {
				$this->pass( 'Pro plugin not installed (expected for free-only testing)' );
			}
			return;
		}

		$this->pass( 'Pro addon active — version: ' . ( defined( 'WB_GAM_PRO_VERSION' ) ? WB_GAM_PRO_VERSION : 'unknown' ) );

		// Check free/pro version compatibility.
		if ( defined( 'WB_GAM_PRO_VERSION' ) ) {
			$free_major = explode( '.', WB_GAM_VERSION )[0] ?? '0';
			$pro_major  = explode( '.', WB_GAM_PRO_VERSION )[0] ?? '0';
			if ( $free_major === $pro_major ) {
				$this->pass( 'Free/Pro major versions match' );
			} else {
				$this->fail( 'Version mismatch — Free ' . WB_GAM_VERSION . ' vs Pro ' . WB_GAM_PRO_VERSION );
			}
		}
	}

	// ── Market Readiness ────────────────────────────────────────────────────────

	/**
	 * Check for market-readiness gaps compared to competitors.
	 */
	private function check_market_readiness(): void {
		$this->section( 'Market Readiness' );

		// Translation readiness.
		$pot_file = WB_GAM_PATH . 'languages/wb-gamification.pot';
		if ( file_exists( $pot_file ) ) {
			$this->pass( 'Translation template (.pot) exists' );
		} else {
			$this->warn( 'No .pot file — run `grunt makepot` before release' );
		}

		// Uninstall hook.
		$uninstall = WB_GAM_PATH . 'uninstall.php';
		if ( file_exists( $uninstall ) ) {
			$this->pass( 'uninstall.php exists' );
		} else {
			$this->warn( 'No uninstall.php — plugin data will persist after deletion' );
		}

		// Readme.
		$readme = WB_GAM_PATH . 'readme.txt';
		if ( file_exists( $readme ) ) {
			$this->pass( 'readme.txt exists' );
		} else {
			$this->warn( 'No readme.txt — required for WordPress.org submission' );
		}

		// Min assets.
		$css_min = WB_GAM_PATH . 'assets/css/admin-premium.min.css';
		$js_min  = WB_GAM_PATH . 'assets/js/settings-nav.min.js';
		if ( file_exists( $css_min ) && file_exists( $js_min ) ) {
			$this->pass( 'Minified assets present' );
		} else {
			$this->warn( 'Missing minified assets — run `grunt build` before release' );
		}

		// Check essential frontend features.
		$blocks_dir = WB_GAM_PATH . 'blocks/';
		if ( is_dir( $blocks_dir ) ) {
			$blocks = glob( $blocks_dir . '*/block.json' ) ?: [];
			$this->pass( count( $blocks ) . ' Gutenberg blocks registered' );
		} else {
			$this->warn( 'No blocks/ directory — no Gutenberg blocks available' );
		}

		// Shortcodes check.
		global $shortcode_tags;
		$gam_shortcodes = array_filter(
			array_keys( $shortcode_tags ),
			fn( $tag ) => str_starts_with( $tag, 'wb_gam_' )
		);
		if ( ! empty( $gam_shortcodes ) ) {
			$this->pass( count( $gam_shortcodes ) . ' shortcodes: ' . implode( ', ', $gam_shortcodes ) );
		} else {
			$this->warn( 'No shortcodes registered — classic editor users have no widgets' );
		}

		// Check for competing gamification plugins.
		$competitors = [
			'mycred/mycred.php'           => 'myCred',
			'gamipress/gamipress.php'     => 'GamiPress',
			'badgeos/badgeos.php'         => 'BadgeOS',
		];
		$conflicts = [];
		foreach ( $competitors as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$conflicts[] = $name;
			}
		}
		if ( ! empty( $conflicts ) ) {
			$this->warn( 'Competing gamification plugins active: ' . implode( ', ', $conflicts ) . ' — may cause UX confusion' );
		} else {
			$this->pass( 'No competing gamification plugins detected' );
		}
	}

	// ── Output helpers ──────────────────────────────────────────────────────────

	/**
	 * Print a section header.
	 *
	 * @param string $title Section name.
	 */
	private function section( string $title ): void {
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%B► ' . $title . '%n' ) );
	}

	/**
	 * Record and display a passing check.
	 *
	 * @param string $msg Check description.
	 */
	private function pass( string $msg ): void {
		++$this->counts['pass'];
		if ( $this->verbose ) {
			WP_CLI::line( WP_CLI::colorize( '  %G✓%n ' . $msg ) );
		}
	}

	/**
	 * Record and display a warning.
	 *
	 * @param string $msg Check description.
	 */
	private function warn( string $msg ): void {
		++$this->counts['warn'];
		WP_CLI::line( WP_CLI::colorize( '  %Y⚠%n ' . $msg ) );
	}

	/**
	 * Record and display a failure.
	 *
	 * @param string $msg Check description.
	 */
	private function fail( string $msg ): void {
		++$this->counts['fail'];
		WP_CLI::line( WP_CLI::colorize( '  %R✗%n ' . $msg ) );
	}
}
