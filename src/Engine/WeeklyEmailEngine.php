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
	private const AS_GROUP    = 'wb_gamification_email';
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
			'From: ' . self::from_header(),
		);

		wp_mail( $user->user_email, $subject, $body, $headers );

		/**
		 * Fires after a weekly summary email is sent.
		 *
		 * @param int   $user_id User who received the email.
		 * @param array $data    Summary data.
		 */
		do_action( 'wb_gamification_weekly_email_sent', $user_id, $data );
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

		return compact(
			'points_this_week',
			'is_best',
			'best_week',
			'badges_this_week',
			'challenges_this_week',
			'streak',
			'rank',
			'total_points'
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
	 * @param \WP_User $user WP_User object for the recipient.
	 * @param array    $data Summary data from gather_data().
	 * @return string HTML email body.
	 */
	private static function render_body( \WP_User $user, array $data ): string {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();
		$name      = esc_html( $user->display_name );

		// Unsubscribe URL — sets notification_mode to 'none' via nonce-protected endpoint.
		$unsub_url = esc_url(
			add_query_arg(
				array(
					'wb_gam_unsub' => '1',
					'uid'          => $user->ID,
					'tok'          => wp_hash( 'unsub_' . $user->ID . $user->user_email ),
				),
				home_url()
			)
		);

		$points_line = sprintf(
			/* translators: 1 = points earned, 2 = total points */
			__( 'You earned <strong>%1$s points</strong> this week (total: %2$s pts)', 'wb-gamification' ),
			number_format_i18n( $data['points_this_week'] ),
			number_format_i18n( $data['total_points'] )
		);

		if ( $data['is_best'] ) {
			$points_line .= ' — <strong style="color:#f59e0b;">🏆 ' . esc_html__( 'Personal best!', 'wb-gamification' ) . '</strong>';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $site_name ); ?></title>
<style>
body { margin:0; padding:0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; color:#111827; }
.wrap { max-width:560px; margin:0 auto; }
.header { background:#6c63ff; padding:2rem; text-align:center; }
.header img { height:40px; }
.header h1 { color:#fff; margin:.5rem 0 0; font-size:1.25rem; }
.body { background:#fff; padding:2rem; }
.greeting { font-size:1.0625rem; margin-bottom:1.5rem; }
.stat { padding:1rem; border-radius:8px; background:#f9fafb; margin-bottom:1rem; }
.stat-label { font-size:0.75rem; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; margin:0 0 .25rem; }
.stat-value { font-size:1.25rem; font-weight:700; margin:0; }
.badge-list, .challenge-list { padding-left:1.25rem; margin:.5rem 0 0; }
.badge-list li, .challenge-list li { margin-bottom:.25rem; font-size:.9375rem; }
.streak { display:flex; align-items:center; gap:.5rem; }
.cta { display:block; text-align:center; margin:1.5rem 0; }
.cta a { display:inline-block; padding:.75rem 2rem; background:#6c63ff; color:#fff; border-radius:999px; text-decoration:none; font-weight:600; font-size:.9375rem; }
.footer { padding:1.5rem 2rem; font-size:.8125rem; color:#9ca3af; text-align:center; border-top:1px solid #f3f4f6; }
.footer a { color:#9ca3af; }
hr { border:none; border-top:1px solid #f3f4f6; margin:1rem 0; }
</style>
</head>
<body>
<div class="wrap">
	<div class="header">
		<h1><?php echo esc_html( $site_name ); ?></h1>
	</div>
	<div class="body">
		<p class="greeting">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s = member display name */
					__( 'Hey %s, here\'s your weekly gamification summary:', 'wb-gamification' ),
					'<strong>' . $name . '</strong>'
				)
			);
			?>
		</p>

		<!-- Points -->
		<div class="stat">
			<p class="stat-label">⭐ <?php esc_html_e( 'Points this week', 'wb-gamification' ); ?></p>
			<p class="stat-value"><?php echo wp_kses_post( $points_line ); ?></p>
		</div>

		<!-- Streak -->
		<div class="stat">
			<p class="stat-label">🔥 <?php esc_html_e( 'Activity streak', 'wb-gamification' ); ?></p>
			<p class="stat-value">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1 = current streak, 2 = longest streak */
						__( '%1$d-day streak (best: %2$d days)', 'wb-gamification' ),
						$data['streak']['current_streak'],
						$data['streak']['longest_streak']
					)
				);
				?>
			</p>
		</div>

		<?php if ( null !== $data['rank'] ) : ?>
		<!-- Rank -->
		<div class="stat">
			<p class="stat-label">🏅 <?php esc_html_e( 'Your leaderboard rank', 'wb-gamification' ); ?></p>
			<p class="stat-value">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d = rank number */
						__( '#%d overall', 'wb-gamification' ),
						$data['rank']
					)
				);
				?>
			</p>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $data['badges_this_week'] ) ) : ?>
		<!-- Badges -->
		<div class="stat">
			<p class="stat-label">🎖 <?php esc_html_e( 'Badges earned this week', 'wb-gamification' ); ?></p>
			<ul class="badge-list">
				<?php foreach ( $data['badges_this_week'] as $badge ) : ?>
					<li><strong><?php echo esc_html( $badge['name'] ); ?></strong><?php echo $badge['description'] ? ' — ' . esc_html( $badge['description'] ) : ''; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $data['challenges_this_week'] ) ) : ?>
		<!-- Challenges -->
		<div class="stat">
			<p class="stat-label">🎯 <?php esc_html_e( 'Challenges completed', 'wb-gamification' ); ?></p>
			<ul class="challenge-list">
				<?php foreach ( $data['challenges_this_week'] as $ch ) : ?>
					<li><?php echo esc_html( $ch['title'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<div class="cta">
			<a href="<?php echo esc_url( $site_url ); ?>"><?php esc_html_e( 'Keep the momentum going →', 'wb-gamification' ); ?></a>
		</div>
	</div><!-- .body -->

	<div class="footer">
		<p>
			<?php echo esc_html( $site_name ); ?> &bull;
			<a href="<?php echo esc_url( $unsub_url ); ?>"><?php esc_html_e( 'Unsubscribe from weekly emails', 'wb-gamification' ); ?></a>
		</p>
	</div>
</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build the From header string for the weekly email.
	 *
	 * @return string Formatted "Name <email>" header value.
	 */
	private static function from_header(): string {
		$name  = get_option( 'wb_gam_weekly_email_from_name', get_bloginfo( 'name' ) );
		$email = get_option( 'admin_email' );
		return sprintf( '%s <%s>', $name, $email );
	}
}
