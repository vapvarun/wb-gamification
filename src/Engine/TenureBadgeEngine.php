<?php
/**
 * WB Gamification Tenure Badge Engine
 *
 * Auto-awards anniversary badges based on how long a member has been
 * registered on the site.
 *
 * Badges awarded:
 *   tenure_1yr   — 1 year member
 *   tenure_2yr   — 2 year member
 *   tenure_5yr   — 5 year member
 *   tenure_10yr  — 10 year member
 *
 * These badges are inserted into wb_gam_badge_defs by this engine on init
 * if they don't already exist (idempotent). Conditions use a special
 * `condition_type: tenure_days` rule type handled by BadgeEngine.
 *
 * Check timing:
 *   - Daily WP-Cron job (`wb_gam_tenure_check`) runs at 02:00 UTC.
 *   - Also fires on `user_register` (delayed check via AS) to catch newly-
 *     registered users who somehow already qualify (e.g. re-registration).
 *   - Checks only members whose registration anniversary falls today, using
 *     DAY(user_registered) = DAY(NOW()) AND MONTH(user_registered) = MONTH(NOW())
 *     so the query is very narrow.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-awards anniversary badges based on how long a member has been registered.
 *
 * @package WB_Gamification
 */
final class TenureBadgeEngine {

	/**
	 * Tenure badge tier definitions keyed by badge ID.
	 *
	 * @var array<string, array{years: int, name: string, description: string}>
	 */
	private const TIERS = array(
		'tenure_1yr'  => array(
			'years'       => 1,
			'name'        => '1-Year Member',
			'description' => 'Has been part of this community for one year.',
		),
		'tenure_2yr'  => array(
			'years'       => 2,
			'name'        => '2-Year Member',
			'description' => 'A two-year member — a valued, long-standing contributor.',
		),
		'tenure_5yr'  => array(
			'years'       => 5,
			'name'        => '5-Year Member',
			'description' => 'Five years and counting — a true community pillar.',
		),
		'tenure_10yr' => array(
			'years'       => 10,
			'name'        => '10-Year Member',
			'description' => 'A decade of community membership — legendary status.',
		),
	);

	private const CRON_HOOK = 'wb_gam_tenure_check';

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register the daily cron hook and ensure badge definitions exist.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_check' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'ensure_badges_exist' ), 15 );
	}

	/**
	 * Schedule the daily tenure-check cron event on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for 02:00 UTC today (or tomorrow if already past).
			$next = strtotime( 'tomorrow 02:00:00 UTC' );
			wp_schedule_event( $next, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily tenure-check cron event on plugin deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// ── Ensure badge defs exist ──────────────────────────────────────────────────

	/**
	 * Insert tenure badge definitions into wb_gam_badge_defs if missing.
	 * Runs on every plugins_loaded but is a no-op after first run.
	 */
	public static function ensure_badges_exist(): void {
		// After the first successful seed, skip all 4 SELECT queries on every page load.
		if ( get_option( 'wb_gam_tenure_seeded' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_badge_defs';

		foreach ( self::TIERS as $id => $tier ) {
			// Skip if already exists. $table is $wpdb->prefix . literal string, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM $table WHERE id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is safe.
			);

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'id'          => $id,
					'name'        => $tier['name'],
					'description' => $tier['description'],
					'category'    => 'special',
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}

		// Mark as seeded so subsequent page loads skip entirely.
		update_option( 'wb_gam_tenure_seeded', 1, true ); // autoload=true for fast check.
	}

	// ── Daily check ─────────────────────────────────────────────────────────────

	/**
	 * Find members whose registration anniversary is today and award
	 * any tenure badges they haven't yet received.
	 */
	public static function run_daily_check(): void {
		global $wpdb;

		// Fetch users whose registration month+day matches today.
		// We chunk in batches of 200 to avoid memory issues on large sites.
		$offset     = 0;
		$batch_size = 200;

		do {
			$users = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->users}
					  WHERE MONTH(user_registered) = MONTH(NOW())
					    AND DAY(user_registered)   = DAY(NOW())
					  LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);

			$fetched = count( $users );

			foreach ( $users as $user_id ) {
				self::check_user( (int) $user_id );
			}

			$offset += $batch_size;
		} while ( $fetched === $batch_size );
	}

	/**
	 * Check and award all applicable tenure badges for a single user.
	 *
	 * @param int $user_id User ID to check.
	 */
	public static function check_user( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$years_member = self::years_since( $user->user_registered );

		foreach ( self::TIERS as $badge_id => $tier ) {
			if ( $years_member < $tier['years'] ) {
				continue;
			}

			// Award via BadgeEngine (which handles duplicate protection).
			BadgeEngine::award_badge( $user_id, $badge_id );
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/**
	 * How many full years ago was the given MySQL datetime?
	 *
	 * @param string $registered MySQL datetime string e.g. "2020-03-15 10:00:00".
	 * @return int Number of full years elapsed since the given date.
	 */
	private static function years_since( string $registered ): int {
		try {
			$reg  = new \DateTime( $registered, new \DateTimeZone( 'UTC' ) );
			$now  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			$diff = $reg->diff( $now );
			return (int) $diff->y;
		} catch ( \Exception $e ) {
			Log::error(
				'TenureBadgeEngine — year-diff failed, returning 0 (no tenure badges will fire for this user this run)',
				array( 'error' => $e->getMessage() )
			);
			return 0;
		}
	}
}
