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
	 * Register with AsyncEvaluator for batched async processing.
	 *
	 * Previously hooked synchronously on `wb_gamification_points_awarded` at
	 * priority 20. Now deferred to reduce per-award DB queries from ~12-15 to ~5.
	 */
	public static function init(): void {
		AsyncEvaluator::register( array( __CLASS__, 'check_personal_records_async' ) );
	}

	/**
	 * Async wrapper for check_personal_records.
	 *
	 * Accepts the plain array format from AsyncEvaluator (the Event object
	 * is not serializable for Action Scheduler transport) and delegates to
	 * the existing check logic.
	 *
	 * @param int   $user_id    User who just earned points.
	 * @param array $event_data Decomposed event data array.
	 * @param int   $points     Points awarded.
	 */
	public static function check_personal_records_async( int $user_id, array $event_data, int $points ): void {
		self::check_personal_records( $user_id, $event_data, $points );
	}

	// ── Hook handler ────────────────────────────────────────────────────────────

	/**
	 * Check whether this award creates a new personal best.
	 *
	 * Accepts either an Event object (legacy sync path) or a plain array
	 * (async batch path via AsyncEvaluator). The event data is unused — only
	 * the user_id matters for period-total lookups.
	 *
	 * @param int         $user_id    User who just earned points.
	 * @param Event|array $event_data The event that triggered the award (Event or plain array).
	 * @param int         $points     Points awarded (not the total — just this award).
	 */
	public static function check_personal_records( int $user_id, Event|array $event_data, int $points ): void {
		$totals = self::period_totals( $user_id );
		self::maybe_record( $user_id, 'day', $totals['day_total'] );
		self::maybe_record( $user_id, 'week', $totals['week_total'] );
		self::maybe_record( $user_id, 'month', $totals['month_total'] );
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
	 * Fetch day, week, and month point totals in a single query.
	 *
	 * Uses CASE WHEN expressions so the DB scans only rows from the current
	 * month (widest range) and conditionally buckets them into the three periods.
	 *
	 * @param int $user_id User ID to sum points for.
	 * @return array{day_total: int, week_total: int, month_total: int}
	 */
	private static function period_totals( int $user_id ): array {
		global $wpdb;

		$day_start   = gmdate( 'Y-m-d' ) . ' 00:00:00';
		$week_start  = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
		$month_start = gmdate( 'Y-m-01' ) . ' 00:00:00';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN created_at >= %s THEN points ELSE 0 END), 0) AS day_total,
					COALESCE(SUM(CASE WHEN created_at >= %s THEN points ELSE 0 END), 0) AS week_total,
					COALESCE(SUM(CASE WHEN created_at >= %s THEN points ELSE 0 END), 0) AS month_total
				FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND created_at >= %s",
				$day_start,
				$week_start,
				$month_start,
				$user_id,
				$month_start
			),
			ARRAY_A
		);

		return array(
			'day_total'   => (int) ( $row['day_total'] ?? 0 ),
			'week_total'  => (int) ( $row['week_total'] ?? 0 ),
			'month_total' => (int) ( $row['month_total'] ?? 0 ),
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
