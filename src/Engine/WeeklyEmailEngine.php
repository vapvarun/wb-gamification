<?php
/**
 * WB Gamification Weekly Email Engine
 *
 * Sends each active member a personalised weekly summary every Monday morning.
 *
 * Email content:
 *   - Points earned this week (with "personal best?" callout)
 *   - Current streak + longest streak
 *   - Badges earned this week
 *   - Challenges completed this week
 *   - Leaderboard rank (private, not shared with others)
 *   - One-tap unsubscribe link
 *
 * Architecture:
 *   - WP-Cron job `wb_gam_weekly_email` fires Monday 08:30 UTC.
 *   - Dispatches one Action Scheduler job per user to avoid PHP timeout.
 *   - Each AS job calls `send_to_user()` which builds + sends the email.
 *   - Users with `notification_mode = 'none'` are skipped.
 *   - Respects `leaderboard_opt_out` (omits rank if opted out).
 *
 * Options:
 *   wb_gam_weekly_email_enabled   (default 1)
 *   wb_gam_weekly_email_from_name (default: site name)
 *   wb_gam_weekly_email_subject   (default: "Your week in {site_name}")
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Sends each active member a personalised weekly gamification summary email.
 *
 * @package WB_Gamification
 */
final class WeeklyEmailEngine {

	private const CRON_HOOK   = 'wb_gam_weekly_email';
	private const AS_HOOK     = 'wb_gam_weekly_email_user';
	private const AS_GROUP    = 'wb_gam_email';
	private const OPT_ENABLED = 'wb_gam_weekly_email_enabled';

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register WP-Cron and Action Scheduler hooks for the weekly email pipeline.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch_batch' ) );
		add_action( self::AS_HOOK, array( __CLASS__, 'send_to_user' ) );
	}

	/**
	 * Schedule the weekly email cron event on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Next Monday at 08:30 UTC.
			$next = strtotime( 'next Monday 08:30:00 UTC' );
			wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the weekly email cron event on plugin deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// ── Batch dispatch ──────────────────────────────────────────────────────────

	/**
	 * Fired by WP-Cron. Queues one AS job per eligible user.
	 */
	public static function dispatch_batch(): void {
		if ( ! FeatureFlags::is_pro_active() || ! FeatureFlags::is_enabled( 'weekly_emails' ) ) {
			return;
		}

		if ( ! (int) get_option( self::OPT_ENABLED, 1 ) ) {
			return;
		}

		global $wpdb;

		// Fetch users who earned at least 1 point in the last 7 days
		// AND have not opted out of all notifications.
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT p.user_id
			   FROM {$wpdb->prefix}wb_gam_points p
			   LEFT JOIN {$wpdb->prefix}wb_gam_member_prefs mp ON mp.user_id = p.user_id
			  WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			    AND (mp.notification_mode IS NULL OR mp.notification_mode != 'none')"
		);

		foreach ( $user_ids as $user_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					self::AS_HOOK,
					array( 'user_id' => (int) $user_id ),
					self::AS_GROUP
				);
			} else {
				// Fallback: run inline (fine for small sites).
				self::send_to_user( (int) $user_id );
			}
		}
	}

	// ── Per-user send ────────────────────────────────────────────────────────────

	/**
	 * Build and send the weekly summary email to a single user.
	 *
	 * @param int $user_id User ID to send the email to.
	 */
	public static function send_to_user( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$data = self::gather_data( $user_id );

		// Skip if nothing noteworthy happened this week.
		if ( 0 === $data['points_this_week'] && empty( $data['badges_this_week'] ) ) {
			return;
		}

		$subject = self::render_subject( $data );
		$body    = self::render_body( $user, $data );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Email::from_header( 'wb_gam_weekly_email_from_name' ),
		);

		/**
		 * Filter the weekly email body before send.
		 *
		 * Use to completely replace the rendered HTML, or to inject
		 * additional content. The base body is built by Email::render()
		 * which already supports theme overrides via
		 * YOUR-THEME/wb-gamification/emails/weekly-recap.php — only
		 * use this filter when a template override isn't enough.
		 *
		 * @param string   $body    HTML email body.
		 * @param \WP_User $user    Recipient.
		 * @param array    $data    Summary data.
		 */
		$body = (string) apply_filters( 'wb_gam_weekly_email_body', $body, $user, $data );

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );
		if ( ! $sent ) {
			Log::error(
				'WeeklyEmailEngine: wp_mail returned false.',
				array(
					'user_id'    => $user_id,
					'recipient'  => $user->user_email,
					'subject'    => $subject,
				)
			);
		}

		/**
		 * Fires after a weekly summary email is sent.
		 *
		 * @param int   $user_id User who received the email.
		 * @param array $data    Summary data.
		 */
		do_action( 'wb_gam_weekly_email_sent', $user_id, $data );
	}

	// ── Data gathering ───────────────────────────────────────────────────────────

	/**
	 * Gather all data points needed for the weekly summary email.
	 *
	 * @param int $user_id User ID to gather data for.
	 * @return array Summary data array.
	 */
	private static function gather_data( int $user_id ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		// Points this week.
		$points_this_week = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND created_at >= %s",
				$user_id,
				$since
			)
		);

		// Personal best this week?
		$best_week = (int) get_user_meta( $user_id, 'wb_gam_pr_best_week', true );
		$is_best   = $points_this_week > 0 && $points_this_week >= $best_week;

		// Badges earned this week.
		$badges_this_week = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.name, b.description
				   FROM {$wpdb->prefix}wb_gam_user_badges ub
				   JOIN {$wpdb->prefix}wb_gam_badge_defs b ON b.id = ub.badge_id
				  WHERE ub.user_id = %d AND ub.earned_at >= %s",
				$user_id,
				$since
			),
			ARRAY_A
		) ?: array();

		// Challenges completed this week.
		$challenges_this_week = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.title
				   FROM {$wpdb->prefix}wb_gam_challenge_log cl
				   JOIN {$wpdb->prefix}wb_gam_challenges c ON c.id = cl.challenge_id
				  WHERE cl.user_id = %d AND cl.completed_at >= %s",
				$user_id,
				$since
			),
			ARRAY_A
		) ?: array();

		// Streak.
		$streak = StreakEngine::get_streak( $user_id );

		// Rank (omit if opted out).
		$opt_out = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT leaderboard_opt_out FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
				$user_id
			)
		);
		$rank    = null;
		if ( ! $opt_out ) {
			$rank = LeaderboardEngine::get_user_rank( $user_id );
		}

		// Total points.
		$total_points = PointsEngine::get_total( $user_id );

		// Resolve the configured currency label so the email reads
		// "You earned 240 Coins this week" on coins-default sites.
		$pt_service   = new \WBGam\Services\PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );

		return compact(
			'points_this_week',
			'is_best',
			'best_week',
			'badges_this_week',
			'challenges_this_week',
			'streak',
			'rank',
			'total_points',
			'points_label'
		);
	}

	// ── Rendering ────────────────────────────────────────────────────────────────

	/**
	 * Render the email subject line, adding an emoji for personal-best or streak weeks.
	 *
	 * @param array $data Summary data from gather_data().
	 * @return string Email subject string.
	 */
	private static function render_subject( array $data ): string {
		$template = get_option(
			'wb_gam_weekly_email_subject',
			/* translators: %s = site name */
			sprintf( __( 'Your week in %s', 'wb-gamification' ), get_bloginfo( 'name' ) )
		);

		if ( $data['is_best'] ) {
			$template .= ' 🏆';
		} elseif ( $data['streak']['current_streak'] >= 7 ) {
			$template .= ' 🔥';
		}

		return $template;
	}

	/**
	 * Render the HTML email body for a weekly summary.
	 *
	 * Resolves the template via Email::locate() — themes can override by
	 * dropping a custom template at:
	 *
	 *   YOUR-THEME/wb-gamification/emails/weekly-recap.php
	 *
	 * Or filter `wb_gam_email_template_path` for full
	 * programmatic override.
	 *
	 * @param \WP_User $user WP_User object for the recipient.
	 * @param array    $data Summary data from gather_data().
	 * @return string HTML email body.
	 */
	private static function render_body( \WP_User $user, array $data ): string {
		$vars = array_merge(
			$data,
			array(
				'user'      => $user,
				'name'      => esc_html( $user->display_name ),
				'site_name' => get_bloginfo( 'name' ),
				'site_url'  => home_url(),
				'unsub_url' => esc_url(
					add_query_arg(
						array(
							'wb_gam_unsub' => '1',
							'uid'          => $user->ID,
							'tok'          => wp_hash( 'unsub_' . $user->ID . $user->user_email ),
						),
						home_url()
					)
				),
			)
		);

		return Email::render( 'weekly-recap', $vars );
	}
}
