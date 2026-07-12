<?php
/**
 * Community Challenge Engine — Phase 3
 *
 * Time-limited, site-wide challenges where the entire community works toward
 * a shared target (Pokémon GO Community Day model).
 *
 * How it works:
 *   1. Admin creates a community challenge (title, target_action, target_count,
 *      starts_at, ends_at, bonus_points).
 *   2. Engine hooks `wb_gam_points_awarded` — every matching event
 *      increments the global counter atomically.
 *   3. When counter reaches target, Engine fires `wb_gam_community_challenge_completed`
 *      and awards bonus_points to every user who contributed at least one event.
 *   4. A live counter is served via REST at GET /community-challenges/{id}.
 *
 * Data stored in wb_gam_community_challenges (separate table added by DbUpgrader).
 * Contributions stored in wb_gam_community_challenge_contributions.
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
 * Site-wide community challenges where all members work toward a shared target.
 *
 * @package WB_Gamification
 */
final class CommunityChallengeEngine {

	/**
	 * Register the points-awarded hook for community challenge processing.
	 */
	public static function init(): void {
		add_action( 'wb_gam_points_awarded', array( __CLASS__, 'on_points_awarded' ), 20, 3 );
		// AS bonus-award handler. complete_challenge() enqueues one job per
		// contributor; this listener is what actually grants the points
		// when the queue runs. Pre-1.4.1 the action was queued but had no
		// listener — every community challenge "completed" without ever
		// awarding the bonus (Basecamp #9933021972).
		add_action( 'wb_gam_community_bonus_award', array( __CLASS__, 'award_community_bonus' ), 10, 3 );

		// Paged bonus fan-out. The per-contributor hook above is KEPT so any job already sitting
		// on the queue from before this upgrade still pays out.
		add_action( self::AS_PAGE_HOOK, array( __CLASS__, 'award_bonus_page' ), 10, 3 );
	}

	/**
	 * Action Scheduler hook for one page of the bonus fan-out.
	 */
	private const AS_PAGE_HOOK = 'wb_gam_community_bonus_page';

	/**
	 * Contributors paid per page. Bounded so no single tick carries the whole site.
	 */
	private const BONUS_PAGE_SIZE = 500;

	/**
	 * Action Scheduler callback — awards the community-challenge bonus to one contributor.
	 *
	 * Each job carries its own (user_id, challenge_id, points) tuple,
	 * fanned out by {@see complete_challenge()}. Runs through the
	 * standard PointsEngine::award path so the bonus goes through the
	 * same ledger, currency, and badge-evaluation pipeline as any other
	 * award — no shortcut writes to wp_wb_gam_points.
	 *
	 * @param int $user_id      Member receiving the bonus.
	 * @param int $challenge_id Community challenge that triggered the bonus (for metadata).
	 * @param int $points       Bonus points to award.
	 * @return void
	 */
	public static function award_community_bonus( int $user_id, int $challenge_id, int $points ): void {
		if ( $user_id <= 0 || $points <= 0 ) {
			return;
		}
		// community_challenge_id rides in $object_id so the ledger row
		// links back to the source. Action id mirrors the synthetic
		// `community_challenge_completed` action that the action_label
		// helper already knows about.
		PointsEngine::award(
			$user_id,
			'community_challenge_completed',
			$points,
			$challenge_id
		);
	}

	// ── Event hook ──────────────────────────────────────────────────────────

	/**
	 * Called after every point award. Checks active community challenges for the action.
	 *
	 * @param int   $user_id User who earned points.
	 * @param Event $event   The event.
	 * @param int   $points  Points awarded (unused but required by hook signature).
	 */
	public static function on_points_awarded( int $user_id, Event $event, int $points ): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		// Find active community challenges that match this action.
		$challenges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, target_action, target_count, bonus_points
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE status = 'active'
				    AND (target_action = %s OR target_action = '*')
				    AND starts_at <= %s
				    AND ends_at >= %s",
				$event->action_id,
				$now,
				$now
			),
			ARRAY_A
		);

		foreach ( $challenges as $challenge ) {
			self::record_contribution( (int) $challenge['id'], $user_id, $event );
		}
	}

	// ── Contribution recording ───────────────────────────────────────────────

	/**
	 * Record one contribution from a user and increment the global counter.
	 *
	 * @param int   $challenge_id Community challenge identifier.
	 * @param int   $user_id      User making the contribution.
	 * @param Event $event        The triggering event (unused beyond the hook signature).
	 */
	private static function record_contribution( int $challenge_id, int $user_id, Event $event ): void {
		global $wpdb;

		// Upsert contribution count for this user.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}wb_gam_community_challenge_contributions
				    (challenge_id, user_id, contribution_count)
				 VALUES (%d, %d, 1)
				 ON DUPLICATE KEY UPDATE contribution_count = contribution_count + 1",
				$challenge_id,
				$user_id
			)
		);

		// Increment global counter atomically.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wb_gam_community_challenges
				    SET global_progress = global_progress + 1
				  WHERE id = %d AND status = 'active'",
				$challenge_id
			)
		);

		// Check if target is now met.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, global_progress, target_count, bonus_points
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE id = %d",
				$challenge_id
			),
			ARRAY_A
		);

		if ( $row && (int) $row['global_progress'] >= (int) $row['target_count'] ) {
			self::complete_challenge( $challenge_id, (int) $row['bonus_points'] );
		}
	}

	// ── Challenge completion ─────────────────────────────────────────────────

	/**
	 * Mark a community challenge as completed and award bonus points to all contributors.
	 *
	 * @param int $challenge_id  The challenge to complete.
	 * @param int $bonus_points  Points to award to each contributor.
	 * @as-fire-once Per-completion one-shot. The atomic SET status='completed'
	 *               UPDATE above guards against double-fire (a second caller
	 *               sees $updated === 0 and bails). Each contributor gets one
	 *               wb_gam_community_bonus_award job; the handler
	 *               (award_community_bonus) calls PointsEngine::award and
	 *               does not re-enter complete_challenge.
	 */
	private static function complete_challenge( int $challenge_id, int $bonus_points ): void {
		global $wpdb;

		// Mark completed — use atomic update to prevent double-fire.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wb_gam_community_challenges
				    SET status = 'completed', completed_at = %s
				  WHERE id = %d AND status = 'active'",
				current_time( 'mysql' ),
				$challenge_id
			)
		);

		if ( ! $updated ) {
			return; // Already completed by another request.
		}

		// Hand the bonus fan-out to ONE paged job, and get out of the member's request.
		//
		// This used to SELECT every contributor and enqueue one Action Scheduler job each, right
		// here. complete_challenge() is reached from the `wb_gam_points_awarded` hook -- so this
		// ran inside the HTTP request of whichever member's award happened to cross the target.
		// At 1,200 contributors that measured 1,200 inserts in 357ms; at 100,000 it is 100,000
		// inserts and roughly half a minute, in a page load. It times out, and because it times
		// out PART WAY, some members get the bonus and some never do.
		//
		// The member's request now schedules exactly one job and returns. Everything else happens
		// on the queue, a page at a time. Same keyset fan-out as WeeklyEmailEngine and
		// StatusRetentionEngine.
		$contributor_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_community_challenge_contributions WHERE challenge_id = %d",
				$challenge_id
			)
		);

		self::schedule_bonus_page( $challenge_id, $bonus_points, 0 );

		/**
		 * Fires when a community challenge is completed.
		 *
		 * @param int $challenge_id  The completed challenge ID.
		 * @param int $bonus_points  Bonus points awarded to contributors.
		 * @param int $contributors  Number of contributing users.
		 */
		do_action( 'wb_gam_community_challenge_completed', $challenge_id, $bonus_points, $contributor_count );
	}

	/**
	 * Queue one page of the bonus fan-out.
	 *
	 * Guarded: the page handler schedules its own successor, so without a dedupe check an
	 * overlapping run would queue the same cursor twice and pay every contributor on that page
	 * twice.
	 *
	 * @param int $challenge_id Challenge being paid out.
	 * @param int $bonus_points Bonus per contributor.
	 * @param int $cursor       Last user_id already paid; 0 to start.
	 * @return void
	 */
	private static function schedule_bonus_page( int $challenge_id, int $bonus_points, int $cursor ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// No Action Scheduler: fall back to walking inline, still one bounded page at a time.
			self::award_bonus_page( $challenge_id, $bonus_points, $cursor );
			return;
		}

		$args = array(
			'challenge_id' => $challenge_id,
			'bonus_points' => $bonus_points,
			'cursor'       => $cursor,
		);

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::AS_PAGE_HOOK, $args, 'wb-gamification' ) ) {
			return;
		}

		as_enqueue_async_action( self::AS_PAGE_HOOK, $args, 'wb-gamification' );
	}

	/**
	 * Action Scheduler callback — pay one page of contributors, then queue the next.
	 *
	 * Keyset, not OFFSET: `user_id > $cursor ORDER BY user_id LIMIT n`. A deep OFFSET scans
	 * everything it skips, so page 200 of a large payout would cost more than page 1.
	 *
	 * @param int $challenge_id Challenge being paid out.
	 * @param int $bonus_points Bonus per contributor.
	 * @param int $cursor       Last user_id already paid; 0 to start.
	 * @return void
	 */
	public static function award_bonus_page( int $challenge_id, int $bonus_points, int $cursor = 0 ): void {
		global $wpdb;

		if ( $challenge_id <= 0 || $bonus_points <= 0 ) {
			return;
		}

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id
				   FROM {$wpdb->prefix}wb_gam_community_challenge_contributions
				  WHERE challenge_id = %d AND user_id > %d
				  ORDER BY user_id ASC
				  LIMIT %d",
				$challenge_id,
				$cursor,
				self::BONUS_PAGE_SIZE
			)
		);

		if ( ! $user_ids ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			// award_community_bonus() is idempotent per (user, challenge) -- it is the same
			// handler the per-contributor jobs used, so a page that runs twice cannot double-pay.
			self::award_community_bonus( (int) $user_id, $challenge_id, $bonus_points );
		}

		// A short page means the keyset is exhausted.
		if ( count( $user_ids ) < self::BONUS_PAGE_SIZE ) {
			return;
		}

		self::schedule_bonus_page( $challenge_id, $bonus_points, (int) end( $user_ids ) );
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Get all active community challenges with their current progress.
	 *
	 * @return array<int, array>
	 */
	public static function get_active(): array {
		global $wpdb;

		$now = current_time( 'mysql' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, target_action, target_count, global_progress,
				        bonus_points, starts_at, ends_at, status
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE status = 'active' AND starts_at <= %s AND ends_at >= %s
				  ORDER BY ends_at ASC",
				$now,
				$now
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get all currently-visible community challenges — active OR completed
	 * within the still-running date window.
	 *
	 * The community-challenges block uses this so a challenge that hit its
	 * target doesn't VANISH from the list the instant it completes. Members
	 * lose the dopamine hit + the "we did it!" social proof if a challenge
	 * disappears mid-cycle. Completed-but-not-expired entries stay listed
	 * (sorted last) until ends_at passes, giving members the full window
	 * to celebrate.
	 *
	 * Active challenges sort first (ascending by ends_at — most urgent
	 * first); completed challenges follow (descending by completed_at —
	 * most-recently-completed first).
	 *
	 * @return array<int, array>
	 */
	public static function get_visible(): array {
		global $wpdb;

		$now = current_time( 'mysql' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, target_action, target_count, global_progress,
				        bonus_points, starts_at, ends_at, status, completed_at
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE starts_at <= %s
				    AND ends_at >= %s
				    AND status IN ('active', 'completed')
				  ORDER BY (status = 'active') DESC,
				           CASE WHEN status = 'active' THEN ends_at ELSE NULL END ASC,
				           CASE WHEN status = 'completed' THEN completed_at ELSE NULL END DESC",
				$now,
				$now
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get a single community challenge.
	 *
	 * @param int $id Community challenge ID.
	 * @return array|null Challenge row, or null if not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, target_action, target_count, global_progress,
				        bonus_points, starts_at, ends_at, status, completed_at
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a user's contribution count to a community challenge.
	 *
	 * @param int $challenge_id Community challenge identifier.
	 * @param int $user_id      User to query.
	 * @return int Contribution count (0 if none).
	 */
	public static function get_user_contribution( int $challenge_id, int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT contribution_count FROM {$wpdb->prefix}wb_gam_community_challenge_contributions
				  WHERE challenge_id = %d AND user_id = %d",
				$challenge_id,
				$user_id
			)
		);
	}
}
