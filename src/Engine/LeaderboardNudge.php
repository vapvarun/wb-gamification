<?php
/**
 * WB Gamification — Weekly Leaderboard Nudge
 *
 * Every Monday morning, sends each active member a private nudge showing
 * their rank for the week and how many points separate them from the next
 * position. The message is intentionally private and positive — never
 * public shaming. Users in opt-out are skipped.
 *
 * Delivery:
 *   1. BuddyPress notification (if BP active)
 *   2. wp_mail email (if wb_gam_nudge_email = 1, default 0)
 *   3. Always fires `wb_gam_weekly_nudge` for custom integrations.
 *
 * Architecture:
 *   - Weekly cron schedules one AS job per active user to avoid request timeout.
 *   - Each AS job calls self::send_nudge($user_id) — isolated, retryable.
 *   - Only users who earned at least 1 point in the current week are processed.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Sends weekly leaderboard rank nudges to active members via BuddyPress notification and optional email.
 *
 * @package WB_Gamification
 */
final class LeaderboardNudge {

	const CRON_HOOK      = 'wb_gam_weekly_nudge';
	const AS_SINGLE_HOOK = 'wb_gam_nudge_single_user';
	const CRON_RECUR     = 'weekly';

	// ── Lifecycle ───────────────────────────────────────────────────────────────

	/**
	 * Register cron and Action Scheduler callbacks, and schedule the weekly cron if needed.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch_batch' ) );
		add_action( self::AS_SINGLE_HOOK, array( __CLASS__, 'send_nudge' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for next Monday at 08:00 UTC.
			$next_monday = strtotime( 'next monday 08:00 UTC' );
			wp_schedule_event( $next_monday, self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the weekly nudge cron on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'next monday 08:00 UTC' ), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the weekly nudge cron on plugin deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// ── Batch dispatch ──────────────────────────────────────────────────────────

	/**
	 * Queue one AS job per active user (users with points this week).
	 * Called by cron hook — runs quickly; actual nudges processed async.
	 */
	public static function dispatch_batch(): void {
		global $wpdb;

		// Users who earned at least 1 point this week, not opted out.
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.user_id
				   FROM {$wpdb->prefix}wb_gam_points p
				  WHERE p.created_at >= %s
				    AND p.user_id NOT IN (
				        SELECT user_id FROM {$wpdb->prefix}wb_gam_member_prefs
				         WHERE leaderboard_opt_out = 1
				    )
				  LIMIT 5000",
				$week_start
			)
		);

		if ( empty( $user_ids ) ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					self::AS_SINGLE_HOOK,
					array( (int) $user_id ),
					'wb-gamification-nudge'
				);
			} else {
				// Fallback: run inline (fine for small sites).
				self::send_nudge( (int) $user_id );
			}
		}
	}

	// ── Single-user nudge ───────────────────────────────────────────────────────

	/**
	 * Send a weekly rank nudge to one user.
	 * Action Scheduler callback — isolated so one failure doesn't block others.
	 *
	 * @param int $user_id User to nudge.
	 */
	public static function send_nudge( int $user_id ): void {
		$rank_data = LeaderboardEngine::get_user_rank( $user_id, 'week' );

		$rank           = $rank_data['rank'];
		$points         = $rank_data['points'];
		$points_to_next = $rank_data['points_to_next'];

		// Skip users with no weekly points (already filtered in dispatch, but guard here too).
		if ( $points <= 0 ) {
			return;
		}

		/**
		 * Fires before a weekly leaderboard nudge is sent.
		 * Return false to skip sending for this user.
		 *
		 * @param bool  $should_send Whether to send. Default true.
		 * @param int   $user_id     User being nudged.
		 * @param array $rank_data   { rank, points, points_to_next }
		 */
		if ( ! (bool) apply_filters( 'wb_gam_should_send_weekly_nudge', true, $user_id, $rank_data ) ) {
			return;
		}

		$message = self::build_message( $user_id, $rank, $points, $points_to_next );

		/**
		 * Filter the leaderboard-nudge message body before send.
		 *
		 * Use to localise / re-tone / append custom CTAs without
		 * subclassing the engine. Receives the rendered string plus
		 * full context.
		 *
		 * @param string   $message        Default message body.
		 * @param int      $user_id        User being nudged.
		 * @param int      $rank           Current weekly rank.
		 * @param int      $points         Points earned this week.
		 * @param int|null $points_to_next Points needed for next rank (null = #1).
		 */
		$message = (string) apply_filters(
			'wb_gam_nudge_message',
			$message,
			$user_id,
			$rank,
			$points,
			$points_to_next
		);

		// BuddyPress notification.
		if ( function_exists( 'bp_notifications_add_notification' ) ) {
			bp_notifications_add_notification(
				array(
					'user_id'           => $user_id,
					'item_id'           => $rank,
					'secondary_item_id' => (int) $points_to_next,
					'component_name'    => 'wb_gamification',
					'component_action'  => 'weekly_rank_nudge',
					'date_notified'     => bp_core_current_time(),
					'is_new'            => 1,
				)
			);
		}

		// Optional email.
		if ( (bool) get_option( 'wb_gam_nudge_email', 0 ) ) {
			self::send_email( $user_id, $message );
		}

		/**
		 * Fires after a weekly nudge is sent.
		 *
		 * Custom integrations (Slack, push, SMS) hook in here.
		 *
		 * @param int    $user_id        User who was nudged.
		 * @param int    $rank           User's current weekly rank.
		 * @param int    $points         Points earned this week.
		 * @param int|null $points_to_next Points to overtake the next rank. Null = #1.
		 * @param string $message        Human-readable nudge message.
		 */
		do_action( 'wb_gam_weekly_nudge', $user_id, $rank, $points, $points_to_next, $message );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Build the nudge message string for a user.
	 *
	 * @param int      $user_id        User being nudged (reserved for future personalisation).
	 * @param int      $rank           User's current weekly rank.
	 * @param int      $points         Points earned this week.
	 * @param int|null $points_to_next Points needed to overtake the next rank, or null if already #1.
	 * @return string Human-readable nudge message.
	 */
	private static function build_message( int $user_id, int $rank, int $points, ?int $points_to_next ): string {
		if ( 1 === $rank ) {
			return sprintf(
				/* translators: %d: points earned this week */
				__( "You're #1 on the leaderboard this week with %d points. Keep it up!", 'wb-gamification' ),
				$points
			);
		}

		if ( null !== $points_to_next ) {
			/* translators: 1: rank, 2: points this week, 3: points needed for next rank */
			return sprintf(
				__( "You're #%1\$d this week with %2\$d points. Just %3\$d more points to move up!", 'wb-gamification' ),
				$rank,
				$points,
				$points_to_next
			);
		}

		/* translators: 1: rank, 2: points this week */
		return sprintf(
			__( "You're #%1\$d this week with %2\$d points.", 'wb-gamification' ),
			$rank,
			$points
		);
	}

	/**
	 * Send an email nudge to a user (only when explicitly opted in by admin).
	 *
	 * @param int    $user_id User to email.
	 * @param string $message Nudge message body.
	 */
	private static function send_email( int $user_id, string $message ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$site_name = (string) get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( 'Your weekly community ranking at %s', 'wb-gamification' ),
			$site_name
		);

		// Render the themed template — themes can drop a custom file at
		// {theme}/wb-gamification/emails/leaderboard-nudge.php to override.
		$rank_data = LeaderboardEngine::get_user_rank( $user_id, 'week' );
		$body      = Email::render( 'leaderboard-nudge', array(
			'user'      => $user,
			'name'      => esc_html( (string) $user->display_name ),
			'site_name' => $site_name,
			'site_url'  => home_url( '/' ),
			'message'   => $message,
			'rank'      => isset( $rank_data['rank'] ) ? (int) $rank_data['rank'] : null,
			'points'    => isset( $rank_data['points'] ) ? (int) $rank_data['points'] : 0,
		) );
		// Fallback to plain text if the template wasn't found (e.g. devs
		// deleted the file or filtered the path to '').
		if ( '' === $body ) {
			$body = $message;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . Email::from_header() );
		$sent    = wp_mail( $user->user_email, $subject, $body, $headers );
		if ( ! $sent ) {
			Log::error(
				'LeaderboardNudge: wp_mail returned false.',
				array(
					'user_id'   => $user_id,
					'recipient' => $user->user_email,
				)
			);
		}
	}
}
