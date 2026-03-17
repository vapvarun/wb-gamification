<?php
/**
 * Credential Expiry Engine — HubSpot-style renewal re-engagement
 *
 * When a badge_def has `validity_days > 0`, credentials earned from that
 * badge expire N days after award. CredentialController returns HTTP 410 Gone
 * for expired credentials, prompting holders to renew.
 *
 * This engine runs a weekly cron to notify members whose credentials expired
 * since the last run, creating the re-engagement cycle:
 *   Earn badge → credential verifiable → credential expires (410 Gone) →
 *   member receives nudge → completes renewal action → re-earns badge.
 *
 * The renewal action is not hardcoded here — the `wb_gamification_credential_expired`
 * hook lets the site admin trigger whatever re-qualification flow makes sense.
 *
 * @package WB_Gamification
 * @since   0.3.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class CredentialExpiryEngine {

	private const CRON_HOOK = 'wb_gam_credential_expiry_check';
	private const OPT_LAST  = 'wb_gam_credential_expiry_last_run';

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Every Monday at 06:00 UTC — well after the cohort-assign cron (00:05).
			$next = strtotime( 'next monday 06:00:00 UTC' );
			wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ── Cron handler ─────────────────────────────────────────────────────────

	/**
	 * Find credentials that expired since the last run and notify holders.
	 *
	 * Uses a stored last-run timestamp so notifications fire exactly once per
	 * expiry event, no matter when the cron actually runs.
	 */
	public static function run(): void {
		global $wpdb;

		// Default: look back 8 days so the first-ever run is not a no-op.
		$last_run = get_option( self::OPT_LAST, gmdate( 'Y-m-d H:i:s', strtotime( '-8 days' ) ) );
		$now      = gmdate( 'Y-m-d H:i:s' );

		// Credentials that expired in the window (last_run, now].
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, badge_id, expires_at
				   FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE expires_at > %s AND expires_at <= %s",
				$last_run,
				$now
			),
			ARRAY_A
		);

		update_option( self::OPT_LAST, $now );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$user_id  = (int) $row['user_id'];
			$badge_id = (string) $row['badge_id'];

			// BP notification — "Your [Badge Name] credential has expired."
			if ( function_exists( 'bp_notifications_add_notification' ) ) {
				bp_notifications_add_notification(
					[
						'user_id'          => $user_id,
						'item_id'          => $user_id,
						'component_name'   => 'wb_gamification',
						'component_action' => 'credential_expired',
						'date_notified'    => bp_core_current_time(),
						'is_new'           => 1,
					]
				);
			}

			/**
			 * Fires when a credential badge expires.
			 *
			 * Hook this to trigger renewal flows: send email, remove role,
			 * redirect member to re-qualification challenge, etc.
			 *
			 * @param int    $user_id    User whose credential expired.
			 * @param string $badge_id   Badge identifier.
			 * @param string $expired_at MySQL datetime of expiry.
			 */
			do_action( 'wb_gamification_credential_expired', $user_id, $badge_id, $row['expires_at'] );
		}
	}

	// ── Public helpers ────────────────────────────────────────────────────────

	/**
	 * Check whether a specific user+badge credential has expired.
	 *
	 * Returns false when expires_at is NULL (credential never expires).
	 *
	 * @param int    $user_id  User to check.
	 * @param string $badge_id Badge identifier.
	 * @return bool
	 */
	public static function is_expired( int $user_id, string $badge_id ): bool {
		global $wpdb;

		$expires_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT expires_at FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d AND badge_id = %s",
				$user_id,
				$badge_id
			)
		);

		if ( ! $expires_at ) {
			return false;
		}

		return strtotime( $expires_at ) <= time();
	}
}
