<?php
/**
 * Feature Flags — single-plugin engine boot and per-feature toggles.
 *
 * All engines ship in the free plugin. Heavy / opt-in engines are listed
 * in OPTIONAL_ENGINES and gated by a per-feature flag that defaults to
 * `true`, so admins can disable individual features in Settings → Features
 * without code changes.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Manages feature flags and boots engines.
 *
 * Core engines always boot. Optional engines boot only when their
 * feature flag is enabled in settings (all enabled by default).
 */
final class FeatureFlags {

	/**
	 * Option key for persisted feature flags.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'wb_gam_features';

	/**
	 * Core engines — always boot.
	 *
	 * Excludes ManifestLoader, Registry, and Engine which keep their own
	 * priority-ordered `add_action` calls (priorities 5, 6, 8).
	 *
	 * @var string[]
	 */
	private const CORE_ENGINES = [
		'BadgeEngine',
		'ChallengeEngine',
		'KudosEngine',
		'LogPruner',
		'ActionSchedulerCleaner',
		'RankAutomation',
		'PersonalRecordEngine',
		'NotificationBridge',
		'Privacy',
		'CredentialExpiryEngine',
		'TransactionalEmailEngine',
		'LoginBonusEngine',
		'ProfilePage',
		// Grants upload_files to logged-in members so the
		// submit-achievement editor's Add Media button works for
		// subscribers / contributors. Opt out via the
		// `wb_gam_grant_member_uploads` filter.
		'MemberUploadCap',
	];

	/**
	 * Optional engines mapped by feature flag key.
	 *
	 * Boot when the flag is on (defaults to true for every flag).
	 *
	 * @var array<string, string>
	 */
	private const OPTIONAL_ENGINES = [
		'cohort_leagues'       => 'CohortEngine',
		'weekly_emails'        => 'WeeklyEmailEngine',
		'leaderboard_nudge'    => 'LeaderboardNudge',
		'status_retention'     => 'StatusRetentionEngine',
		'community_challenges' => 'CommunityChallengeEngine',
		'site_first_badges'    => 'SiteFirstBadgeEngine',
		'tenure_badges'        => 'TenureBadgeEngine',
		'badge_share'          => 'BadgeSharePage',
	];

	/**
	 * Default state for every feature flag.
	 *
	 * All features enabled by default. Admins can toggle individual
	 * features off in Settings → Features.
	 *
	 * @var array<string, bool>
	 */
	private const DEFAULTS = [
		'cohort_leagues'       => true,
		'weekly_emails'        => true,
		'leaderboard_nudge'    => true,
		'status_retention'     => true,
		'community_challenges' => true,
		'site_first_badges'    => true,
		'tenure_badges'        => true,
		'badge_share'          => true,
	];

	/**
	 * Runtime cache of resolved feature flags.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $features = null;

	/**
	 * Check if a specific feature flag is enabled.
	 *
	 * @param string $feature Feature flag key (e.g. 'cohort_leagues').
	 * @return bool
	 */
	public static function is_enabled( string $feature ): bool {
		$features = self::get_all();
		return ! empty( $features[ $feature ] );
	}

	/**
	 * Get all feature flags merged with defaults.
	 *
	 * @return array<string, bool>
	 */
	public static function get_all(): array {
		if ( null === self::$features ) {
			self::$features = wp_parse_args(
				(array) get_option( self::OPTION_KEY, [] ),
				self::DEFAULTS
			);
		}
		return self::$features;
	}

	/**
	 * Persist updated feature flags and bust the static cache.
	 *
	 * @param array<string, bool> $features Feature flags to save.
	 * @return bool True on successful update.
	 */
	public static function update( array $features ): bool {
		self::$features = null; // bust static cache.
		return update_option( self::OPTION_KEY, $features );
	}

	/**
	 * Get the default flag values.
	 *
	 * @return array<string, bool>
	 */
	public static function get_defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Get the optional engine map (flag key => class name).
	 *
	 * @return array<string, string>
	 */
	public static function get_optional_engine_map(): array {
		return self::OPTIONAL_ENGINES;
	}

	/**
	 * Boot all engines. Called from wb-gamification.php at plugins_loaded priority 10.
	 *
	 * ManifestLoader (5), Registry (6), and Engine (8) keep their own
	 * priority-ordered `add_action` calls — this method handles everything
	 * at priority 10+.
	 *
	 * @return void
	 */
	public static function boot_engines(): void {
		$namespace = 'WBGam\\Engine\\';

		// Core engines — always boot.
		foreach ( self::CORE_ENGINES as $class ) {
			$fqcn = $namespace . $class;
			if ( method_exists( $fqcn, 'init' ) ) {
				$fqcn::init();
			}
		}

		// Optional engines — boot if their feature flag is enabled (all on by default).
		foreach ( self::OPTIONAL_ENGINES as $flag => $class ) {
			if ( self::is_enabled( $flag ) ) {
				$fqcn = $namespace . $class;
				if ( method_exists( $fqcn, 'init' ) ) {
					$fqcn::init();
				}
			}
		}

		/**
		 * Fires after all engines have booted.
		 *
		 * Third-party extensions hook here to register additional engines
		 * or override behaviour.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wb_gam_engines_booted' );
	}
}
