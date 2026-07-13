<?php
/**
 * WB Gamification Badge Engine
 *
 * Evaluates badge conditions after every point award and auto-awards
 * badges when conditions are met.
 *
 * Condition types (stored as JSON in wb_gam_rules.rule_config):
 *
 *   point_milestone  — user's cumulative points >= threshold
 *     { "condition_type": "point_milestone", "points": 100 }
 *
 *   action_count     — user has performed action >= N times
 *     { "condition_type": "action_count", "action_id": "wp_publish_post", "count": 10 }
 *
 *   admin_awarded    — manual only; never auto-evaluates
 *     { "condition_type": "admin_awarded" }
 *
 * Custom condition types can be registered via the
 * `wb_gam_badge_condition` filter.
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
 * Evaluates badge conditions and awards badges when conditions are met.
 *
 * @package WB_Gamification
 */
final class BadgeEngine {

	private const CACHE_GROUP = 'wb_gamification';

	/**
	 * Daily pass for conditions no award can change (tenure).
	 */
	public const CRON_PASS = 'wb_gam_badge_cron_pass';

	/**
	 * Retroactive backfill for one badge.
	 */
	public const BACKFILL_HOOK = 'wb_gam_badge_backfill_page';

	/**
	 * Members evaluated per backfill tick.
	 */
	private const BACKFILL_PAGE_SIZE = 500;

	/**
	 * Members evaluated per cron tick. Keyset-paged: no single tick carries the site.
	 */
	private const CRON_PAGE_SIZE = 500;

	/**
	 * Arm the daily badge pass, and retire the engine cron it replaces.
	 *
	 * @return void
	 */
	public static function maybe_schedule_cron_pass(): void {
		// TenureBadgeEngine's cron is gone with the engine. Clear it, or it stays scheduled forever
		// on every existing site, firing a hook nothing listens to.
		wp_clear_scheduled_hook( 'wb_gam_tenure_check' );

		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Guarded, per the AS-schedule contract: re-arming on every init must never stack duplicates.
		if ( ! as_has_scheduled_action( self::CRON_PASS, array(), 'wb-gamification' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CRON_PASS, array(), 'wb-gamification' );
		}
	}

	/**
	 * Deactivation hook — clear the recurring daily badge cron pass.
	 *
	 * Mirrors LeaderboardEngine::deactivate(). Without this, the RECURRING
	 * Action Scheduler action armed by maybe_schedule_cron_pass() keeps
	 * rescheduling itself forever after the plugin is deactivated — AS
	 * actions are not tied to plugin lifecycle the way WP-Cron events
	 * cleared via register_deactivation_hook are, so nothing stops it
	 * unless we explicitly unschedule it here.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_PASS, array(), 'wb-gamification' );
		}
		// Legacy WP-Cron event predating the Action Scheduler migration (see
		// maybe_schedule_cron_pass()) — cleared here too in case a site is
		// deactivating before ever reaching init on this version.
		wp_clear_scheduled_hook( 'wb_gam_tenure_check' );
	}

	/**
	 * Start a retroactive backfill -- ONLY when the owner asked for one.
	 *
	 * This is never automatic. Awarding a badge to ten thousand members who qualified years ago is
	 * a product decision the SITE OWNER makes, not something the plugin does to their community
	 * behind their back. Plenty of owners launch a badge deliberately "from today onwards", and
	 * a surprise flood of notifications is not a feature.
	 *
	 * @param string $badge_id Badge to backfill.
	 * @return void
	 */
	public static function start_backfill( string $badge_id ): void {
		if ( '' === $badge_id ) {
			return;
		}

		global $wpdb;

		// Reset progress. The owner will watch this number, so it has to start honest.
		update_option(
			'wb_gam_backfill_' . $badge_id,
			array(
				'checked'    => 0,
				'awarded'    => 0,
				'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
				'started_at' => current_time( 'mysql' ),
				'done'       => false,
			),
			false
		);

		self::schedule_backfill_page( $badge_id, 0 );
	}

	/**
	 * Award one page of members who already qualify.
	 *
	 * DRIVEN FROM wp_users, NOT wb_gam_user_totals.
	 *
	 * The design spec said to drive this from wb_gam_user_totals, on the reasoning that "a member
	 * with no points cannot satisfy any auto-condition". That is FALSE, and it took a tenure badge
	 * to see it: a member with zero points absolutely satisfies "has been a member for 365 days" --
	 * they joined a year ago and never did anything. On the live site that would have silently
	 * skipped 18 members, and on a real community it is every lurker who has been around for years.
	 * The bounded-looking driving set was the wrong one.
	 *
	 * @param string $badge_id Badge being backfilled.
	 * @param int    $cursor   Last user id processed.
	 * @return void
	 */
	public static function backfill_page( string $badge_id, int $cursor = 0 ): void {
		global $wpdb;

		$rule = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rule_config FROM {$wpdb->prefix}wb_gam_rules
				  WHERE rule_type = 'badge_condition' AND target_id = %s AND is_active = 1",
				$badge_id
			)
		);

		$config = json_decode( (string) $rule, true );

		if ( ! is_array( $config ) || ! BadgeRule::is_valid( $config ) ) {
			self::finish_backfill( $badge_id );
			return;
		}

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
				$cursor,
				self::BACKFILL_PAGE_SIZE
			)
		);

		if ( ! $user_ids ) {
			self::finish_backfill( $badge_id );
			return;
		}

		$progress = (array) get_option( 'wb_gam_backfill_' . $badge_id, array() );
		$awarded  = (int) ( $progress['awarded'] ?? 0 );
		$checked  = (int) ( $progress['checked'] ?? 0 );

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			++$checked;

			if ( self::has_badge( $user_id, $badge_id ) ) {
				continue;
			}

			$state = array(
				'total'  => PointsEngine::get_total( $user_id, null ),
				'earned' => self::get_user_earned_badge_ids( $user_id ),
				'streak' => null,
			);

			// No $event. The rule is evaluated against the member's STATE -- which is exactly why
			// the evaluator never lets condition logic touch the event.
			if ( ! self::evaluate_rule( $user_id, $config, null, $state ) ) {
				continue;
			}

			// Through award_badge(), so max_earners still holds. A backfill of a "first to reach
			// Champion" badge over ten thousand qualifying members must still produce exactly one
			// winner -- and it does, because scarcity is enforced under a lock in one place.
			if ( self::award_badge( $user_id, $badge_id ) ) {
				++$awarded;
			}
		}

		$progress['checked'] = $checked;
		$progress['awarded'] = $awarded;
		update_option( 'wb_gam_backfill_' . $badge_id, $progress, false );

		if ( count( $user_ids ) < self::BACKFILL_PAGE_SIZE ) {
			self::finish_backfill( $badge_id );
			return;
		}

		self::schedule_backfill_page( $badge_id, (int) end( $user_ids ) );
	}

	/**
	 * Mark a backfill complete.
	 *
	 * @param string $badge_id Badge.
	 * @return void
	 */
	private static function finish_backfill( string $badge_id ): void {
		$progress         = (array) get_option( 'wb_gam_backfill_' . $badge_id, array() );
		$progress['done'] = true;
		update_option( 'wb_gam_backfill_' . $badge_id, $progress, false );
	}

	/**
	 * Queue the next backfill page.
	 *
	 * @param string $badge_id Badge.
	 * @param int    $cursor   Last user id processed.
	 * @return void
	 */
	private static function schedule_backfill_page( string $badge_id, int $cursor ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::backfill_page( $badge_id, $cursor );
			return;
		}

		$args = array(
			'badge_id' => $badge_id,
			'cursor'   => $cursor,
		);

		// The handler schedules its own successor, so guard it -- an overlapping run would walk the
		// same page twice. Awarding is idempotent, but the progress counter would double-count and
		// the owner would watch a number that lies.
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::BACKFILL_HOOK, $args, 'wb-gamification' ) ) {
			return;
		}

		as_enqueue_async_action( self::BACKFILL_HOOK, $args, 'wb-gamification' );
	}

	/**
	 * Backfill progress for the admin screen.
	 *
	 * @param string $badge_id Badge.
	 * @return array{checked:int,awarded:int,total:int,done:bool}|null
	 */
	public static function backfill_progress( string $badge_id ): ?array {
		$progress = get_option( 'wb_gam_backfill_' . $badge_id );

		return is_array( $progress ) ? $progress : null;
	}

	/**
	 * Forget the cached rules list.
	 */
	public static function flush_rules_cache(): void {
		wp_cache_delete( 'wb_gam_badge_rules', self::CACHE_GROUP );
	}

	/**
	 * Every active badge rule, object-cached.
	 *
	 * Extracted from evaluate_on_award() because the daily cron pass needs exactly the same list,
	 * and a second copy of this query would be a second place to forget the cache.
	 *
	 * @return array<int,array{badge_id:string,rule_config:string}>
	 */
	public static function get_active_rules(): array {
		global $wpdb;

		$rules = wp_cache_get( 'wb_gam_badge_rules', self::CACHE_GROUP );

		if ( false === $rules ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rules = $wpdb->get_results(
				"SELECT target_id AS badge_id, rule_config
				   FROM {$wpdb->prefix}wb_gam_rules
				  WHERE rule_type = 'badge_condition' AND is_active = 1",
				ARRAY_A
			) ?: array();

			wp_cache_set( 'wb_gam_badge_rules', $rules, self::CACHE_GROUP, 300 ); // 5 min TTL.
		}

		return (array) $rules;
	}

	/**
	 * Daily pass: evaluate the badges that only the calendar can complete.
	 *
	 * Keyset-paged over members, one Action Scheduler job per page, each scheduling its successor.
	 * TenureBadgeEngine walked every user on the site in a single cron tick; at 100k members that is
	 * the fan-out this branch has spent the day removing everywhere else.
	 *
	 * @param int $cursor Last user id processed.
	 * @return void
	 */
	public static function run_cron_pass( int $cursor = 0 ): void {
		global $wpdb;

		// Only rules that answer to `cron` at all -- which today means tenure. If an owner has no
		// tenure badges, this costs one query and stops.
		$rules = array_filter(
			self::get_active_rules(),
			static function ( $rule ) {
				$config = json_decode( (string) $rule['rule_config'], true );
				return is_array( $config ) && BadgeRule::is_relevant( $config, array( 'cron' ) );
			}
		);

		if ( ! $rules ) {
			return;
		}

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
				$cursor,
				self::CRON_PAGE_SIZE
			)
		);

		if ( ! $user_ids ) {
			return;
		}

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$earned  = self::get_user_earned_badge_ids( $user_id );
			$total   = PointsEngine::get_total( $user_id, null );

			self::evaluate_for_signals( $user_id, array( 'cron' ), null, $rules, $earned, $total );
		}

		if ( count( $user_ids ) < self::CRON_PAGE_SIZE ) {
			return; // Short page: done.
		}

		self::schedule_cron_page( (int) end( $user_ids ) );
	}

	/**
	 * Queue the next page of the daily pass.
	 *
	 * Guarded: the handler schedules its own successor, so without a dedupe check an overlapping run
	 * would queue the same cursor twice.
	 *
	 * @param int $cursor Last user id processed.
	 * @return void
	 */
	private static function schedule_cron_page( int $cursor ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::run_cron_pass( $cursor );
			return;
		}

		$args = array( 'cursor' => $cursor );

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::CRON_PASS, $args, 'wb-gamification' ) ) {
			return;
		}

		as_enqueue_async_action( self::CRON_PASS, $args, 'wb-gamification' );
	}
	private const CACHE_TTL = 60; // Seconds.

	/**
	 * Object-cache key + TTL for the badge rarity map.
	 *
	 * @since 1.6.4
	 */
	private const RARITY_CACHE_KEY = 'wb_gam_badge_rarity_map';
	private const RARITY_CACHE_TTL = 900; // 15 minutes.

	/**
	 * Boot — hook into the points-awarded action to evaluate conditions.
	 */
	public static function init(): void {
		add_action( 'wb_gam_points_awarded', array( __CLASS__, 'evaluate_on_award' ), 10, 3 );

		// Conditions that no award can ever change still have to be evaluated by SOMETHING.
		// tenure_days is the only one today: it moves with the calendar, not with anything a member
		// does. This daily pass is what TenureBadgeEngine's cron used to be -- except it now
		// evaluates whatever tenure rules the OWNER has configured, instead of a hardcoded list of
		// four the owner could not see.
		add_action( self::CRON_PASS, array( __CLASS__, 'run_cron_pass' ), 10, 1 );
		add_action( self::BACKFILL_HOOK, array( __CLASS__, 'backfill_page' ), 10, 2 );

		// Arm the daily pass. AS is not up until init.
		if ( did_action( 'init' ) ) {
			self::maybe_schedule_cron_pass();
		} else {
			add_action( 'init', array( __CLASS__, 'maybe_schedule_cron_pass' ) );
		}

		// Awarding a badge changes every badge's rarity denominator-share, so the
		// cached map is dropped on award. Priority 5 = before the display-side
		// listeners, so anything rendering in the same request recomputes fresh.
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'flush_rarity_cache' ), 5, 0 );
	}

	/**
	 * Map of badge_id → rarity percentage (% of members holding it).
	 *
	 * CACHED, because rarity is a COSMETIC DISPLAY STAT. "Held by 3% of members"
	 * being a few minutes stale harms nobody, and nothing in the plugin branches
	 * on it.
	 *
	 * That distinction is the entire fix. The pre-1.6.4 code refused to cache this
	 * — its comment said "not suitable for generic caching" — because it conflated
	 * rarity with the `max_earners` guard below. They are not the same thing:
	 *
	 *   - `max_earners` MUST be live. A stale count over-awards a limited badge:
	 *     "first 100 members" would hand out 130. It stays uncached (see
	 *     award_badge()), and idx_badge_id now makes it a ref lookup rather than
	 *     a full scan.
	 *   - Rarity is decoration. It may be stale. It must not cost a full scan of
	 *     an EVENT-scaled table on every request.
	 *
	 * And it WAS every request: `GET /badges` and `GET /badges/{id}` are both
	 * `permission_callback => '__return_true'`, so any anonymous visitor — or bot —
	 * could trigger a `COUNT(DISTINCT user_id) GROUP BY badge_id` over millions of
	 * rows, plus a `COUNT(*)` over wp_users, as often as they liked.
	 *
	 * Correctness is preserved two ways: the cache is dropped on every badge award
	 * (see init()) and on the delete paths, and the TTL means even a missed
	 * invalidation self-heals within 15 minutes.
	 *
	 * @since 1.6.4
	 *
	 * @return array<string, float> badge_id => percentage of members holding it.
	 */
	public static function get_rarity_map(): array {
		$cached = wp_cache_get( self::RARITY_CACHE_KEY, 'wb_gamification' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached immediately below.
		$total_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		if ( $total_users <= 0 ) {
			return array();
		}

		// GROUP BY badge_id is served directly by idx_badge_id (no temp table, no
		// filesort). It is still an index scan — an aggregate has to touch every
		// row — which is precisely why the RESULT is cached instead of recomputed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- cached immediately below.
		$rows = $wpdb->get_results(
			"SELECT badge_id, COUNT(DISTINCT user_id) AS earner_count
			   FROM {$wpdb->prefix}wb_gam_user_badges
			  GROUP BY badge_id",
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['badge_id'] ] = round( ( (int) $row['earner_count'] / $total_users ) * 100, 1 );
		}

		wp_cache_set( self::RARITY_CACHE_KEY, $map, 'wb_gamification', self::RARITY_CACHE_TTL );

		return $map;
	}

	/**
	 * Drop the cached rarity map.
	 *
	 * Must be called from every path that inserts into or deletes from
	 * wb_gam_user_badges — award (hooked in init()), an admin deleting a badge
	 * definition, and a GDPR erase. A cache whose invalidation misses a write path
	 * is just a slower way to serve wrong data.
	 *
	 * @since 1.6.4
	 */
	public static function flush_rarity_cache(): void {
		wp_cache_delete( self::RARITY_CACHE_KEY, 'wb_gamification' );
	}

	// ── Award pipeline ─────────────────────────────────────────────────────────

	/**
	 * Evaluate all badge conditions after a point award.
	 *
	 * Optimised to load all conditions in one query and all earned badge IDs in
	 * one query, so the inner loop runs in-memory without N+1 DB round-trips.
	 *
	 * @param int   $user_id User who just earned points.
	 * @param Event $event   The event that triggered the award.
	 * @param int   $points  Points awarded.
	 */
	public static function evaluate_on_award( int $user_id, Event $event, int $points ): void {
		$rules = self::get_active_rules();

		if ( empty( $rules ) ) {
			return;
		}

		// Load earned badge IDs in one query for in-memory filtering.
		$earned = self::get_user_earned_badge_ids( $user_id );

		// Resolve the currency the triggering event awarded — point_milestone
		// badges must check the SAME currency, otherwise a "100 coins" badge
		// would silently grant on the user's points balance instead.
		//
		// Read `$event->point_type` first (stamped by Engine::process in
		// 1.4.1) — it's the canonical answer. Falls back to metadata for
		// callers that bypass Engine::process (legacy synthetic events),
		// then to empty string (which PointsEngine::get_total treats as
		// "primary currency"). See audit/DATA-FLOW-AWARD-2026-05-27.md §G5/G6.
		$event_type = $event->point_type
			?? ( isset( $event->metadata['point_type'] ) ? (string) $event->metadata['point_type'] : '' );
		$total      = PointsEngine::get_total( $user_id, '' !== $event_type ? $event_type : null );

		// The signals this award actually emitted.
		//
		// THIS IS WHAT MAKES THE AWARD PATH CHEAPER THAN IT WAS. Before, a "publish 10 posts" badge
		// ran a COUNT(*) every time a member reacted to a comment, because evaluate_condition()
		// short-circuited only when the required count was exactly 1. With 35 rules on the live
		// site, 12 of them action_count with count > 1, that is twelve pointless COUNT queries on
		// every single award. The gate deletes them.
		$signals = array( 'points', 'action:' . (string) $event->action_id );

		self::evaluate_for_signals( $user_id, $signals, $event, $rules, $earned, $total );
	}

	/**
	 * Evaluate every rule that COULD have been changed by these signals.
	 *
	 * Split out of evaluate_on_award() because the award path is not the only thing that can move a
	 * badge: a level change, a streak milestone, and the awarding of another badge each emit their
	 * own signals, and each can complete a rule that an award cannot.
	 *
	 * @param int        $user_id User being evaluated.
	 * @param string[]   $signals Signals that just fired.
	 * @param Event|null $event   The triggering event, or null (backfill/cron have none).
	 * @param array      $rules   Active badge rules.
	 * @param string[]   $earned  Badge ids this member already holds (mutated as new ones land).
	 * @param int        $total   Primed point total.
	 * @return void
	 */
	private static function evaluate_for_signals( int $user_id, array $signals, ?Event $event, array $rules, array &$earned, int $total ): void {
		// Shared state, primed once per pass. Six of the eight condition types answer from this and
		// cost zero queries.
		$state = array(
			'total'         => $total,
			'earned'        => $earned,
			'streak'        => null, // lazily read, only if a streak_days condition survives the gate
			// Per-action counts, memoized as they are asked for.
			//
			// `action_count` was the one condition type that went to the database EVERY time it was
			// evaluated, and tiered badges are the normal way people use it: Bronze at 5 comments,
			// Silver at 25, Gold at 100 -- three badges, one action, one member. Every one of them is
			// relevant when that action fires (correctly: the gate cannot know which tier is close),
			// so every one of them ran its own COUNT(*).
			//
			// Measured on the dev site: a steady-state award cost 11 queries. With 20 tiered badges on
			// the same action it cost 31 -- twenty byte-identical COUNT(*) queries, in a row, all
			// asking exactly the same question about exactly the same member, inside a single award.
			// The count cannot change between them: nothing writes to the ledger in the middle of an
			// evaluation pass. Twenty round trips to learn one number.
			//
			// Keyed by action_id, because a member can hold badges on several actions.
			'action_counts' => array(),
		);

		$newly_awarded = array();

		foreach ( $rules as $rule ) {
			if ( in_array( $rule['badge_id'], $earned, true ) ) {
				continue; // Already earned.
			}

			$config = json_decode( (string) $rule['rule_config'], true );
			if ( ! is_array( $config ) || ! BadgeRule::is_valid( $config ) ) {
				continue;
			}

			// THE GATE. If nothing that just happened could have changed this badge's answer, do
			// not ask the question -- and do not pay for the SQL that answering it would cost.
			if ( ! BadgeRule::is_relevant( $config, $signals ) ) {
				continue;
			}

			if ( ! self::evaluate_rule( $user_id, $config, $event, $state ) ) {
				continue;
			}

			if ( self::award_badge( $user_id, $rule['badge_id'] ) ) {
				$earned[]          = $rule['badge_id'];
				$state['earned'][] = $rule['badge_id'];
				$newly_awarded[]   = $rule['badge_id'];
			}
		}

		// A badge can be a condition of another badge. Awarding one emits `badge:{id}`, which may
		// complete a rule that nothing else could -- so cascade, but only over the badges that
		// actually landed. Bounded: each pass can only award badges not yet held, and the set of
		// badges is finite, so this terminates.
		if ( $newly_awarded ) {
			$cascade = array();
			foreach ( $newly_awarded as $badge_id ) {
				$cascade[] = 'badge:' . $badge_id;
			}
			self::evaluate_for_signals( $user_id, $cascade, $event, $rules, $earned, $total );
		}
	}

	/**
	 * Evaluate one grouped rule.
	 *
	 * `$event` is used ONLY by the relevance gate, never by the condition logic -- so this works
	 * with `$event = null`, which is exactly what the retroactive backfill needs: it evaluates a
	 * member's state, not an event that happened to them.
	 *
	 * @param int        $user_id User being evaluated.
	 * @param array      $rule    Grouped rule config.
	 * @param Event|null $event   Unused by conditions; present for filters.
	 * @param array      $state   Primed shared state (total, earned, streak).
	 * @return bool True if the badge should be awarded.
	 */
	public static function evaluate_rule( int $user_id, array $rule, ?Event $event, array &$state ): bool {
		if ( ! BadgeRule::is_valid( $rule ) ) {
			return false; // An empty rule never awards. An empty ALL is vacuously true, and would
							// otherwise hand the badge to every member on the site.
		}

		$mode = BadgeRule::match_mode( $rule );

		// Cheapest first, so a failing in-memory condition kills the badge before any SQL runs.
		// With `all` short-circuiting on the first false, ordering is most of the saving.
		foreach ( BadgeRule::by_cost( BadgeRule::conditions( $rule ) ) as $condition ) {
			$met = self::evaluate_one( (array) $condition, $user_id, $event, $state );

			if ( BadgeRule::MATCH_ALL === $mode && ! $met ) {
				return false; // short-circuit
			}
			if ( BadgeRule::MATCH_ANY === $mode && $met ) {
				return true;  // short-circuit
			}
		}

		return BadgeRule::MATCH_ALL === $mode;
	}

	/**
	 * Evaluate ONE condition.
	 *
	 * Eight types. SIX OF THEM COST ZERO QUERIES once $state is primed -- which is the only reason
	 * multi-condition badges are affordable at all. Naively, 35 badges x 4 conditions, several of
	 * them query-backed, is 120 evaluations per award. That does not survive 100k members.
	 *
	 * @param array      $condition One condition.
	 * @param int        $user_id   User being evaluated.
	 * @param Event|null $event     Triggering event, or null (backfill has none).
	 * @param array      $state     Primed shared state, by reference (streak is read lazily).
	 * @return bool
	 */
	private static function evaluate_one( array $condition, int $user_id, ?Event $event, array &$state ): bool {
		$type = isset( $condition['type'] ) ? (string) $condition['type'] : '';

		switch ( $type ) {

			// ── Free: answered from state already primed for this pass ──────────────────────
			case 'point_milestone':
				return (int) $state['total'] >= (int) ( $condition['points'] ?? 0 );

			case 'level_reached':
				// get_level_for_points() is PURE. (get_level_for_user() is not -- it performs up to
				// two update_user_meta() WRITES on what looks like a read, which is why the award
				// path does not touch it.)
				$level = LevelEngine::get_level_for_points( (int) $state['total'] );
				return $level && (int) ( $level['id'] ?? 0 ) >= (int) ( $condition['level_id'] ?? 0 );

			case 'badge_earned':
				return in_array( (string) ( $condition['badge_id'] ?? '' ), (array) $state['earned'], true );

			case 'tenure_days':
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					return false;
				}
				// user_registered is written by WordPress core in GMT. Compared against the same
				// clock. Mixing this with site-local time is the bug this branch fixed five times.
				$registered = strtotime( (string) $user->user_registered . ' UTC' );
				if ( ! $registered ) {
					return false;
				}
				return (int) floor( ( time() - $registered ) / DAY_IN_SECONDS ) >= (int) ( $condition['days'] ?? 0 );

			case 'admin_awarded':
				return false; // Manual grants only. Never auto-evaluates.

			// ── One indexed lookup, and only when this condition survived the gate ──────────
			case 'streak_days':
				if ( null === $state['streak'] ) {
					// get_streak(), not get_row() -- get_row() is PRIVATE. The seam check that
					// approved this in the plan grepped for the function NAME and never looked at
					// its visibility, which is exactly the kind of half-verification that produces
					// a plan everyone trusts and nobody can build.
					$state['streak'] = (int) ( StreakEngine::get_streak( $user_id )['current_streak'] ?? 0 );
				}
				return (int) $state['streak'] >= (int) ( $condition['days'] ?? 0 );

			// ── One indexed COUNT / range scan ──────────────────────────────────────────────
			case 'action_count':
				$action_id = (string) ( $condition['action_id'] ?? '' );
				$required  = max( 1, (int) ( $condition['count'] ?? 1 ) );
				// No `count === 1` fast path any more. The relevance gate replaced it and does the
				// job properly: this is only reached when the action it names actually fired --
				// whatever the required count is. That fast path is why a "publish 10 posts" badge
				// used to run a COUNT(*) every time someone reacted to a comment.
				//
				// Answered from the pass memo. Tiered badges (Bronze 5 / Silver 25 / Gold 100 on one
				// action) are the ordinary way this condition is used, and every tier is relevant when
				// that action fires -- so each tier used to run its own COUNT(*) for the same member
				// and the same action, in the same pass, and get the same answer. It cannot differ:
				// nothing writes to the ledger between two conditions of one evaluation.
				if ( ! isset( $state['action_counts'][ $action_id ] ) ) {
					$state['action_counts'][ $action_id ] = PointsEngine::get_action_count( $user_id, $action_id );
				}

				return (int) $state['action_counts'][ $action_id ] >= $required;

			case 'points_in_period':
				return self::points_in_period( $user_id, (string) ( $condition['period'] ?? 'week' ) )
					>= (int) ( $condition['points'] ?? 0 );

			default:
				/**
				 * Allow extensions to handle custom badge condition types.
				 *
				 * @since 1.0.0
				 *
				 * @param bool       $result    Whether the condition is met. Default false.
				 * @param string     $type      Condition type string.
				 * @param array      $condition Full condition config.
				 * @param int        $user_id   User being evaluated.
				 * @param Event|null $event     Triggering event, or null.
				 */
				return (bool) apply_filters( 'wb_gam_evaluate_badge_condition', false, $type, $condition, $user_id, $event );
		}
	}

	/**
	 * Points a member earned inside a rolling window.
	 *
	 * CLOCK: wb_gam_points.created_at is written with current_time( 'mysql' ) -- SITE-LOCAL -- so
	 * the window boundary is computed in that same clock. Using gmdate() or NOW() here would
	 * reintroduce, in brand-new code, the exact defect this branch fixed FIVE times: on a site
	 * behind UTC the window silently drops recent activity; ahead of UTC it pulls in activity from
	 * before the window opened. CI stage 2.15 fails the build for an unannotated NOW().
	 *
	 * @param int    $user_id User.
	 * @param string $period  day | week | month.
	 * @return int
	 */
	private static function points_in_period( int $user_id, string $period ): int {
		global $wpdb;

		$windows = array(
			'day'   => DAY_IN_SECONDS,
			'week'  => 7 * DAY_IN_SECONDS,
			'month' => 30 * DAY_IN_SECONDS,
		);
		$window  = $windows[ $period ] ?? ( 7 * DAY_IN_SECONDS );
		$since   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $window );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND created_at >= %s",
				$user_id,
				$since
			)
		);
	}

	// ── Public award / read API ────────────────────────────────────────────────

	/**
	 * Award a badge to a user.
	 *
	 * Idempotent — returns false if the user already holds the badge.
	 * When the badge_def has `validity_days > 0`, sets `expires_at` automatically.
	 *
	 * @param int         $user_id   User to award.
	 * @param string      $badge_id  Badge ID (matches wb_gam_badge_defs.id).
	 * @param string|null $earned_at Optional UTC 'Y-m-d H:i:s' earned date for
	 *                               imports; defaults to now.
	 * @return bool                  True if the badge was newly awarded.
	 */
	public static function award_badge( int $user_id, string $badge_id, ?string $earned_at = null ): bool {
		if ( self::has_badge( $user_id, $badge_id ) ) {
			return false;
		}
		// Importers pass the source's earned date (UTC 'Y-m-d H:i:s') to keep
		// migrated achievements on their real timeline; organic awards default
		// to now.
		$earned_at = ( null !== $earned_at && '' !== $earned_at ) ? $earned_at : current_time( 'mysql' );

		global $wpdb;

		// Compute expiry if the badge_def specifies a validity window.
		$def = self::get_badge_def( $badge_id );

		// Eligibility gate: closes_at — stop awarding after the cutoff date.
		if ( $def && ! empty( $def['closes_at'] ) && gmdate( 'Y-m-d H:i:s' ) >= $def['closes_at'] ) {
			return false;
		}

		// Eligibility gate: max_earners — stop awarding once N members hold it.
		//
		// This has to be ATOMIC and it was not: a bare COUNT-then-INSERT. Two workers both read
		// $earner_count = 0 for a max_earners=1 badge, and both awarded it. UNIQUE(user_id,
		// badge_id) does not save this -- that index stops ONE member holding a badge twice, and
		// says nothing at all about TWO members both being "the first".
		//
		// Proven on a live site: two concurrent awards, and both members ended up holding "First
		// Champion". Serialised on the badge now, so exactly one worker can be inside the
		// count-then-award window at a time.
		//
		// This is the plugin's ONLY scarcity mechanism. SiteFirstBadgeEngine used to hand-roll a
		// second one out of transients and COUNT(*)s -- three stacked guards, none atomic -- and
		// it is gone.
		if ( $def && ! empty( $def['max_earners'] ) ) {
			return Lock::run(
				'badge_scarcity_' . $badge_id,
				static function () use ( $user_id, $badge_id, $earned_at, $def ) {
					global $wpdb;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- live count needed; caching here would cause over-awarding.
					$earner_count = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id = %s",
							$badge_id
						)
					);

					if ( $earner_count >= (int) $def['max_earners'] ) {
						return false;
					}

					return self::award_badge_unguarded( $user_id, $badge_id, $earned_at, $def );
				},
				// Lock declined means another worker is deciding this scarce slot right now.
				// Do not award.
				false
			);
		}

		// Not a scarce badge: there is no slot to contend for, so there is nothing to serialise.
		return self::award_badge_unguarded( $user_id, $badge_id, $earned_at, $def );
	}

	/**
	 * Award the badge, having already passed every eligibility gate.
	 *
	 * Split out of award_badge() so the scarcity lock wraps EXACTLY the window that needs
	 * serialising -- count the holders, then award -- and nothing more. Locking the whole method
	 * would put every badge award on the site in a single queue; locking less than this window is
	 * precisely what let two members both become "the first".
	 *
	 * @param int        $user_id   Member being awarded.
	 * @param string     $badge_id  Badge to award.
	 * @param string     $earned_at MySQL datetime the badge was earned.
	 * @param array|null $def       Badge definition, already resolved by the caller.
	 * @return bool True if this call is the one that awarded it.
	 */
	private static function award_badge_unguarded( int $user_id, string $badge_id, string $earned_at, ?array $def ): bool {
		global $wpdb;

		/**
		 * Filter whether a specific badge should be awarded.
		 *
		 * Return false to prevent this badge from being awarded to this user.
		 * Useful for adding custom eligibility rules beyond the built-in gates.
		 *
		 * @since 1.0.0
		 * @param bool   $should_award Whether to proceed with the award.
		 * @param int    $user_id      User ID.
		 * @param string $badge_id     Badge definition ID.
		 * @param array  $badge_def    Full badge definition array (name, category, etc.).
		 */
		if ( ! (bool) apply_filters( 'wb_gam_should_award_badge', true, $user_id, $badge_id, $def ?? array() ) ) {
			return false;
		}

		$validity   = $def ? (int) ( $def['validity_days'] ?? 0 ) : 0;
		$expires_at = $validity > 0
			? gmdate( 'Y-m-d H:i:s', strtotime( "+{$validity} days" ) )
			: null;

		// Race-safe insert. The has_badge() check above is a cache-backed
		// read — two concurrent callers can both pass that gate with stale
		// cache state and both reach this point. The wb_gam_user_badges
		// table has UNIQUE(user_id, badge_id), so the second writer would
		// otherwise trip a duplicate-key error and pollute debug.log. With
		// INSERT IGNORE the DB is the arbiter: one writer wins (rows=1),
		// the loser silently no-ops (rows=0) and we return false so the
		// awarded-hook only fires once. See PERF-004
		// (audit/PERF-DIAG-2026-05-27.yaml) for the original incident.
		// NULL must be a literal: $wpdb->prepare() coerces a PHP null bound to
		// %s into '' — which non-strict MySQL stores as the zero-date
		// 0000-00-00 00:00:00 in a DATETIME column. Zero-dates fail the
		// `expires_at IS NULL OR expires_at > now` visibility filter, making
		// every awarded badge invisible on all display surfaces (Basecamp
		// 9985131435; shipped broken in 1.5.0–1.5.3 via ef8fb69).
		$badges_table = $wpdb->prefix . 'wb_gam_user_badges';
		if ( null === $expires_at ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix + literal.
			$inserted = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$badges_table}` (user_id, badge_id, earned_at, expires_at) VALUES (%d, %s, %s, NULL)",
					$user_id,
					$badge_id,
					$earned_at
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix + literal.
			$inserted = (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$badges_table}` (user_id, badge_id, earned_at, expires_at) VALUES (%d, %s, %s, %s)",
					$user_id,
					$badge_id,
					$earned_at,
					$expires_at
				)
			);
		}

		if ( $inserted < 1 ) {
			// Two paths land here:
			// 1. Race-loser — another concurrent caller already inserted
			// this badge. INSERT IGNORE no-ops; nothing to log.
			// 2. Genuine DB failure — disk full, schema drift, etc.
			// $wpdb->last_error will be non-empty.
			if ( '' !== (string) $wpdb->last_error ) {
				Log::error(
					'BadgeEngine: failed to insert wb_gam_user_badges row.',
					array(
						'user_id'  => $user_id,
						'badge_id' => $badge_id,
						'wpdb_err' => $wpdb->last_error,
					)
				);
			}
			return false;
		}

		// Bust earned-badges cache.
		wp_cache_delete( "wb_gam_earned_badges_{$user_id}", self::CACHE_GROUP );

		/**
		 * Fires when a member earns a badge.
		 *
		 * @param int        $user_id  User who earned the badge.
		 * @param array|null $def      Badge definition row, or null if not found.
		 * @param string     $badge_id Badge identifier.
		 */
		do_action( 'wb_gam_badge_awarded', $user_id, $def ?? array(), $badge_id );

		/**
		 * Fires after a badge is awarded to a user.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id  User who earned the badge.
		 * @param string $badge_id Badge identifier.
		 */
		do_action( 'wb_gam_after_badge_award', $user_id, $badge_id );

		// Dispatch outbound webhook. Event name MUST match the
		// WebhooksController::VALID_EVENTS allowlist (`'badge_earned'`).
		// Any mismatch silently breaks every Zapier/Make subscriber that
		// signed up for badge events. Was originally fixed in PR #47; the
		// 2026-05-27 stability audit found it had regressed to
		// `badge_awarded`. Canonical: `badge_earned`. Don't change without
		// updating WebhooksController::VALID_EVENTS + an integration journey.
		WebhookDispatcher::dispatch(
			'badge_earned',
			$user_id,
			null,
			0,
			array(
				'badge_id'   => $badge_id,
				'badge_name' => $def ? $def['name'] : $badge_id,
			)
		);

		return true;
	}

	/**
	 * Check whether a user currently holds a badge.
	 *
	 * @param int    $user_id  User to check.
	 * @param string $badge_id Badge identifier.
	 * @return bool
	 */
	public static function has_badge( int $user_id, string $badge_id ): bool {
		return in_array( $badge_id, self::get_user_earned_badge_ids( $user_id ), true );
	}

	/**
	 * Count badges earned by a user.
	 *
	 * Reuses the object-cached `get_user_earned_badge_ids()` so the
	 * leaderboard / member directory adornments don't add a per-user DB
	 * query when the directory renders 20+ rows.
	 *
	 * @since 1.4.0
	 *
	 * @param int $user_id User to look up.
	 * @return int Total number of earned (non-expired) badges.
	 */
	public static function count_user_badges( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		return count( self::get_user_earned_badge_ids( $user_id ) );
	}

	/**
	 * Get all earned badge IDs for a user (single query, object-cache backed).
	 *
	 * @param int $user_id User to look up.
	 * @return string[]   Array of badge_id strings.
	 */
	public static function get_user_earned_badge_ids( int $user_id ): array {
		$cache_key = "wb_gam_earned_badges_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;
		// Exclude expired credentials so has_badge() returns false for expired ones.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT badge_id FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d
				    AND (expires_at IS NULL OR expires_at > %s)",
				$user_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		$ids = array_values( $ids ?: array() );
		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::CACHE_TTL );

		return $ids;
	}

	/**
	 * Warm the earned-badges cache for many users in one query.
	 *
	 * Listing surfaces (BP member directory, leaderboard) call
	 * count_user_badges() / get_user_earned_badge_ids() per row. Without
	 * priming, each row runs its own query. This batch-loads non-expired
	 * badge IDs for the whole page and seeds the exact per-user cache key
	 * those readers use, so each subsequent call is a cache hit. Users with
	 * no badges are primed to an empty array (still a hit). Safe to call
	 * repeatedly.
	 *
	 * @param int[] $user_ids Users to prime.
	 * @return void
	 */
	public static function prime_earned_badges( array $user_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), static fn( $id ) => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now          = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is built from an int count; all values pass through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, badge_id FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE ( expires_at IS NULL OR expires_at > %s )
				    AND user_id IN ( $placeholders )",
				array_merge( array( $now ), $ids )
			)
		);

		$map = array_fill_keys( $ids, array() );
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ][] = (string) $row->badge_id;
		}
		foreach ( $map as $uid => $badge_ids ) {
			wp_cache_set( "wb_gam_earned_badges_{$uid}", array_values( $badge_ids ), self::CACHE_GROUP, self::CACHE_TTL );
		}
	}

	/**
	 * Get earned badges with full definition data for a user.
	 *
	 * @param int $user_id User to look up.
	 * @return array<int, array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, category: string, earned_at: string, expires_at: string|null}>
	 */
	public static function get_user_badges( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.name, b.description, b.image_url,
				        b.is_credential, b.category, ub.earned_at, ub.expires_at
				   FROM {$wpdb->prefix}wb_gam_user_badges ub
				   JOIN {$wpdb->prefix}wb_gam_badge_defs b ON b.id = ub.badge_id
				  WHERE ub.user_id = %d
				    AND (ub.expires_at IS NULL OR ub.expires_at > %s)
				  ORDER BY ub.earned_at DESC",
				$user_id,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'            => $row['id'],
					'name'          => $row['name'],
					'description'   => $row['description'],
					'image_url'     => $row['image_url'] ?: null,
					'is_credential' => (bool) $row['is_credential'],
					'category'      => $row['category'],
					'earned_at'     => $row['earned_at'],
					'expires_at'    => $row['expires_at'] ?: null,
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Repair earned-badge rows whose expires_at holds a zero-date.
	 *
	 * 1.5.0–1.5.3 wrote `0000-00-00 00:00:00` instead of SQL NULL for
	 * never-expiring badges (null passed through $wpdb->prepare() %s — see
	 * the note in award_badge()). Zero-dates fail the visibility filter, so
	 * the badges exist but never display. This restores NULL, or
	 * `earned_at + validity_days` when the badge definition declares a
	 * validity window, then busts the per-user earned-badges caches.
	 *
	 * Idempotent — matching rows only exist while the data is broken. Called
	 * from DbUpgrader::upgrade_to_1_5_4() and `wp wb-gamification doctor --fix`.
	 *
	 * @since 1.5.4
	 *
	 * @return int Number of rows repaired.
	 */
	public static function repair_zero_date_expiry(): int {
		global $wpdb;

		$badges_table = $wpdb->prefix . 'wb_gam_user_badges';
		$defs_table   = $wpdb->prefix . 'wb_gam_badge_defs';

		// Zero-dates sort below any real DATETIME, so `< '1971-01-01'`
		// matches them without a zero-date literal (which servers running
		// NO_ZERO_DATE reject).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot repair; table names from $wpdb->prefix + literal.
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM `{$badges_table}` WHERE expires_at IS NOT NULL AND expires_at < '1971-01-01'"
		);
		if ( empty( $user_ids ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot repair; table names from $wpdb->prefix + literal.
		$repaired = (int) $wpdb->query(
			"UPDATE `{$badges_table}` ub
			   LEFT JOIN `{$defs_table}` bd ON bd.id = ub.badge_id
			    SET ub.expires_at = CASE
			        WHEN bd.validity_days IS NOT NULL AND bd.validity_days > 0
			            THEN DATE_ADD(ub.earned_at, INTERVAL bd.validity_days DAY)
			        ELSE NULL
			    END
			  WHERE ub.expires_at IS NOT NULL AND ub.expires_at < '1971-01-01'"
		);

		foreach ( $user_ids as $uid ) {
			wp_cache_delete( 'wb_gam_earned_badges_' . (int) $uid, self::CACHE_GROUP );
		}

		return $repaired;
	}

	/**
	 * Get the raw earned-badge row including expires_at, regardless of expiry status.
	 * Used by CredentialController to distinguish "never earned" from "expired".
	 *
	 * @param int    $user_id  User to look up.
	 * @param string $badge_id Badge identifier.
	 * @return array{earned_at: string, expires_at: string|null}|null
	 */
	public static function get_badge_row( int $user_id, string $badge_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT earned_at, expires_at FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d AND badge_id = %s",
				$user_id,
				$badge_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a single badge definition.
	 *
	 * @param string $badge_id Badge identifier.
	 * @return array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, validity_days: int|null, closes_at: string|null, max_earners: int|null, category: string}|null
	 */
	public static function get_badge_def( string $badge_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, image_url, is_credential, validity_days, closes_at, max_earners, category
				   FROM {$wpdb->prefix}wb_gam_badge_defs
				  WHERE id = %s",
				$badge_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'            => $row['id'],
			'name'          => $row['name'],
			'description'   => $row['description'],
			'image_url'     => $row['image_url'] ?: null,
			'is_credential' => (bool) $row['is_credential'],
			'validity_days' => isset( $row['validity_days'] ) ? (int) $row['validity_days'] : null,
			'closes_at'     => $row['closes_at'] ?: null,
			'max_earners'   => isset( $row['max_earners'] ) ? (int) $row['max_earners'] : null,
			'category'      => $row['category'],
		);
	}

	/**
	 * Insert a badge definition if it does not already exist.
	 *
	 * Used by importers to materialize a WB badge for each source achievement
	 * before awarding it. Idempotent on `id` — an existing def is left as-is
	 * (a re-run never clobbers admin edits).
	 *
	 * @param array{id:string, name:string, description?:string, image_url?:string, category?:string, is_credential?:bool, validity_days?:int|null, closes_at?:string|null, max_earners?:int|null} $def Definition.
	 * @return bool True if a new def was created.
	 */
	public static function upsert_def( array $def ): bool {
		$id = isset( $def['id'] ) ? (string) $def['id'] : '';
		if ( '' === $id ) {
			return false;
		}
		if ( null !== self::get_badge_def( $id ) ) {
			return false;
		}

		$row     = array(
			'id'            => $id,
			'name'          => isset( $def['name'] ) ? (string) $def['name'] : $id,
			'description'   => isset( $def['description'] ) ? (string) $def['description'] : '',
			'image_url'     => isset( $def['image_url'] ) ? (string) $def['image_url'] : null,
			'category'      => isset( $def['category'] ) ? (string) $def['category'] : 'imported',
			'is_credential' => empty( $def['is_credential'] ) ? 0 : 1,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d' );

		// Award-window columns. Previously omitted entirely, so a programmatic
		// def could never carry an expiry, cutoff or earner cap — the columns
		// existed and every read path honoured them, but no writer set them.
		// `null` (and `0` for the counters) means "no limit" and stores SQL NULL,
		// which `$wpdb->insert()` emits as a literal NULL regardless of format.
		$row['validity_days'] = empty( $def['validity_days'] ) ? null : max( 1, (int) $def['validity_days'] );
		$formats[]            = '%d';
		$row['closes_at']     = empty( $def['closes_at'] ) ? null : (string) $def['closes_at'];
		$formats[]            = '%s';
		$row['max_earners']   = empty( $def['max_earners'] ) ? null : max( 1, (int) $def['max_earners'] );
		$formats[]            = '%d';

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $wpdb->prefix . 'wb_gam_badge_defs', $row, $formats );
		return false !== $inserted;
	}

	/**
	 * Get all badge definitions with earned status for a user.
	 *
	 * Unearned badges are included (greyed-out in UI) so members can see
	 * what to work toward — the "locked but visible" forward motivation model.
	 *
	 * @param int $user_id User whose earned status to check. 0 = skip earned check.
	 * @return array<int, array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, category: string, earned: bool, earned_at: string|null}>
	 */
	public static function get_all_badges_for_user( int $user_id = 0 ): array {
		global $wpdb;

		$defs = $wpdb->get_results(
			"SELECT id, name, description, image_url, is_credential, category
			   FROM {$wpdb->prefix}wb_gam_badge_defs
			  ORDER BY category, name",
			ARRAY_A
		);

		if ( empty( $defs ) ) {
			return array();
		}

		// Build earned-at + expires_at map in one query.
		$now        = gmdate( 'Y-m-d H:i:s' );
		$badge_data = array();
		if ( $user_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT badge_id, earned_at, expires_at FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id = %d",
					$user_id
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$badge_data[ $row['badge_id'] ] = array(
					'earned_at'  => $row['earned_at'],
					'expires_at' => $row['expires_at'],
				);
			}
		}

		return array_map(
			static function ( array $def ) use ( $badge_data, $now ): array {
				$data       = $badge_data[ $def['id'] ] ?? null;
				$earned_at  = $data['earned_at'] ?? null;
				$expires_at = $data['expires_at'] ?? null;
				$is_expired = $expires_at && strtotime( $expires_at ) <= strtotime( $now );
				return array(
					'id'            => $def['id'],
					'name'          => $def['name'],
					'description'   => $def['description'],
					'image_url'     => $def['image_url'] ?: null,
					'is_credential' => (bool) $def['is_credential'],
					'validity_days' => isset( $def['validity_days'] ) ? (int) $def['validity_days'] : null,
					'category'      => $def['category'],
					'earned'        => null !== $earned_at && ! $is_expired,
					'earned_at'     => $earned_at,
					'expires_at'    => $expires_at,
					'is_expired'    => $is_expired,
				);
			},
			$defs
		);
	}
}
