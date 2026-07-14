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

use WBGam\Engine\BadgeRule;
use WBGam\Engine\Clock;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;
use WBGam\Engine\FeatureFlags;
use WP_CLI;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

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
	private array $counts = [
		'pass' => 0,
		'warn' => 0,
		'fail' => 0,
	];

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
	 * [--recompute-leaderboard]
	 * : Force a fresh leaderboard snapshot AND invalidate every cached
	 *   leaderboard / rank entry. Useful when the cached snapshot has
	 *   drifted from the live ledger after a bulk import / migration / outage.
	 *
	 * [--drain-action-scheduler]
	 * : Loop ActionSchedulerCleaner::cleanup() until the AS tables are
	 *   back under the runaway threshold (or 20 ticks elapse, whichever
	 *   comes first). Use after PERF-001-class incidents when the daily
	 *   cleaner can't catch up to a recursion-bloated queue.
	 *
	 * [--max-ticks=<n>]
	 * : Cap the drain loop at N cleanup ticks. Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification doctor
	 *     wp wb-gamification doctor --verbose
	 *     wp wb-gamification doctor --fix
	 *     wp wb-gamification doctor --recompute-leaderboard
	 *     wp wb-gamification doctor --drain-action-scheduler
	 *     wp wb-gamification doctor --drain-action-scheduler --max-ticks=40
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->verbose = WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$this->fix     = WP_CLI\Utils\get_flag_value( $assoc_args, 'fix', false );

		// Short-circuit one-shot mode: --recompute-leaderboard rebuilds the
		// snapshot + invalidates every cache key, then exits without running
		// the full diagnostic suite. Designed for the "leaderboard wrong"
		// support ticket — admin runs this, snapshot rebuilds in seconds.
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'recompute-leaderboard', false ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BWB Gamification — leaderboard recompute%n' ) );
			\WBGam\Engine\LeaderboardEngine::write_snapshot();
			\WBGam\Engine\LeaderboardEngine::invalidate_cache();
			WP_CLI::success( 'Leaderboard snapshot rebuilt and cache invalidated.' );
			return;
		}

		// Short-circuit one-shot mode: drain Action Scheduler tables when a
		// runaway hook has bloated them past what the daily cleaner can
		// recover from. Loops the cleaner (which auto-detects runaway and
		// switches to a 1-hour retention horizon) until the row count
		// drops below the threshold or --max-ticks is reached.
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'drain-action-scheduler', false ) ) {
			$this->drain_action_scheduler( (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'max-ticks', 20 ) );
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BWWB Gamification Doctor v' . WB_GAM_VERSION . '%n' ) );
		WP_CLI::line( str_repeat( '─', 60 ) );

		$this->check_database();
		$this->check_default_levels();
		$this->check_default_badges();
		$this->check_badge_expiry_integrity();
		$this->check_actions();
		$this->check_core_wp_actions();
		$this->check_duplicate_hooks();
		$this->check_settings();
		$this->check_kudos_options();
		$this->check_feature_flags();
		$this->check_integrations();
		$this->check_rest_api();
		$this->check_cron();
		$this->check_leaderboard_queries();
		$this->check_totals_match_ledger();
		$this->check_market_readiness();

		WP_CLI::line( '' );
		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line(
			sprintf(
				'Results: %s pass, %s warn, %s fail',
				WP_CLI::colorize( '%G' . $this->counts['pass'] . '%n' ),
				WP_CLI::colorize( '%Y' . $this->counts['warn'] . '%n' ),
				WP_CLI::colorize( '%R' . $this->counts['fail'] . '%n' )
			)
		);

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

			// Read the rule through BadgeRule, not by hand. A rule is a GROUP of conditions now, and a
			// doctor that still reached for `condition_type` would match nothing at all -- then cheerfully
			// report "no auto-award badge conditions" on a site with thirty-five working ones.
			if ( ! BadgeRule::is_auto_award( $config ) ) {
				continue;
			}

			++$auto_count;

			// An orphaned action is one a rule points at that no integration registers -- the badge can
			// never be earned. Every action_count condition in the group is checked, not just the first.
			foreach ( BadgeRule::conditions( $config ) as $condition ) {
				if ( 'action_count' !== ( $condition['type'] ?? '' ) ) {
					continue;
				}

				$action_id = $condition['action_id'] ?? '';
				if ( $action_id && ! isset( $actions[ $action_id ] ) ) {
					$orphaned[] = $rule['target_id'] . ' → ' . $action_id;
				}
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

	// ── Earned-Badge Expiry Integrity ───────────────────────────────────────────

	/**
	 * Detect zero-date expires_at rows on earned badges.
	 *
	 * 1.5.0–1.5.3 stored `0000-00-00 00:00:00` instead of NULL for
	 * never-expiring badges (Basecamp 9985131435). Such rows fail the
	 * `expires_at IS NULL OR expires_at > now` visibility filter, so the
	 * badges exist in wb_gam_user_badges but show as 0 on every surface —
	 * the exact "badges awarded but not displaying" support symptom.
	 */
	private function check_badge_expiry_integrity(): void {
		$this->section( 'Earned-Badge Expiry Integrity' );

		global $wpdb;
		// Zero-dates sort below any real DATETIME; comparing against
		// '1971-01-01' avoids a zero-date literal (rejected by NO_ZERO_DATE).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$broken = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE expires_at IS NOT NULL AND expires_at < '1971-01-01'"
		);

		if ( 0 === $broken ) {
			$this->pass( 'No zero-date expires_at rows on earned badges' );
			return;
		}

		$this->fail( $broken . ' earned-badge rows have a zero-date expires_at — badges exist but display as 0 everywhere (profiles, Members page, blocks)' );
		if ( $this->fix ) {
			WP_CLI::line( '  → Repairing expires_at (NULL, or earned_at + validity_days where the badge defines a window)...' );
			$repaired = \WBGam\Engine\BadgeEngine::repair_zero_date_expiry();
			WP_CLI::success( '  ' . $repaired . ' rows repaired; earned-badge caches flushed.' );
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
		$this->pass(
			'Categories: ' . implode(
				', ',
				array_map(
					fn( $cat, $n ) => "$cat($n)",
					array_keys( $cats ),
					array_values( $cats )
				)
			)
		);
	}

	// ── Core WordPress Triggers ───────────────────────────────────────────────────

	/**
	 * Verify the always-on core WordPress content triggers are registered.
	 *
	 * Publishing a blog post and commenting on a post are core WordPress
	 * events with no BuddyPress equivalent. If they get re-flagged
	 * `standalone_only` (so they vanish whenever BuddyPress is active), the
	 * default badges that count them — first_post, prolific_writer,
	 * content_creator, first_comment, engaged_reader — become permanently
	 * un-earnable on every BuddyPress site. This guard catches that
	 * regression on a doctor run instead of in production.
	 */
	private function check_core_wp_actions(): void {
		$this->section( 'Core WordPress Triggers' );

		$actions  = Registry::get_actions();
		$required = [
			'wp_publish_post'  => 'first_post / prolific_writer / content_creator',
			'wp_leave_comment' => 'first_comment / engaged_reader',
		];

		$missing = [];
		foreach ( $required as $action_id => $badges ) {
			if ( ! isset( $actions[ $action_id ] ) ) {
				$missing[] = $action_id . ' (badges: ' . $badges . ')';
			}
		}

		$bp_active = function_exists( 'buddypress' );

		if ( empty( $missing ) ) {
			$this->pass( 'Core WP content triggers registered (BuddyPress ' . ( $bp_active ? 'active' : 'inactive' ) . ')' );
		} else {
			$this->fail(
				'Core WP content triggers NOT registered: ' . implode( '; ', $missing )
				. ( $bp_active
					? ' — likely re-flagged standalone_only; those badges cannot be earned on this BuddyPress site.'
					: '.' )
			);
		}
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
				'give_complete_purchase'             => 'first-time bonus',
				'publish_post'                       => 'BP Member Blog publish (superseded by core wp_publish_post when both present)',
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

		$daily = (int) get_option( 'wb_gam_kudos_daily_limit', 5 );
		$recv  = (int) get_option( 'wb_gam_kudos_receiver_points', 5 );
		$giver = (int) get_option( 'wb_gam_kudos_giver_points', 2 );

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
	 * Check which optional feature flags are enabled.
	 */
	private function check_feature_flags(): void {
		$this->section( 'Feature Flags' );

		$flags    = FeatureFlags::get_all();
		$enabled  = array_filter( $flags );
		$disabled = array_diff_key( $flags, $enabled );

		$this->pass( count( $enabled ) . ' of ' . count( $flags ) . ' optional features enabled' );

		if ( ! empty( $disabled ) ) {
			$this->pass( 'Disabled: ' . implode( ', ', array_keys( $disabled ) ) );
		}
	}

	// ── Integrations ────────────────────────────────────────────────────────────

	/**
	 * Check which integrations are detected and loaded.
	 */
	private function check_integrations(): void {
		$this->section( 'Integrations' );

		$integrations = [
			'BuddyPress'          => function_exists( 'buddypress' ),
			'bbPress'             => function_exists( 'bbpress' ),
			'WooCommerce'         => class_exists( 'WooCommerce' ),
			'LearnDash'           => defined( 'LEARNDASH_VERSION' ),
			'LifterLMS'           => defined( 'LLMS_PLUGIN_FILE' ),
			'MemberPress'         => defined( 'MEPR_VERSION' ),
			'GiveWP'              => class_exists( 'Give' ),
			'The Events Calendar' => class_exists( 'Tribe__Events__Main' ),
			'WPMediaVerse'        => defined( 'MVS_VERSION' ),
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

		// Check if WPMediaVerse ships a gamification manifest (either edition).
		if ( defined( 'MVS_VERSION' ) ) {
			$mvs_manifests  = array(
				WP_PLUGIN_DIR . '/wpmediaverse/wb-gamification.php',
				WP_PLUGIN_DIR . '/wpmediaverse-pro/wb-gamification.php',
			);
			$found_manifest = false;
			foreach ( $mvs_manifests as $manifest ) {
				if ( file_exists( $manifest ) ) {
					$found_manifest = true;
					break;
				}
			}
			if ( $found_manifest ) {
				$this->pass( 'WPMediaVerse gamification manifest found' );
			} else {
				$this->warn( 'WPMediaVerse active but no gamification manifest — media actions not registered' );
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
			'wb_gam_prune_logs'           => 'Log pruner',
			'wb_gam_leaderboard_snapshot' => 'Leaderboard snapshot',
		];

		foreach ( $expected as $hook => $label ) {
			$next = wp_next_scheduled( $hook );
			// Recurring jobs such as the leaderboard snapshot are scheduled through
			// Action Scheduler, which wp_next_scheduled() cannot see; fall back to it
			// so the check does not falsely report a scheduled job as missing.
			if ( ! $next && function_exists( 'as_next_scheduled_action' ) ) {
				$as_next = as_next_scheduled_action( $hook );
				if ( is_int( $as_next ) && $as_next > 0 ) {
					$next = $as_next;
				} elseif ( true === $as_next ) {
					$next = time();
				}
			}
			if ( $next ) {
				$when = human_time_diff( time(), $next );
				$this->pass( $label . ' scheduled (next: ' . $when . ')' );
			} else {
				$this->warn( $label . ' (' . $hook . ') not scheduled' );
			}
		}
	}

	// ── Leaderboard queries ─────────────────────────────────────────────────────

	/**
	 * EXECUTE every leaderboard read path and fail on any database error.
	 *
	 * This check exists because a green unit suite shipped a blank leaderboard.
	 *
	 * The exclusion fragment was tested in isolation and passed. The query it was composed INTO was
	 * never executed by anything, so a string-rewrite that mangled `mp.user_id` into `muser_id`
	 * reached customers: MySQL rejected the query, `get_results()` returned null, the engine read
	 * that as "no rows", and every scoped leaderboard rendered EMPTY rather than erroring. A silent
	 * wrong answer, on every site.
	 *
	 * `$wpdb` swallows the error, and an empty leaderboard looks exactly like a quiet community. So
	 * the only way to catch this class is to run the real query against a real database and ask the
	 * database whether it was actually valid. That is what this does.
	 *
	 * WHAT IT ACTUALLY COVERS, precisely -- because overclaiming here is how the next one hides:
	 *
	 * SCOPED boards bypass the snapshot on every call, so they always execute the composed fallback
	 * query. They are what catch this class, and they are what caught the `muser_id` regression when
	 * this check was written against it.
	 *
	 * The unscoped ALL-TIME board is served from the snapshot whenever one is warm -- so on a site
	 * with a healthy cron it does NOT exercise the fallback, and it did not go red on the bug. It
	 * shares the same composer (`build_totals_query()`) as the scoped path, which is what makes the
	 * scoped coverage meaningful for it. On a fresh install or a cron-less host, where the fallback
	 * is the only path, this check exercises it directly.
	 */
	private function check_leaderboard_queries(): void {
		global $wpdb;

		$this->section( 'Leaderboard Queries (executed)' );

		// Bypass the object cache AND the snapshot, so the composed fallback query is the thing
		// that actually runs. Reading a warm snapshot would prove nothing -- that is precisely how
		// the blank-leaderboard regression stayed hidden.
		wp_cache_flush();

		// The scope MUST resolve to real members, or the scoped paths short-circuit to an empty board
		// (correctly -- a scope with no members has no board) and never execute the query at all.
		//
		// This is not hypothetical: when the short-circuit was added, it silently disarmed this very
		// check. The bug it was written to catch sailed straight through it, because on a site with
		// no BuddyPress bridge every scope resolves to nobody. A check that cannot fail is not a
		// check. So the scope is resolved here to members who actually exist.
		$scope_members = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->prefix}wb_gam_user_totals WHERE total > 0 ORDER BY total DESC LIMIT 3"
		);

		$resolve_scope = static function () use ( $scope_members ) {
			return array_map( 'absint', (array) $scope_members );
		};

		add_filter( 'wb_gam_leaderboard_scope_user_ids', $resolve_scope, 99 );

		$paths = array(
			'all-time (materialised totals)' => array( 'all', '', 0 ),
			'daily (ledger)'                 => array( 'day', '', 0 ),
			'weekly (ledger)'                => array( 'week', '', 0 ),
			'monthly (ledger)'               => array( 'month', '', 0 ),
			'scoped: bp_group'               => array( 'all', 'bp_group', 1 ),
			'scoped: cohort'                 => array( 'all', 'cohort', 1 ),
		);

		$failed = 0;

		// COUNT THE ROWS. The check that was supposed to catch every round of this bug could not fail.
		//
		// It called get_leaderboard(), THREW THE RESULT AWAY, and asserted only that $wpdb->last_error
		// was empty. So it passed on a totally blank leaderboard -- byte-identical output, 33 pass / 0
		// fail, with every board returning zero rows. Six rounds of "the board is short" went past a
		// health check whose only opinion was that MySQL had not raised an error.
		//
		// And `count > 0` would not have been enough either: it would still have passed 8-of-10,
		// 17-of-25 and 15-of-100. The only assertion that catches a SHORT board is the one that knows
		// how long the board should be -- so it is measured against an independent COUNT(*) oracle of
		// who is actually eligible, computed here rather than asked of the engine under test.
		$limit = 10;

		foreach ( $paths as $label => [ $period, $scope_type, $scope_id ] ) {
			// Clears last_error and last_query, so what we read back belongs to THIS call.
			$wpdb->flush();

			$rows = LeaderboardEngine::get_leaderboard( $period, $limit, $scope_type, $scope_id );

			// An EMPTY board is legitimate (a quiet site, an empty group). A DATABASE ERROR is not,
			// and it is the thing that renders as an empty board.
			if ( ! empty( $wpdb->last_error ) ) {
				$this->fail( 'Leaderboard query failed - ' . $label . ': ' . $wpdb->last_error );
				++$failed;
				continue;
			}

			// Only the global boards get the length assertion: a scoped board's eligible set depends on
			// whatever resolved the scope (a BuddyPress group, a filter), and recomputing that here
			// would just be asking the engine the same question twice and calling the answer an oracle.
			if ( '' !== (string) $scope_type ) {
				continue;
			}

			$eligible = self::count_eligible_members( $period );
			$expected = min( $limit, $eligible );
			$got      = count( (array) $rows );

			if ( $got !== $expected ) {
				// The message has to describe what actually happened. The first version said "SHORT" in
				// both directions, so when the board returned one row MORE than the oracle expected it
				// sent the operator hunting orphans that did not exist. A gate that misdescribes its own
				// finding is a gate people learn to distrust.
				$this->fail(
					sprintf(
						'Leaderboard %s - %s: %d rows for a board of %d, but %d members are eligible (expected %d). %s',
						$got < $expected ? 'SHORT' : 'OVER-FULL',
						$label,
						$got,
						$limit,
						$eligible,
						$expected,
						$got < $expected
							? 'Orphaned rows, an opted-out member, or an excluded role is eating slots.'
							: 'The board is ranking members the eligibility rules say it should not.'
					)
				);
				++$failed;
			}
		}

		remove_filter( 'wb_gam_leaderboard_scope_user_ids', $resolve_scope, 99 );

		if ( empty( $scope_members ) ) {
			// No members with points, so the scoped paths resolved to nobody and short-circuited.
			// Say so, rather than printing a pass that means nothing.
			$this->warn( 'Leaderboard scoped paths not executed - no members with points to scope to' );
			return;
		}

		if ( 0 === $failed ) {
			$this->pass( count( $paths ) . ' leaderboard query paths execute without a database error' );
		}
	}

	/**
	 * The materialised totals must agree with the ledger they summarise.
	 *
	 * TWO SOURCES OF TRUTH FOR ONE NUMBER, AND NOTHING CHECKED THEM.
	 *
	 * The snapshot writer SUMs the wb_gam_points ledger. totals_board() -- the live fallback -- reads
	 * the materialised wb_gam_user_totals. They are supposed to be the same number, and the write path
	 * keeps them so: PointsEngine inserts the ledger row and upserts the total inside one transaction,
	 * so neither can commit without the other.
	 *
	 * But nothing verified it afterwards, and anything that touches the ledger from OUTSIDE the engine
	 * breaks the tie silently -- a bad import, a crashed transaction, a support engineer running SQL, a
	 * bug in a version since fixed. When it breaks, the warm board and the stale board serve DIFFERENT
	 * MEMBERS with DIFFERENT POINTS, and the only symptom is that the leaderboard changes when the cron
	 * runs. That is exactly what QA saw, and there was no way to diagnose it.
	 *
	 * Found by chasing precisely that: nine members on the QA database had a totals row that did not
	 * match their ledger, one by 515 points, and the two boards disagreed about who was even on them.
	 *
	 * --fix recomputes the drifted rows from the ledger, which is the authority: the ledger is the
	 * append-only record of what happened; the total is a cache of it.
	 */
	private function check_totals_match_ledger(): void {
		$this->section( 'Points totals vs ledger' );

		global $wpdb;

		$points = $wpdb->prefix . 'wb_gam_points';
		$totals = $wpdb->prefix . 'wb_gam_user_totals';

		// Both directions. Walking only the ledger misses a totals row with no ledger behind it at all,
		// and that is the row that put a member on one board and not the other.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$drifted = $wpdb->get_results(
			"SELECT t.user_id, t.point_type, t.total AS totals_says, COALESCE( l.led, 0 ) AS ledger_says
			   FROM {$totals} t
			   LEFT JOIN ( SELECT user_id, point_type, SUM(points) led FROM {$points} GROUP BY user_id, point_type ) l
			          ON l.user_id = t.user_id AND l.point_type = t.point_type
			  WHERE t.total <> COALESCE( l.led, 0 )
			  ORDER BY ABS( t.total - COALESCE( l.led, 0 ) ) DESC",
			ARRAY_A
		);

		if ( ! $drifted ) {
			$this->pass( 'Every wb_gam_user_totals row matches the ledger' );
			return;
		}

		$this->fail(
			sprintf(
				'%d member total(s) disagree with the ledger. The snapshot board sums the LEDGER and the '
					. 'live board reads these TOTALS, so the two boards will serve different members.',
				count( $drifted )
			)
		);

		foreach ( array_slice( $drifted, 0, 5 ) as $row ) {
			WP_CLI::line(
				sprintf(
					'    user %d (%s): totals says %d, ledger says %d  (%+d)',
					(int) $row['user_id'],
					(string) $row['point_type'],
					(int) $row['totals_says'],
					(int) $row['ledger_says'],
					(int) $row['totals_says'] - (int) $row['ledger_says']
				)
			);
		}
		if ( count( $drifted ) > 5 ) {
			WP_CLI::line( sprintf( '    ... and %d more', count( $drifted ) - 5 ) );
		}

		if ( ! $this->fix ) {
			WP_CLI::line( '    → run with --fix to recompute these from the ledger.' );
			return;
		}

		foreach ( $drifted as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$totals,
				array( 'total' => (int) $row['ledger_says'] ),
				array(
					'user_id'    => (int) $row['user_id'],
					'point_type' => (string) $row['point_type'],
				),
				array( '%d' ),
				array( '%d', '%s' )
			);
		}

		LeaderboardEngine::write_snapshot();
		LeaderboardEngine::invalidate_cache();

		WP_CLI::success( sprintf( '  Recomputed %d total(s) from the ledger and rebuilt the snapshot.', count( $drifted ) ) );
	}

	/**
	 * How many members SHOULD be on a board of this period. An independent COUNT(*), not the engine.
	 *
	 * The point of an oracle is that it does not share a bug with the thing it is checking. Asking
	 * LeaderboardEngine how many members it thinks are eligible and then checking that it returned
	 * that many would pass on every board it has ever got wrong.
	 *
	 * So this counts them the long way, from the ledger, applying the same three predicates the
	 * product applies -- the member EXISTS, has not OPTED OUT, and is not owner-EXCLUDED -- in SQL
	 * written here, on purpose.
	 *
	 * @param string $period 'all' | 'month' | 'week' | 'day'.
	 * @return int Members eligible for that board.
	 */
	private static function count_eligible_members( string $period ): int {
		global $wpdb;

		$starts = array(
			'day'   => Clock::site_day_start( 'today' ),
			'week'  => Clock::site_cutoff( 'monday this week' ),
			'month' => Clock::site_day_start( 'first day of this month' ),
		);

		$since = $starts[ $period ] ?? null;
		$where = '';
		$binds = array( PointsEngine::resolve_type( null ) );

		if ( null !== $since ) {
			$where   = ' AND p.created_at >= %s';
			$binds[] = $since;
		}

		$excluded_users = array_map( 'absint', (array) get_option( 'wb_gam_excluded_users', array() ) );
		$excluded_roles = array_map( 'sanitize_key', (array) get_option( 'wb_gam_excluded_roles', array() ) );

		$excl = '';
		if ( $excluded_users ) {
			$excl .= ' AND p.user_id NOT IN ( ' . implode( ',', array_fill( 0, count( $excluded_users ), '%d' ) ) . ' )';
			$binds = array_merge( $binds, $excluded_users );
		}
		foreach ( $excluded_roles as $role ) {
			$excl   .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} rm WHERE rm.user_id = p.user_id"
				. " AND rm.meta_key = '{$wpdb->get_blog_prefix()}capabilities' AND rm.meta_value LIKE %s )";
			$binds[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT p.user_id
					  FROM {$wpdb->prefix}wb_gam_points p
					  JOIN {$wpdb->users} u ON u.ID = p.user_id
					 WHERE p.point_type = %s {$where} {$excl}
					   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}wb_gam_member_prefs mp
					                     WHERE mp.user_id = p.user_id AND mp.leaderboard_opt_out = 1 )
					 GROUP BY p.user_id
					HAVING SUM(p.points) > 0
				) eligible",
				$binds
			)
		);
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

		// The "minified assets" check used to live here, and every part of it was fiction. It looked
		// for assets/css/admin-premium.min.css, which does not exist and never has. It looked for
		// assets/js/settings-nav.min.js, which existed but was enqueued by nothing -- WordPress loads
		// the unminified settings-nav.js. And when it failed, which was always, it told you to run
		// `grunt build`, in a repo with no grunt, no Gruntfile and no reference to grunt anywhere in
		// package.json. So it warned on every doctor run about a requirement that does not exist and
		// prescribed a command that cannot be run. A check that is always wrong teaches people to
		// ignore the checks that are not. Both dead .min.js files went with it.

		// Check essential frontend features.
		$blocks_dir = WB_GAM_PATH . 'build/Blocks/';
		if ( is_dir( $blocks_dir ) ) {
			$blocks = glob( $blocks_dir . '*/block.json' ) ?: [];
			$this->pass( count( $blocks ) . ' Gutenberg blocks registered' );
		} else {
			$this->warn( 'No build/Blocks/ directory — run `npm run build` to compile blocks' );
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
			'mycred/mycred.php'       => 'myCred',
			'gamipress/gamipress.php' => 'GamiPress',
			'badgeos/badgeos.php'     => 'BadgeOS',
		];
		$conflicts   = [];
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

	// ── One-shot operational helpers ────────────────────────────────────────────

	/**
	 * Drain the Action Scheduler tables after a runaway-hook incident.
	 *
	 * Loops `ActionSchedulerCleaner::cleanup()` (which auto-detects runaway
	 * state and switches to a 1-hour retention horizon) until either the
	 * AS row count drops back under the runaway threshold or `$max_ticks`
	 * is exhausted. Each tick respects the cleaner's own 50-second runtime
	 * budget — this is a long-running command, expect ~5 minutes per 1M
	 * rows on a healthy local install.
	 *
	 * @param int $max_ticks Cap on cleanup() invocations. Defaults to 20.
	 */
	private function drain_action_scheduler( int $max_ticks ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			WP_CLI::error( 'Action Scheduler tables not found. Nothing to drain.' );
		}

		WP_CLI::line( WP_CLI::colorize( '%BWB Gamification — Action Scheduler drain%n' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		WP_CLI::line( sprintf( 'Initial row count: %s', number_format( $before ) ) );

		$max_ticks = max( 1, $max_ticks );
		$total     = array(
			'complete' => 0,
			'failed'   => 0,
			'pending'  => 0,
		);

		for ( $tick = 1; $tick <= $max_ticks; $tick++ ) {
			$result = \WBGam\Engine\ActionSchedulerCleaner::cleanup();

			foreach ( array( 'complete', 'failed', 'pending' ) as $status ) {
				$total[ $status ] += (int) ( $result[ $status ] ?? 0 );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

			WP_CLI::line(
				sprintf(
					'tick %2d: complete=%s failed=%s pending=%s | rows now=%s',
					$tick,
					number_format( $result['complete'] ?? 0 ),
					number_format( $result['failed'] ?? 0 ),
					number_format( $result['pending'] ?? 0 ),
					number_format( $current )
				)
			);

			// All three statuses returned zero — no more candidates within the
			// current retention horizon; further ticks would be no-ops.
			$tick_deleted = (int) ( $result['complete'] ?? 0 )
				+ (int) ( $result['failed'] ?? 0 )
				+ (int) ( $result['pending'] ?? 0 );
			if ( 0 === $tick_deleted ) {
				WP_CLI::line( 'Tick produced no deletions — drain complete.' );
				break;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		WP_CLI::line( '' );
		WP_CLI::line(
			sprintf(
				'Drain finished. Rows: %s → %s (Δ %s deleted).',
				number_format( $before ),
				number_format( $after ),
				number_format( $before - $after )
			)
		);
		WP_CLI::line(
			sprintf(
				'Per-status totals: complete=%s failed=%s pending=%s',
				number_format( $total['complete'] ),
				number_format( $total['failed'] ),
				number_format( $total['pending'] )
			)
		);

		$runaway = \WBGam\Engine\ActionSchedulerCleaner::get_runaway_state();
		if ( false === $runaway ) {
			WP_CLI::success( 'AS tables are back under the runaway threshold.' );
		} else {
			WP_CLI::warning(
				sprintf(
					'Still in runaway state: %s rows (top hook: %s = %s rows). Re-run with --max-ticks=%d.',
					number_format( $runaway['rows'] ),
					$runaway['top_hook'],
					number_format( $runaway['top_hook_n'] ),
					$max_ticks * 2
				)
			);
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
