<?php
/**
 * Feature Flags — free/pro engine split and lazy loading.
 *
 * Separates engines into:
 *   - Core (free, always loaded)
 *   - Pro  (loaded only when wb-gamification-pro is active AND the feature flag is on)
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Manages feature flags and boots engines based on free/pro status.
 *
 * Core engines always boot. Pro engines boot only when the pro add-on
 * is active AND the individual feature flag is enabled in settings.
 */
final class FeatureFlags {

	/**
	 * Option key for persisted feature flags.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'wb_gam_features';

	/**
	 * Core engines — always boot (free plugin).
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
		'RankAutomation',
		'PersonalRecordEngine',
		'NotificationBridge',
		'Privacy',
		'CredentialExpiryEngine',
		'TransactionalEmailEngine',
		'LoginBonusEngine',
		'ProfilePage',
	];

	/**
	 * Optional pro engines mapped by feature flag key.
	 *
	 * Boot only when the flag is on AND the pro plugin is active.
	 *
	 * @var array<string, string>
	 */
	private const PRO_ENGINES = [
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
	 * All features enabled by default — WB Gamification is 100% free.
	 * Admins can toggle individual features off in Settings → Features.
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
	 * Whether the pro add-on is active.
	 *
	 * Pro plugin defines `WB_GAM_PRO_VERSION` on load.
	 *
	 * @return bool
	 */
	public static function is_pro_active(): bool {
		return defined( 'WB_GAM_PRO_VERSION' );
	}

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
	 * Get the pro engine map (flag key => class name).
	 *
	 * @return array<string, string>
	 */
	public static function get_pro_engine_map(): array {
		return self::PRO_ENGINES;
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

		// Optional engines — boot if feature flag is enabled (all on by default).
		foreach ( self::PRO_ENGINES as $flag => $class ) {
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
		 * Pro plugin hooks here to register additional engines or override
		 * behaviour.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wb_gam_engines_booted' );
	}
}
