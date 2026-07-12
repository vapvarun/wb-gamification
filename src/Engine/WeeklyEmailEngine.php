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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

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

	/**
	 * Action Scheduler hook that carries the dispatch cursor from page to page.
	 *
	 * @since 1.6.4
	 * @var string
	 */
	private const AS_PAGE_HOOK = 'wb_gam_weekly_email_page';

	/**
	 * Members dispatched per page. Bounds the work a single tick performs.
	 *
	 * @since 1.6.4
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Dispatch lock. WP-Cron re-fires an overdue event on the next page load, so
	 * without this a tick that timed out mid-dispatch starts a SECOND full run and
	 * every already-queued member gets a duplicate weekly email.
	 *
	 * TTL comfortably outlives a full paged run; it is refreshed on every page and
	 * released when the keyset is exhausted.
	 *
	 * @since 1.6.4
	 * @var string
	 */
	private const DISPATCH_LOCK_KEY = 'wb_gam_weekly_email_dispatching';
	private const DISPATCH_LOCK_TTL = 3600;

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register WP-Cron and Action Scheduler hooks for the weekly email pipeline.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch_batch' ) );
		add_action( self::AS_HOOK, array( __CLASS__, 'send_to_user' ) );
		// Paged continuation — each page schedules the next until the keyset drains.
		add_action( self::AS_PAGE_HOOK, array( __CLASS__, 'dispatch_page' ) );
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
	 *
	 * @as-fire-once Weekly cron tick. Each iteration enqueues one job for a
	 *               distinct user_id from the SELECT. The AS handler sends an
	 *               email and does not re-enter dispatch_batch.
	 */
	public static function dispatch_batch(): void {
		if ( ! FeatureFlags::is_enabled( 'weekly_emails' ) ) {
			return;
		}

		if ( ! (int) get_option( self::OPT_ENABLED, 1 ) ) {
			return;
		}

		// Guard against overlapping dispatch. WP-Cron re-fires an overdue event on
		// the next page load, so a tick that timed out part-way through would
		// previously start a SECOND full dispatch — enqueueing a second job per
		// member. send_to_user() has no "already emailed this week" check, so that
		// is a duplicate weekly email to every member who was already queued. The
		// lock is what stops the duplicate, and the per-user dedupe below is the
		// belt to its braces.
		if ( get_transient( self::DISPATCH_LOCK_KEY ) ) {
			return;
		}
		set_transient( self::DISPATCH_LOCK_KEY, 1, self::DISPATCH_LOCK_TTL );

		// Start a fresh weekly run from the top of the keyset.
		self::dispatch_page( 0 );
	}

	/**
	 * Dispatch ONE page of the weekly send, then schedule the next.
	 *
	 * Before 1.6.4 dispatch_batch() ran an unbounded `SELECT DISTINCT user_id`
	 * over the whole ledger, pulled every active member into one PHP array, and
	 * enqueued one Action Scheduler job per member — all inside a single 60-second
	 * WP-Cron tick. At 100k members that is 100,000 INSERTs into
	 * actionscheduler_actions in one request. It times out part-way, leaves the AS
	 * queue flooded, and the un-enqueued remainder is simply lost until next week.
	 * (This plugin has already been bitten by an AS blow-up once — see
	 * ActionSchedulerCleaner's PERF-002 note, 3.6M rows in 40 hours.)
	 *
	 * Now it walks a KEYSET cursor: each page selects the next PAGE_SIZE members
	 * with `user_id > $cursor ORDER BY user_id`, enqueues their sends, and schedules
	 * the next page as its own Action Scheduler job. Work per tick is bounded, the
	 * run resumes across ticks instead of restarting, and a failure loses one page
	 * rather than the whole week.
	 *
	 * Keyset, not OFFSET: an OFFSET of 90,000 makes MySQL walk and discard 90,000
	 * rows to reach the page. `user_id > $cursor` is a range scan on idx_user_created
	 * and costs the same on page 200 as on page 1.
	 *
	 * @since 1.6.4
	 *
	 * @param int $cursor Highest user_id already dispatched. 0 starts a fresh run.
	 *
	 * @as-fire-once One page per invocation. Schedules its own successor only while
	 *               a full page came back, so the chain terminates when drained.
	 */
	public static function dispatch_page( int $cursor = 0 ): void {
		if ( ! FeatureFlags::is_enabled( 'weekly_emails' ) || ! (int) get_option( self::OPT_ENABLED, 1 ) ) {
			return;
		}

		global $wpdb;

		// This query decides WHO gets the weekly email, and it had the same two-clock defect
		// as the digest's content window: p.created_at is written with current_time( 'mysql' )
		// -- site-local -- while DATE_SUB(NOW(), ...) is the DATABASE clock. On a site behind
		// UTC the recipient list was cut against a boundary hours away from the one the member
		// experienced, so members active at the edge of the window were dropped from the send
		// entirely and nobody would ever notice, because a missing email leaves no trace.
		//
		// Fixing the content window (see build_digest) and leaving the audience query on NOW()
		// would have been the worst of both: a correct digest sent to the wrong people.
		$window_start = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 7 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.user_id
				   FROM {$wpdb->prefix}wb_gam_points p
				   LEFT JOIN {$wpdb->prefix}wb_gam_member_prefs mp ON mp.user_id = p.user_id
				  WHERE p.created_at >= %s
				    AND p.user_id > %d
				    AND (mp.notification_mode IS NULL OR mp.notification_mode != 'none')
				  ORDER BY p.user_id ASC
				  LIMIT %d",
				$window_start,
				$cursor,
				self::PAGE_SIZE
			)
		);

		if ( empty( $user_ids ) ) {
			delete_transient( self::DISPATCH_LOCK_KEY );
			return;
		}

		$last = $cursor;
		foreach ( $user_ids as $user_id ) {
			$uid  = (int) $user_id;
			$last = max( $last, $uid );

			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				// No Action Scheduler — send inline. Fine for a small site; a large
				// one always has AS (it ships with this plugin).
				self::send_to_user( $uid );
				continue;
			}

			// Don't stack a second send on a member who already has one pending.
			if (
				function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::AS_HOOK, array( 'user_id' => $uid ), self::AS_GROUP )
			) {
				continue;
			}

			as_enqueue_async_action(
				self::AS_HOOK,
				array( 'user_id' => $uid ),
				self::AS_GROUP
			);
		}

		// A short page means the keyset is exhausted — the run is done.
		if ( count( $user_ids ) < self::PAGE_SIZE ) {
			delete_transient( self::DISPATCH_LOCK_KEY );
			return;
		}

		// More to go. Hand the next page to Action Scheduler rather than looping
		// here, so no single request carries the whole site.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 60,
				self::AS_PAGE_HOOK,
				array( 'cursor' => $last ),
				self::AS_GROUP
			);
			// Keep the lock alive across the chain.
			set_transient( self::DISPATCH_LOCK_KEY, 1, self::DISPATCH_LOCK_TTL );
			return;
		}

		// No AS: continue inline. Bounded by PAGE_SIZE per iteration.
		self::dispatch_page( $last );
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

		// Worker-time re-check of the opt-out state. The batch SQL at
		// `dispatch_batch` already filters `notification_mode != 'none'`
		// at enqueue, but the AS queue can run minutes later — a user
		// who clicks the unsubscribe link in that window must NOT receive
		// the recap that was already enqueued. Cheap PK lookup; SELECT
		// cost is negligible compared to wp_mail.
		// Closes audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G11.
		global $wpdb;
		$mode = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT notification_mode FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
				$user_id
			)
		);
		if ( 'none' === $mode ) {
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
					'user_id'   => $user_id,
					'recipient' => $user->user_email,
					'subject'   => $subject,
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
		// The digest window MUST be expressed in the clock its columns are stored in.
		//
		// $since feeds three queries below -- points.created_at, user_badges.earned_at and
		// challenge_log.completed_at -- and all three are written with current_time( 'mysql' ),
		// i.e. SITE-LOCAL. This boundary was gmdate(), i.e. UTC. So the "last 7 days" window was
		// skewed by the site's UTC offset in every digest: a US site silently dropped the most
		// recent hours of activity from the email, and a site ahead of UTC included hours that
		// belonged to the previous week.
		//
		// Third instance of this class in this plugin (after the leaderboard snapshot and the
		// kudos cooldown), which is why it is now a portfolio-wide static rule rather than a
		// thing we keep rediscovering by hand.
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 7 * DAY_IN_SECONDS ) );

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

		// Email subject markers — Unicode emojis stripped per the
		// Lucide-only rule. Subject lines can't render CSS-driven icons,
		// so we use textual modifiers instead.
		if ( $data['is_best'] ) {
			$template .= ' — ' . __( 'Personal best!', 'wb-gamification' );
		} elseif ( $data['streak']['current_streak'] >= 7 ) {
			$template .= ' — ' . __( 'Streak active', 'wb-gamification' );
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
