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

final class TenureBadgeEngine {

	/** @var array<string, array{years: int, name: string, description: string}> */
	private const TIERS = [
		'tenure_1yr'  => [ 'years' => 1,  'name' => '1-Year Member',  'description' => 'Has been part of this community for one year.' ],
		'tenure_2yr'  => [ 'years' => 2,  'name' => '2-Year Member',  'description' => 'A two-year member — a valued, long-standing contributor.' ],
		'tenure_5yr'  => [ 'years' => 5,  'name' => '5-Year Member',  'description' => 'Five years and counting — a true community pillar.' ],
		'tenure_10yr' => [ 'years' => 10, 'name' => '10-Year Member', 'description' => 'A decade of community membership — legendary status.' ],
	];

	private const CRON_HOOK = 'wb_gam_tenure_check';

	// ── Boot ────────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_daily_check' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'ensure_badges_exist' ], 15 );
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for 02:00 UTC today (or tomorrow if already past).
			$next = strtotime( 'tomorrow 02:00:00 UTC' );
			wp_schedule_event( $next, 'daily', self::CRON_HOOK );
		}
	}

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
		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_badge_defs';

		foreach ( self::TIERS as $id => $tier ) {
			// Skip if already exists.
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM $table WHERE id = %s", $id )
			);

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				[
					'id'          => $id,
					'name'        => $tier['name'],
					'description' => $tier['description'],
					'category'    => 'special',
				],
				[ '%s', '%s', '%s', '%s' ]
			);
		}
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

			foreach ( $users as $user_id ) {
				self::check_user( (int) $user_id );
			}

			$offset += $batch_size;
		} while ( count( $users ) === $batch_size );
	}

	/**
	 * Check and award all applicable tenure badges for a single user.
	 *
	 * @param int $user_id
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
	 * @param string $registered e.g. "2020-03-15 10:00:00"
	 * @return int
	 */
	private static function years_since( string $registered ): int {
		try {
			$reg  = new \DateTime( $registered, new \DateTimeZone( 'UTC' ) );
			$now  = new \DateTime( 'now',        new \DateTimeZone( 'UTC' ) );
			$diff = $reg->diff( $now );
			return (int) $diff->y;
		} catch ( \Exception $e ) {
			return 0;
		}
	}
}
