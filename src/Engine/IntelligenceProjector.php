<?php
/**
 * Read-side projection: per-user behavioural intelligence signals.
 *
 * v2.5 (the projection pattern) + AI intelligence v1 (the heuristic
 * implementation), shipped together because v2.5 was always going to
 * need a first example to validate the pattern.
 *
 * Projection contract:
 *   1. Source: wb_gam_events (immutable log — the source of truth).
 *   2. Output: wb_gam_user_intelligence (denormalised, recomputable).
 *   3. Trigger: daily cron `wb_gam_compute_intelligence`. Per-user
 *      compute is also exposed for on-demand recompute.
 *   4. No data loss: if the projection row is wrong or stale,
 *      recompute from the event log restores it. Don't write to
 *      this table from anywhere except the projector — anyone who
 *      needs a derived signal can either query the row OR
 *      recompute(user_id) before reading.
 *
 * Heuristic v1 (no ML, no training):
 *
 *   engagement_score = log10(events_30d + 1)
 *                      × min(action_diversity / 5, 1.0)
 *                      × max(0, 1.0 - recency_days / 30)
 *
 *   Three multiplicands so each signal has to be present for the
 *   user to score high. Power user (lots of events, varied actions,
 *   recently active) → high score. Inactive (no events in 30 days)
 *   → score = 0 regardless of past activity. Single-action grinder
 *   (1000 events, 1 distinct action) → diversity multiplier caps the
 *   score so we don't over-reward farmable signals.
 *
 *   churn_risk = 1.0 - normalised(engagement_score)
 *
 *   Above 0.7 = high risk. Above 0.9 = critical. Tunable via
 *   the wb_gam_churn_risk_thresholds filter (future).
 *
 *   anomaly_flag (boolean):
 *     SET to 1 when (events_30d > 500) AND (action_diversity < 3)
 *     Catches the "bot grinding 1 action 500 times" pattern. Tunable.
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class IntelligenceProjector {

	public const COMPUTE_CRON = 'wb_gam_compute_intelligence';

	/**
	 * Batch size per cron tick. The query path is bounded — we don't
	 * try to compute every user on a single cron run; we walk a slice.
	 *
	 * For a site with 100k users at the default daily schedule that
	 * means each user gets recomputed roughly every (100k/2000) = 50
	 * days. Sites that want fresher signals tune this OR add an
	 * on-demand recompute on critical events.
	 */
	public const BATCH_SIZE = 2000;

	/**
	 * Boot hook — registers the cron handler. Called once from
	 * wb-gamification.php's engine bootstrap.
	 */
	public static function boot(): void {
		add_action( self::COMPUTE_CRON, array( __CLASS__, 'compute_batch' ) );

		if ( ! wp_next_scheduled( self::COMPUTE_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::COMPUTE_CRON );
		}
	}

	/**
	 * Daily projection tick. Walks BATCH_SIZE users (least-recently-
	 * computed first) and recomputes their intelligence signals.
	 *
	 * @as-fire-once Daily cron tick. Bounded by BATCH_SIZE. The handler
	 *               iterates over selected user_ids and calls compute_for_user
	 *               in a loop — no recursion, no AS enqueue.
	 */
	public static function compute_batch(): void {
		if ( ! get_option( 'wb_gam_feature_user_intelligence_v1' ) ) {
			return;
		}

		global $wpdb;

		// Pick users to recompute: those with at least one event AND
		// whose intelligence row is either missing or oldest.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 86400 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT e.user_id
				   FROM (SELECT DISTINCT user_id FROM {$wpdb->prefix}wb_gam_events) e
				   LEFT JOIN {$wpdb->prefix}wb_gam_user_intelligence i
				     ON i.user_id = e.user_id
				  WHERE i.user_id IS NULL OR i.computed_at < %s
				  ORDER BY (i.computed_at IS NULL) DESC, i.computed_at ASC
				  LIMIT %d",
				$cutoff,
				self::BATCH_SIZE
			)
		);

		foreach ( (array) $user_ids as $user_id ) {
			self::compute_for_user( (int) $user_id );
		}
	}

	/**
	 * Compute intelligence signals for a single user and UPSERT the
	 * projection row. Safe to call on demand (e.g. from admin "Refresh"
	 * button or a debug CLI).
	 *
	 * Idempotent — same input always produces same output.
	 *
	 * @param int $user_id Target user.
	 */
	public static function compute_for_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( ! get_option( 'wb_gam_feature_user_intelligence_v1' ) ) {
			return;
		}

		global $wpdb;

		// Three aggregates from wb_gam_events.
		$events_table = $wpdb->prefix . 'wb_gam_events';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS events_30d,
					COUNT(DISTINCT action_id) AS action_diversity,
					COALESCE(DATEDIFF(NOW(), MAX(created_at)), 999) AS recency_days
				   FROM {$events_table}
				  WHERE user_id = %d
				    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$user_id
			),
			ARRAY_A
		);

		$events_30d       = (int) ( $row['events_30d'] ?? 0 );
		$action_diversity = (int) ( $row['action_diversity'] ?? 0 );
		$recency_days     = (int) ( $row['recency_days'] ?? 999 );

		// Engagement score: log volume × diversity multiplier × recency multiplier.
		$volume_factor    = log10( $events_30d + 1 );
		$diversity_factor = min( $action_diversity / 5.0, 1.0 );
		$recency_factor   = max( 0.0, 1.0 - ( $recency_days / 30.0 ) );
		$engagement       = $volume_factor * $diversity_factor * $recency_factor;

		// Churn risk: inverse of engagement, normalised to 0..1. We
		// know engagement maxes around log10(500+1) * 1.0 * 1.0 ≈ 2.7,
		// so divide by 2.7 to normalise. Clamped to [0, 1].
		$normalised_engagement = min( 1.0, $engagement / 2.7 );
		$churn_risk            = max( 0.0, 1.0 - $normalised_engagement );

		// Anomaly flag — bot pattern: high volume + low diversity.
		$anomaly = ( $events_30d > 500 && $action_diversity < 3 ) ? 1 : 0;

		// UPSERT. Single PK on user_id makes ON DUPLICATE KEY clean.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}wb_gam_user_intelligence
					(user_id, engagement_score, action_diversity, recency_days, events_30d, churn_risk, anomaly_flag, computed_at)
				 VALUES (%d, %f, %d, %d, %d, %f, %d, NOW())
				 ON DUPLICATE KEY UPDATE
					engagement_score = VALUES(engagement_score),
					action_diversity = VALUES(action_diversity),
					recency_days     = VALUES(recency_days),
					events_30d       = VALUES(events_30d),
					churn_risk       = VALUES(churn_risk),
					anomaly_flag     = VALUES(anomaly_flag),
					computed_at      = VALUES(computed_at)",
				$user_id,
				round( $engagement, 4 ),
				$action_diversity,
				$recency_days,
				$events_30d,
				round( $churn_risk, 3 ),
				$anomaly
			)
		);
	}

	/**
	 * Read the projection row for a user. Returns null if the user has
	 * never been projected (e.g. no events yet, or the cron hasn't
	 * reached them).
	 *
	 * Callers that need fresh signals can call compute_for_user($id)
	 * first; the next get_for_user($id) will return the just-updated
	 * row.
	 *
	 * @return array{user_id:int,engagement_score:float,action_diversity:int,recency_days:int,events_30d:int,churn_risk:float,anomaly_flag:bool,computed_at:string}|null
	 */
	public static function get_for_user( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( ! get_option( 'wb_gam_feature_user_intelligence_v1' ) ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_user_intelligence WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'user_id'          => (int) $row['user_id'],
			'engagement_score' => (float) $row['engagement_score'],
			'action_diversity' => (int) $row['action_diversity'],
			'recency_days'     => (int) $row['recency_days'],
			'events_30d'       => (int) $row['events_30d'],
			'churn_risk'       => (float) $row['churn_risk'],
			'anomaly_flag'     => 1 === (int) $row['anomaly_flag'],
			'computed_at'      => (string) $row['computed_at'],
		);
	}
}
