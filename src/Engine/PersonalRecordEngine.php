<?php
/**
 * WB Gamification — Personal Record Engine
 *
 * Detects when a member sets a new personal milestone and fires a
 * private, positive notification. Strava-model: never public, never
 * comparative against other members — purely a celebration of personal growth.
 *
 * Milestones tracked:
 *   - best_week  : highest weekly points total ever
 *   - best_month : highest monthly points total ever
 *   - best_day   : highest single-day points total ever
 *
 * How it works:
 *   - Hooked onto `wb_gamification_points_awarded` (runs after every award).
 *   - Calculates the current period total for the user.
 *   - Compares against the stored personal best in user meta.
 *   - If a new personal best is set, fires `wb_gamification_personal_record`
 *     and sends a BP notification.
 *
 * Personal bests are stored in user meta:
 *   - wb_gam_pr_best_week   (int)
 *   - wb_gam_pr_best_month  (int)
 *   - wb_gam_pr_best_day    (int)
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Detects when a member sets a new personal points record and fires a notification.
 *
 * @package WB_Gamification
 */
final class PersonalRecordEngine {

	/**
	 * Register the points-awarded hook for personal record detection.
	 */
	public static function init(): void {
		// Priority 20 — runs after BadgeEngine (10) and after all side-effects settle.
		add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'check_personal_records' ), 20, 3 );
	}

	// ── Hook handler ────────────────────────────────────────────────────────────

	/**
	 * Check whether this award creates a new personal best.
	 *
	 * @param int   $user_id User who just earned points.
	 * @param Event $event   The event that triggered the award.
	 * @param int   $points  Points awarded (not the total — just this award).
	 */
	public static function check_personal_records( int $user_id, Event $event, int $points ): void {
		self::maybe_record( $user_id, 'day', self::period_total( $user_id, 'day' ) );
		self::maybe_record( $user_id, 'week', self::period_total( $user_id, 'week' ) );
		self::maybe_record( $user_id, 'month', self::period_total( $user_id, 'month' ) );
	}

	// ── Record detection ────────────────────────────────────────────────────────

	/**
	 * Compare period total against stored personal best. Update + notify if new best.
	 *
	 * @param int    $user_id User to check.
	 * @param string $period  'day' | 'week' | 'month'.
	 * @param int    $current Current period total.
	 */
	private static function maybe_record( int $user_id, string $period, int $current ): void {
		if ( $current <= 0 ) {
			return;
		}

		$meta_key = 'wb_gam_pr_best_' . $period;
		$previous = (int) get_user_meta( $user_id, $meta_key, true );

		if ( $current <= $previous ) {
			return; // Not a new personal best.
		}

		// Update stored personal best.
		update_user_meta( $user_id, $meta_key, $current );

		$message = self::build_message( $period, $current, $previous );

		// BuddyPress notification.
		if ( function_exists( 'bp_notifications_add_notification' ) ) {
			bp_notifications_add_notification(
				array(
					'user_id'           => $user_id,
					'item_id'           => $current,
					'secondary_item_id' => $previous,
					'component_name'    => 'wb_gamification',
					'component_action'  => 'personal_record_' . $period,
					'date_notified'     => bp_core_current_time(),
					'is_new'            => 1,
				)
			);
		}

		/**
		 * Fires when a member sets a new personal points record for a period.
		 *
		 * Custom integrations (push, email, Slack) hook in here.
		 *
		 * @param int    $user_id  User who set the record.
		 * @param string $period   'day' | 'week' | 'month'
		 * @param int    $current  New personal best (points this period).
		 * @param int    $previous Previous personal best (0 if first time).
		 * @param string $message  Human-readable congratulations message.
		 */
		do_action( 'wb_gamification_personal_record', $user_id, $period, $current, $previous, $message );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Sum points for a user within a period (day / week / month).
	 *
	 * @param int    $user_id User ID to sum points for.
	 * @param string $period  'day' | 'week' | 'month'.
	 * @return int Total points for the period.
	 */
	private static function period_total( int $user_id, string $period ): int {
		global $wpdb;

		$period_starts = array(
			'day'   => gmdate( 'Y-m-d' ) . ' 00:00:00',
			'week'  => gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00',
			'month' => gmdate( 'Y-m-01' ) . ' 00:00:00',
		);

		$start = $period_starts[ $period ] ?? null;
		if ( null === $start ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points),0)
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND created_at >= %s",
				$user_id,
				$start
			)
		);
	}

	/**
	 * Build a congratulatory message string.
	 *
	 * @param string $period   Period label ('day' | 'week' | 'month').
	 * @param int    $current  New personal best points value.
	 * @param int    $previous Previous personal best (0 if first time).
	 * @return string Translated congratulatory message.
	 */
	private static function build_message( string $period, int $current, int $previous ): string {
		$period_labels = array(
			'day'   => __( 'today', 'wb-gamification' ),
			'week'  => __( 'this week', 'wb-gamification' ),
			'month' => __( 'this month', 'wb-gamification' ),
		);

		$label = $period_labels[ $period ] ?? $period;

		if ( 0 === $previous ) {
			return sprintf(
				/* translators: 1: points, 2: period label e.g. "this week" */
				__( 'Personal record! You earned %1$d points %2$s — your best ever!', 'wb-gamification' ),
				$current,
				$label
			);
		}

		return sprintf(
			/* translators: 1: points, 2: period label, 3: previous personal best */
			__( 'New personal record! %1$d points %2$s — beating your previous best of %3$d!', 'wb-gamification' ),
			$current,
			$label,
			$previous
		);
	}
}
