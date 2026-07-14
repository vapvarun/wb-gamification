<?php
/**
 * Notification Bridge
 *
 * Collects gamification events that happened during this page request and
 * outputs:
 *   1. The Interactivity API–driven toast / overlay markup (once per page).
 *   2. A small inline <script> that seeds window.wbGamNotifications with any
 *      pending events stored in a transient for the current user.
 *
 * Events are written to the transient by hooking the gamification action hooks
 * (badge awarded, level changed, etc.).  They are flushed once — reading the
 * transient deletes it.
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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Bridges gamification events to front-end notifications via transients and Interactivity API.
 *
 * @package WB_Gamification
 */
final class NotificationBridge {

	/**
	 * Maximum events retained per member in the queue. Oldest evict on write.
	 *
	 * Enforced by trim(), which IS the bound on the table — see its docblock.
	 * Until 1.6.4 this capped a parallel transient while the durable table (the
	 * primary read path) appended forever.
	 *
	 * @var int
	 */
	private const QUEUE_MAX_EVENTS = 50;

	/**
	 * Maximum toasts delivered to a member in one read.
	 *
	 * A toast is an ephemeral, realtime surface: it says "this just happened".
	 * Replaying a backlog through it is never the right answer — a member
	 * returning to 30,000 pending events wants the newest few, not 600 page loads
	 * of catch-up. fetch_unseen() returns at most this many NEWEST events and
	 * hands callers the head of the backlog to park their cursor on, so the
	 * remainder is dropped rather than replayed.
	 *
	 * Public because the SSE transport (WBGam\API\SSEController) drives the same
	 * reader with a request-supplied cursor: the cap is a property of the toast
	 * surface, not of one caller.
	 *
	 * @since 1.6.4
	 * @var int
	 */
	public const BURST_MAX_EVENTS = 5;

	/**
	 * Rows deleted per statement by `prune_queue()`, and the number of such
	 * statements one cron tick may run (5,000 x 20 = 100,000 rows/run).
	 *
	 * Before 1.6.4 the prune was a single `LIMIT 5000` DELETE per day with no
	 * loop, so any site producing more than 5,000 prunable rows/day fell
	 * permanently behind and the table grew without limit. Batching in a loop
	 * lets a backlog actually drain; the per-run cap keeps one tick from
	 * locking the table (same shape as BuddyNext's LogRetentionService).
	 *
	 * @since 1.6.4
	 * @var int
	 */
	private const PRUNE_BATCH_SIZE  = 5000;
	private const PRUNE_MAX_BATCHES = 20;
	/**
	 * User-meta key prefix for per-consumer delivery cursors. Each reader
	 * tracks the largest `_id` it has already delivered to the client, so
	 * the next read returns only newer events. Replaces the previous
	 * destructive read-and-delete pattern that lost events whenever two
	 * consumers raced for the same transient.
	 *
	 * @var string
	 */
	private const CURSOR_META_PREFIX = 'wb_gam_notif_cursor_';

	/**
	 * Option holding the admin-chosen toast stack position.
	 *
	 * @var string
	 */
	public const TOAST_POSITION_OPTION = 'wb_gam_toast_position';

	/**
	 * Allowed toast positions. The value maps 1:1 to a
	 * `.wb-gam-toasts--{position}` CSS modifier (see assets/css/frontend.css)
	 * and is validated again client-side in assets/js/toast.js.
	 *
	 * @var string[]
	 */
	public const TOAST_POSITIONS = array( 'top-right', 'top-center', 'bottom-right', 'bottom-left' );

	/**
	 * Default toast position. Bottom-right is the conventional ambient-
	 * notification corner and never overlaps a top nav / sticky header
	 * (the previous top-center default collided with BuddyX's sticky
	 * header — Basecamp #9932190385).
	 *
	 * @var string
	 */
	public const TOAST_POSITION_DEFAULT = 'bottom-right';

	/**
	 * Resolve the configured toast position, validated against the allowed
	 * set. Falls back to the default for an unset or unrecognized value.
	 *
	 * @return string One of self::TOAST_POSITIONS.
	 */
	public static function get_toast_position(): string {
		$position = (string) get_option( self::TOAST_POSITION_OPTION, self::TOAST_POSITION_DEFAULT );

		if ( ! in_array( $position, self::TOAST_POSITIONS, true ) ) {
			$position = self::TOAST_POSITION_DEFAULT;
		}

		/**
		 * Filters the toast stack position before it is sent to the client.
		 *
		 * @since 1.5.2
		 *
		 * @param string $position One of 'top-right', 'top-center',
		 *                         'bottom-right', 'bottom-left'.
		 */
		$position = (string) apply_filters( 'wb_gam_toast_position', $position );

		return in_array( $position, self::TOAST_POSITIONS, true ) ? $position : self::TOAST_POSITION_DEFAULT;
	}

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Daily prune cron hook. Removes notifications older than the retention
	 * window from the durable queue table. Transients still expire via TTL
	 * and don't need an explicit prune.
	 */
	public const PRUNE_CRON = 'wb_gam_notifications_queue_prune';

	/**
	 * Retention window for the durable queue table (seconds). 24 hours is
	 * a balance between "user catches up on toasts after a day away"
	 * (covered) and "table doesn't grow indefinitely" (bounded).
	 */
	public const RETENTION_SECONDS = 86400;

	public static function init(): void {
		// Collect events from action hooks.
		add_action( 'wb_gam_points_awarded', array( __CLASS__, 'on_points_awarded' ), 99, 3 );
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'on_badge_awarded' ), 99, 3 );
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_changed' ), 99, 3 );
		add_action( 'wb_gam_streak_milestone', array( __CLASS__, 'on_streak_milestone' ), 99, 2 );
		add_action( 'wb_gam_challenge_completed', array( __CLASS__, 'on_challenge_completed' ), 99, 2 );
		add_action( 'wb_gam_kudos_given', array( __CLASS__, 'on_kudos_given' ), 99, 4 );

		// ...and take it back when a moderator revokes it. This action was fired and never listened to,
		// so a revoked kudos still congratulated the receiver.
		add_action( 'wb_gam_kudos_revoked', array( __CLASS__, 'on_kudos_revoked' ), 10, 3 );

		// v2.2 — daily prune of the durable queue table.
		add_action( self::PRUNE_CRON, array( __CLASS__, 'prune_queue' ) );

		// Arm the recurring event on init, never at plugins_loaded: wp_schedule_event
		// resolves schedules via wp_get_schedules(), which fires the
		// cron_schedules filter — that must not run before init on WP 6.7+.
		if ( did_action( 'init' ) ) {
			self::maybe_schedule();
		} else {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		}
		// Skip toasts: OFF for every member, on every site, unless the owner asks for them.
		//
		// A member is never told they earned nothing. Their action succeeded -- they posted, they
		// reacted, they commented; the only thing that did not happen is an invisible points
		// increment they never asked about. "You're on cooldown" and "you've hit your daily limit"
		// read as though the action FAILED when it did not, and fire again and again precisely
		// because a capped member keeps being active. So the default is silence, and it stays silence.
		//
		// 1.6.4 briefly went further and deleted the mechanism outright, on the argument that an
		// opt-in lever is not neutral. That was over-reach, and QA was right to bounce it: the
		// `wb_gam_award_skip_toast_reasons` filter was RELEASED in 1.6.3 and documented in its public
		// changelog. Deleting it one patch later does not remove the surface from a site that opted
		// in -- it silently stops their add_filter() from doing anything, with no error and no notice.
		// A published extension point that quietly becomes a no-op is worse than one we disagree with.
		//
		// The default is what protects members (nobody sees a skip toast unless an owner turns one
		// on). The filter is what keeps our word.
		add_action( 'wb_gam_award_skipped', array( __CLASS__, 'on_award_skipped' ), 99, 4 );

		// Output markup + seed script once, in the footer.
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
	}

	/**
	 * Arm the daily queue-prune event if not already scheduled. Idempotent —
	 * safe to call on every init.
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::PRUNE_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_CRON );
		}
	}

	/**
	 * Surface an award-skip to the member as a toast — if, and only if, the owner asked for it.
	 *
	 * @since 1.4.1
	 * @since 1.6.3 Defaults to silence. No skip reason reaches a member unless an owner opts it in
	 *              through `wb_gam_award_skip_toast_reasons`.
	 *
	 * @param int    $user_id   User who would have been awarded.
	 * @param string $action_id Action that was skipped.
	 * @param string $reason    Closed-set reason from PointsEngine::passes_rate_limits().
	 * @param array  $context   Optional context (daily_cap_used, cooldown_seconds, etc.).
	 * @return void
	 */
	public static function on_award_skipped( int $user_id, string $action_id, string $reason, array $context = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		// The ONLY reasons a member may ever be shown. An engine-internal veto (`sandboxed`,
		// `self_action`, `pre_change_veto`, `excluded`) describes a decision the SITE made about the
		// member -- it is not feedback, and it is not the member's business.
		//
		// 1.6.3 promised exactly this in its docblock ("never eligible regardless of this filter")
		// and did not enforce it: an owner who filtered in `sandboxed` got past the in_array() check,
		// fell through the switch with no message, and pushed a toast with an EMPTY body. The promise
		// is now enforced where it is made.
		$eligible = array( 'cooldown', 'daily_cap', 'weekly_cap' );
		if ( ! in_array( $reason, $eligible, true ) ) {
			return;
		}

		/**
		 * Filter which award-skip reasons are surfaced to the member as a toast.
		 *
		 * Defaults to EMPTY -- no skip reason is shown to a member. Gamification is positive
		 * reinforcement: members should only ever see reward toasts (points earned, badge, level up),
		 * never a "you got nothing" message. A cooldown ("try again in a bit"), a daily cap and a
		 * weekly cap all tell the member they earned nothing for normal activity; they are not
		 * actionable, they read as errors, and they demotivate at scale.
		 *
		 * A site owner whose community genuinely wants cap feedback can opt specific reasons back in:
		 *
		 *   add_filter( 'wb_gam_award_skip_toast_reasons', fn() => array( 'daily_cap', 'weekly_cap' ) );
		 *
		 * Engine-internal vetoes are never eligible, whatever this filter returns.
		 *
		 * @since 1.6.3
		 *
		 * @param string[] $reasons   Skip reasons that get a member toast. Default [].
		 * @param int      $user_id   Member who would see the toast.
		 * @param string   $action_id Action that was skipped.
		 */
		$user_facing_reasons = (array) apply_filters(
			'wb_gam_award_skip_toast_reasons',
			array(),
			$user_id,
			$action_id
		);

		if ( ! in_array( $reason, $user_facing_reasons, true ) ) {
			return;
		}

		switch ( $reason ) {
			case 'cooldown':
				$message = __( "You're on cooldown for this action - try again in a bit.", 'wb-gamification' );
				break;
			case 'daily_cap':
				$message = __( "You've hit your daily limit for this action. Resets tomorrow.", 'wb-gamification' );
				break;
			default:
				$message = __( "You've hit your weekly limit for this action. Resets next week.", 'wb-gamification' );
				break;
		}

		self::push(
			$user_id,
			array(
				'type'    => 'skip',
				'reason'  => $reason,
				'action'  => $action_id,
				'message' => $message,
				'context' => $context,
			)
		);
	}

	// ── Event collectors ────────────────────────────────────────────────────────

	/**
	 * Queue a points notification for the user.
	 *
	 * @param int   $user_id User who earned points.
	 * @param Event $event   Source event.
	 * @param int   $points  Points awarded.
	 */
	public static function on_points_awarded( int $user_id, Event $event, int $points ): void {
		// Don't notify for internal synthetic actions (challenge bonus, streak bonus).
		$silent = array( 'challenge_completed', 'streak_milestone' );
		if ( in_array( $event->action_id, $silent, true ) ) {
			return;
		}

		// First-earn explainer — push a one-time welcome toast so the member
		// understands what just happened and where to see their progress.
		// Gated by user_meta so it fires exactly once per user, the first time
		// they earn any points. Hub URL is included in the detail line so they
		// know where to look.
		if ( ! get_user_meta( $user_id, 'wb_gam_seen_first_earn_toast', true ) ) {
			update_user_meta( $user_id, 'wb_gam_seen_first_earn_toast', 1 );

			$hub_page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
			$hub_url     = $hub_page_id ? get_permalink( $hub_page_id ) : '';

			$detail = $hub_url
				? sprintf(
					/* translators: %s: URL to the Gamification Hub page. */
					__( 'See your full progress - points, badges, levels, leaderboard - at %s', 'wb-gamification' ),
					wp_make_link_relative( $hub_url )
				)
				: __( 'Earn more points by being active on the site - every action counts.', 'wb-gamification' );

			self::push(
				$user_id,
				array(
					'type'    => 'welcome',
					'message' => __( 'Welcome - you just earned your first points!', 'wb-gamification' ),
					'detail'  => $detail,
					'icon'    => 'icon-sparkles',
				)
			);
		}

		// Resolve the currency label for this award so the toast string is
		// in sync with the admin-configured point type instead of always
		// reading "+5 points" on an XP / Coins / custom-currency site
		// (Basecamp 9925427545). PointTypeService::resolve() falls back to
		// the primary currency for unknown slugs, so the lookup never
		// throws even when the event predates the multi-currency tables.
		$wb_gam_point_type = '';
		if ( property_exists( $event, 'point_type' ) ) {
			$wb_gam_point_type = (string) $event->point_type;
		}
		if ( '' === $wb_gam_point_type ) {
			$action_def = \WBGam\Engine\Registry::get_action( $event->action_id );
			if ( is_array( $action_def ) ) {
				$wb_gam_point_type = \WBGam\Engine\Registry::resolve_action_point_type( $action_def );
			}
		}
		$pt_service = new \WBGam\Services\PointTypeService();
		$pt_record  = $pt_service->get( $wb_gam_point_type ) ?: $pt_service->get( $pt_service->default_slug() );
		$label      = (string) ( $pt_record['label'] ?? __( 'points', 'wb-gamification' ) );

		$payload = array(
			'type'    => 'points',
			'points'  => $points,
			// action_id travels to the client so toast.js only merges
			// repeats of the SAME action (e.g. "Leave a comment x2") and
			// keeps distinct actions as separate, individually-labeled
			// toasts instead of a meaningless "+N points (M actions)".
			'action'  => $event->action_id,
			'message' => sprintf(
				/* translators: 1: signed point delta, 2: currency label. */
				__( '+%1$d %2$s', 'wb-gamification' ),
				$points,
				$label
			),
			'detail'  => self::resolve_award_detail( $event ),
		);

		// A kudos exchange queues THREE toasts for one kudos: this points toast (for
		// both the giver and the receiver -- KudosEngine::record_kudos() fires
		// wb_gam_points_awarded twice, once per side) plus the "Someone gave you
		// kudos!" toast from on_kudos_given() below. KudosEngine stamps the kudos row
		// id onto object_id for both award events, so stamp it onto the payload too --
		// otherwise a moderator's revoke can only find and retract the kudos toast
		// (matched by its own kudos_id) and these two points toasts are stranded,
		// telling the giver/receiver they still have points that were just clawed
		// back. See on_kudos_revoked().
		if ( in_array( $event->action_id, array( 'give_kudos', 'receive_kudos' ), true ) && $event->object_id > 0 ) {
			$payload['kudos_id'] = $event->object_id;
		}

		self::push( $user_id, $payload );
	}

	/**
	 * Queue a badge notification for the user.
	 *
	 * BadgeEngine fires: do_action( 'wb_gam_badge_awarded', $user_id, $def, $badge_id )
	 *
	 * @param int    $user_id  User who earned the badge.
	 * @param array  $badge    Badge definition array (name, description, image_url, …).
	 * @param string $badge_id Badge slug (matches $badge['id']).
	 */
	public static function on_badge_awarded( int $user_id, array $badge, string $badge_id = '' ): void {
		// A migration replays history; it is not news. Without this, importing a member's three-year-old
		// badge tells them they just earned it -- QA proved a member who had not logged in for a year got
		// a congratulations email because an admin ran a migration. The badge still lands; only the
		// announcement stands down. See WBGam\Engine\ImportMode.
		if ( \WBGam\Engine\ImportMode::is_active() ) {
			return;
		}

		self::push(
			$user_id,
			array(
				'type'    => 'badge',
				'message' => sprintf(
					/* translators: %s = badge name */
					__( 'Badge earned: %s', 'wb-gamification' ),
					$badge['name'] ?? ''
				),
				'detail'  => $badge['description'] ?? null,
				'icon'    => 'icon-medal',
			)
		);
	}

	/**
	 * Queue a level-up notification for the user.
	 *
	 * LevelEngine fires: do_action( 'wb_gam_level_changed', $user_id, $new_level, $old_level )
	 *
	 * Pre-1.0.0 LevelEngine also fired a second time with int IDs; that
	 * legacy fire was removed. This listener was migrated to the array
	 * signature at the same time.
	 *
	 * @param int        $user_id   User who levelled up.
	 * @param array|null $new_level New level data (id, name, min_points, icon_url) or null.
	 * @param array|null $old_level Previous level data or null.
	 */
	public static function on_level_changed( int $user_id, ?array $new_level = null, ?array $old_level = null ): void {
		// Resilient to listeners receiving null — fall back to a fresh read.
		if ( null === $new_level || empty( $new_level['id'] ) ) {
			global $wpdb;
			$current_id = (int) get_user_meta( $user_id, 'wb_gam_level_id', true );
			if ( $current_id <= 0 ) {
				return;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$new_level = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT name, icon_url FROM {$wpdb->prefix}wb_gam_levels WHERE id = %d",
					$current_id
				),
				ARRAY_A
			) ?: array();
		}

		$level_name = (string) ( $new_level['name'] ?? '' );
		$message    = '' !== $level_name
			/* translators: %s: new level name. */
			? sprintf( __( 'You reached %s!', 'wb-gamification' ), $level_name )
			: __( 'You leveled up!', 'wb-gamification' );

		self::push(
			$user_id,
			array(
				'type'      => 'level_up',
				'message'   => $message,
				'levelName' => $level_name,
				'icon_url'  => $new_level['icon_url'] ?? '',
			)
		);
	}

	/**
	 * Queue a streak milestone notification for the user.
	 *
	 * @param int $user_id     User who hit the milestone.
	 * @param int $streak_days Number of consecutive days.
	 */
	public static function on_streak_milestone( int $user_id, int $streak_days ): void {
		self::push(
			$user_id,
			array(
				'type'    => 'streak_milestone',
				/* translators: %d: streak day count. */
				'message' => sprintf( _n( '%d-day streak!', '%d-day streak!', $streak_days, 'wb-gamification' ), $streak_days ),
				'days'    => $streak_days,
			)
		);
	}

	/**
	 * Queue a challenge-completed notification for the user.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Challenge data array.
	 */
	public static function on_challenge_completed( int $user_id, array $challenge ): void {
		self::push(
			$user_id,
			array(
				'type'    => 'challenge',
				'message' => sprintf(
					/* translators: %s = challenge title */
					__( 'Challenge complete: %s', 'wb-gamification' ),
					$challenge['title'] ?? ''
				),
				'icon'    => 'icon-target',
			)
		);
	}

	/**
	 * Queue a kudos notification for the receiver.
	 *
	 * @param int    $giver_id    User who gave kudos.
	 * @param int    $receiver_id User who received kudos.
	 * @param string $message     Kudos message text.
	 * @param int    $kudos_id    Kudos record ID.
	 */
	public static function on_kudos_given( int $giver_id, int $receiver_id, string $message, int $kudos_id ): void {
		// Notify the receiver (only if they're the current user on this request).
		self::push(
			$receiver_id,
			array(
				'type'     => 'kudos',
				// The kudos this toast is FOR. It was not recorded, which meant a moderator revoking
				// abusive kudos had no way to find the toast it had already queued -- so the receiver
				// was still congratulated for kudos that had been taken away as abuse.
				'kudos_id' => $kudos_id,
				'message'  => __( 'Someone gave you kudos!', 'wb-gamification' ),
				'detail'   => $message ?: null,
				'icon'     => 'icon-heart-handshake',
			)
		);
	}

	/**
	 * A moderator revoked a kudos. Take back the toast as well as the points.
	 *
	 * `wb_gam_kudos_revoked` fired and NOTHING listened to it. So a moderator could revoke a kudos as
	 * abuse -- reversing both members' points, correctly -- and the receiver would still be told
	 * "Someone gave you kudos!" on their next page load, for a kudos that no longer exists.
	 *
	 * Half a reversal is arguably worse than none: the points quietly vanish while the congratulation
	 * still arrives, and the member has no way to connect the two.
	 *
	 * `send()` (via `record_kudos()`) queues THREE rows for one kudos: the receiver's
	 * points toast, the giver's points toast, and this method's own "Someone gave you
	 * kudos!" toast -- all three now carry `kudos_id` in their payload (the points
	 * toasts get it from on_points_awarded() above; this toast already had it). A
	 * revoke must retract all three, for both users, or the points quietly reverse
	 * while the toasts that announced them keep lying.
	 *
	 * @since 1.6.4
	 *
	 * @param int $kudos_id    Revoked kudos.
	 * @param int $giver_id    Giver.
	 * @param int $receiver_id Receiver.
	 * @return void
	 */
	public static function on_kudos_revoked( int $kudos_id, int $giver_id, int $receiver_id ): void {
		global $wpdb;

		if ( $kudos_id <= 0 || $receiver_id <= 0 || ! self::queue_ready() ) {
			return;
		}

		// Both users can have a queued row for this kudos_id (receiver: points + kudos
		// toast; giver: points toast only) -- giver_id is filtered out below if unset
		// so this still degrades gracefully to receiver-only deletion.
		$user_ids = array_values( array_unique( array_filter( array( $receiver_id, $giver_id ) ) ) );
		if ( empty( $user_ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_notifications_queue
				  WHERE user_id IN ({$placeholders})
				    AND payload_json LIKE %s",
				array_merge( $user_ids, array( '%' . $wpdb->esc_like( '"kudos_id":' . $kudos_id ) . '%' ) )
			)
		);
	}

	// ── Output ──────────────────────────────────────────────────────────────────

	/**
	 * Render the Interactivity API markup and seed script in the footer.
	 * Only outputs for logged-in users who have pending events.
	 */
	public static function render(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$events = self::read_pending( $user_id, 'footer' );

		wp_enqueue_style( 'wb-gamification' );
		// Mount the IA store BEFORE the markup renders so the
		// data-wp-bind attributes resolve on first paint and the
		// streak / level-up overlays start hidden, not stuck-visible.
		wp_enqueue_script_module( 'wb-gamification-notifications' );

		// Interactivity script modules cannot use wp_set_script_translations, so
		// deliver the store's translatable fallback strings through injected
		// state. The store reads state.i18n.* only when a live event arrives
		// without a server-resolved label.
		wp_interactivity_state(
			'wb-gamification',
			array(
				'i18n' => array(
					'levelUp' => __( 'Level up!', 'wb-gamification' ),
				),
			)
		);

		// Enqueue the toast STACK renderer here, not only in enqueue_assets(): a host
		// that isolates foreign assets on its own front-end routes (e.g. BuddyNext's
		// AssetIsolation, which dequeues non-core/non-theme handles at
		// wp_enqueue_scripts priority 9999) strips toast.js there, leaving the seeded
		// earn with nothing to render it. render() runs on wp_footer — AFTER any such
		// late dequeue — so re-enqueueing the registered handles here makes the on-earn
		// toast appear wherever the notification surface does, on every host/theme.
		// (No-ops when the handles were never registered, e.g. logged-out.)
		if ( wp_script_is( 'wb-gamification-toast', 'registered' ) ) {
			wp_enqueue_script( 'wb-gamification-realtime' );
			wp_enqueue_script( 'wb-gamification-toast' );
		}

		// Always output the markup shell (JS needs the DOM nodes).
		// Seed script only if there are events.
		?>
		<div
			id="wb-gam-notifications"
			data-wp-interactive="wb-gamification"
			data-wp-init="callbacks.init"
		>
			<!--
				Toast STACK is owned by assets/js/toast.js (single container,
				lives in document.body). This element only carries the
				celebration overlays (level-up + streak milestone) — those
				are the IA store's surface.
			-->

			<!-- Level-up overlay -->
			<div
				class="wb-gam-overlay wb-gam-overlay--level-up"
				data-wp-bind--hidden="!state.levelUp.active"
				data-wp-on--click="actions.dismissLevelUp"
				hidden
				<?php
				/*
				 * An ANNOUNCEMENT, not a dialog.
				 *
				 * This claimed role="alertdialog" aria-modal="true" -- which tells a screen reader that
				 * the rest of the page is inert and that focus is trapped in here. Neither was true:
				 * nothing trapped focus, ESC did nothing, and the overlay is dismissed by clicking it.
				 * So an assistive-tech user was told they were in a modal they could not get out of,
				 * about a celebration they did not need to act on.
				 *
				 * A level-up is something that HAPPENED. It is announced (role="status", polite, so it
				 * waits its turn rather than cutting the member off mid-sentence) and it is never
				 * focused. Vestibular safety is handled separately -- see the prefers-reduced-motion
				 * block in assets/css/frontend.css.
				 */
				?>
				role="status"
				aria-live="polite"
				aria-label="<?php esc_attr_e( 'Level up!', 'wb-gamification' ); ?>"
			>
				<div class="wb-gam-overlay__card">
					<p class="wb-gam-overlay__eyebrow"><?php esc_html_e( 'Level up!', 'wb-gamification' ); ?></p>
					<img alt="" class="wb-gam-overlay__icon"
						data-wp-bind--src="state.levelUp.iconUrl"
						data-wp-bind--hidden="!state.levelUp.iconUrl"
					/>
					<p class="wb-gam-overlay__title" data-wp-text="state.levelUp.levelName"></p>
					<button
						class="wb-gam-overlay__dismiss"
						aria-label="<?php esc_attr_e( 'Close', 'wb-gamification' ); ?>"
						data-wp-on--click="actions.dismissLevelUp"
					><?php esc_html_e( 'Awesome!', 'wb-gamification' ); ?></button>
				</div>
			</div>

			<!-- Streak milestone overlay -->
			<div
				class="wb-gam-overlay wb-gam-overlay--streak"
				data-wp-bind--hidden="!state.streakMilestone.active"
				data-wp-on--click="actions.dismissStreakMilestone"
				hidden
				<?php
				/*
				 * An ANNOUNCEMENT, not a dialog.
				 *
				 * This claimed role="alertdialog" aria-modal="true" -- which tells a screen reader that
				 * the rest of the page is inert and that focus is trapped in here. Neither was true:
				 * nothing trapped focus, ESC did nothing, and the overlay is dismissed by clicking it.
				 * So an assistive-tech user was told they were in a modal they could not get out of,
				 * about a celebration they did not need to act on.
				 *
				 * A level-up is something that HAPPENED. It is announced (role="status", polite, so it
				 * waits its turn rather than cutting the member off mid-sentence) and it is never
				 * focused. Vestibular safety is handled separately -- see the prefers-reduced-motion
				 * block in assets/css/frontend.css.
				 */
				?>
				role="status"
				aria-live="polite"
				aria-label="<?php esc_attr_e( 'Streak milestone!', 'wb-gamification' ); ?>"
			>
				<div class="wb-gam-overlay__card">
					<p class="wb-gam-overlay__eyebrow">&#x1F525; <?php esc_html_e( 'Streak milestone!', 'wb-gamification' ); ?></p>
					<p class="wb-gam-overlay__streak-days">
						<span data-wp-text="state.streakMilestone.days"></span>
						<?php esc_html_e( 'days', 'wb-gamification' ); ?>
					</p>
					<p class="wb-gam-overlay__sub"><?php esc_html_e( 'Keep showing up - you\'re on fire!', 'wb-gamification' ); ?></p>
					<button
						class="wb-gam-overlay__dismiss"
						aria-label="<?php esc_attr_e( 'Close', 'wb-gamification' ); ?>"
						data-wp-on--click="actions.dismissStreakMilestone"
					><?php esc_html_e( 'Keep it up!', 'wb-gamification' ); ?></button>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $events ) ) : ?>
			<?php wp_print_inline_script_tag( 'window.wbGamNotifications = ' . wp_json_encode( $events ) . ';', array( 'id' => 'wb-gam-notifications-data' ) ); ?>
		<?php endif; ?>
		<?php
	}

	// ── Queue: one store, one writer, one reader ─────────────────────────────────
	//
	// The durable `wb_gam_notifications_queue` table is the ONLY store. Until
	// 1.6.4 every push also wrote a parallel transient, and the two disagreed on
	// everything that mattered: the transient was capped at 50 by array_slice
	// while the table appended forever; the transient's ids restarted at 1 on
	// every cache flush while the table's auto-increment never resets. The read
	// path had already moved to the table, so the transient was written on every
	// award and read essentially never — and a block in push() existed purely to
	// walk the cursor metas and drag new transient ids past a stale high-water
	// mark, a hack whose only reason to exist was the transient's resetting ids.
	//
	// One store deletes all of it: the second bound, the second id scheme, the
	// reconciliation hack, and two cache round-trips per award.

	/**
	 * Is the durable queue available? Created by DbUpgrader's feature migration,
	 * which runs at `plugins_loaded` (BootOrder::SCHEMA) — i.e. before any award
	 * can fire on a normal request.
	 *
	 * The only window where this is false is a boot so early the migration has
	 * not run. Events pushed there are dropped, deliberately: a toast is an
	 * ephemeral "this just happened" surface, and one that missed its moment has
	 * no value later. That is the same reasoning behind the read burst-cap.
	 *
	 * @since 1.6.4
	 */
	private static function queue_ready(): bool {
		return (bool) get_option( 'wb_gam_feature_notifications_queue_v1' );
	}

	/**
	 * Queue one notification event for a member.
	 *
	 * The table's AUTO_INCREMENT is the event id — nothing here computes one.
	 * Readers stamp the authoritative row id onto the payload as `_id` (the key
	 * the client dedupes on), so an id assigned at write time would only be
	 * overwritten at read time. `_ts` is kept for toast.js's legacy fallback key.
	 *
	 * @param int   $user_id User to notify.
	 * @param array $event   Notification event data.
	 */
	private static function push( int $user_id, array $event ): void {
		if ( $user_id <= 0 || ! self::queue_ready() ) {
			return;
		}

		/**
		 * Filter toast notification data before it is queued.
		 *
		 * Modify the message, icon, or type. Return an empty array to suppress
		 * this notification entirely.
		 *
		 * @since 1.0.0
		 * @param array $event   Notification data (type, message, detail, icon).
		 * @param int   $user_id User who will see the toast.
		 */
		$event = (array) apply_filters( 'wb_gam_toast_data', $event, $user_id );
		if ( empty( $event ) ) {
			return;
		}

		$event['_ts'] = time();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_notifications_queue',
			array(
				'user_id'      => $user_id,
				'event_type'   => (string) ( $event['type'] ?? 'unknown' ),
				'payload_json' => (string) wp_json_encode( $event ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		self::trim( $user_id );
	}

	/**
	 * Evict a member's queue rows beyond QUEUE_MAX_EVENTS, oldest first.
	 *
	 * THE bound on the table. Not a safety net over the prune cron — the bound
	 * itself. A queue that is capped on write cannot grow without limit on any
	 * install, including the ones that need it most: `DISABLE_WP_CRON` hosts with
	 * no real crontab, where the daily prune silently never runs. Before 1.6.4
	 * only the transient was bounded and the table appended forever, so a load
	 * test left one member holding 30,197 rows (Basecamp #10086171887).
	 *
	 * Dropping oldest-first costs nothing: a read renders at most the newest
	 * BURST_MAX_EVENTS, so rows evicted here were never going to be shown.
	 *
	 * The subquery is wrapped in a derived table because MySQL will not read from
	 * the table it is deleting from. It resolves against `idx_user_id (user_id,
	 * id)` — a ~51-row index scan that matches nothing on the common path where
	 * the member is under cap.
	 *
	 * @since 1.6.4
	 *
	 * @param int $user_id Member whose queue to trim.
	 */
	private static function trim( int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_notifications_queue
				  WHERE user_id = %d
				    AND id < (
				        SELECT keep_from FROM (
				            SELECT id AS keep_from
				              FROM {$wpdb->prefix}wb_gam_notifications_queue
				             WHERE user_id = %d
				          ORDER BY id DESC
				             LIMIT 1 OFFSET %d
				        ) AS cutoff
				    )",
				$user_id,
				$user_id,
				self::QUEUE_MAX_EVENTS - 1
			)
		);
	}

	/**
	 * Daily cron: delete rows past the retention window.
	 *
	 * Retention, NOT the bound — trim() is the bound. That distinction matters:
	 * before 1.6.4 this cron WAS the table's only limit, it ran a single un-looped
	 * `LIMIT 5000` DELETE per day, and so any site producing more than 5,000
	 * prunable rows/day fell permanently behind and grew without limit. Worse, on
	 * `DISABLE_WP_CRON` installs with no real crontab it never ran at all.
	 *
	 * Now it batches in a loop with a per-run cap, so a backlog actually drains —
	 * and because the table is bounded on write, a site where this never fires is
	 * still safe. Whatever the per-run cap leaves behind, the next tick collects.
	 *
	 * @since 1.6.4 Batched loop with a per-run cap. Was a single un-looped DELETE.
	 *
	 * @as-fire-once Daily cron tick. Bounded delete loop; cannot recurse.
	 */
	public static function prune_queue(): void {
		if ( ! self::queue_ready() ) {
			return;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );

		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}wb_gam_notifications_queue WHERE created_at < %s LIMIT %d",
					$cutoff,
					self::PRUNE_BATCH_SIZE
				)
			);

			// A short batch (or an error) means the backlog is drained — stop.
			if ( ! is_int( $deleted ) || $deleted < self::PRUNE_BATCH_SIZE ) {
				break;
			}
		}
	}

	/**
	 * THE queue reader. Every surface goes through this — the footer seed, the
	 * heartbeat tick, the REST poll (all via read_pending, cursor in user_meta)
	 * and the SSE stream (WBGam\API\SSEController, cursor from the request).
	 *
	 * Returns the NEWEST unseen events, capped at $limit, ordered oldest-first
	 * for display. The last element's id is therefore the head of the entire
	 * backlog, not merely of the slice returned — so a caller that advances its
	 * cursor to it skips everything older instead of queueing it for next time.
	 * That single property is what makes a backlog impossible to replay, and it
	 * needs no special case for small queues: the newest-5-of-3 is all 3, and the
	 * cursor lands exactly where it would have anyway.
	 *
	 * A toast is an ephemeral, realtime surface. A member returning to 30,000
	 * pending events wants the newest few, not 600 page loads of catch-up.
	 *
	 * Rows whose payload will not decode are still returned (with an empty
	 * payload) so their id can advance the caller's cursor — otherwise one corrupt
	 * row would wedge the queue forever. Callers skip empty payloads for display.
	 *
	 * @since 1.6.4
	 *
	 * @param int $user_id  Member id.
	 * @param int $after_id Highest id already delivered to this caller.
	 * @param int $limit    Max events to return. Defaults to the toast burst cap.
	 * @return array<int, array{id: int, event_type: string, payload: array}> Oldest-first.
	 */
	public static function fetch_unseen( int $user_id, int $after_id, int $limit = self::BURST_MAX_EVENTS ): array {
		if ( $user_id <= 0 || ! self::queue_ready() ) {
			return array();
		}

		$limit = max( 1, $limit );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, payload_json
				   FROM {$wpdb->prefix}wb_gam_notifications_queue
				  WHERE user_id = %d AND id > %d
				  ORDER BY id DESC
				  LIMIT %d",
				$user_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$events = array();
		foreach ( array_reverse( $rows ) as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );

			$events[] = array(
				'id'         => (int) $row['id'],
				'event_type' => (string) $row['event_type'],
				'payload'    => is_array( $payload ) ? $payload : array(),
			);
		}

		return $events;
	}

	/**
	 * Read the events a given consumer has not yet delivered, and check its
	 * cursor forward.
	 *
	 * Non-destructive: each consumer (footer render, heartbeat tick, REST poll)
	 * keeps its own user-meta cursor, so the same toast reaches each independent
	 * surface once without a race.
	 *
	 * @param int    $user_id  Member id.
	 * @param string $consumer Cursor namespace ('footer', 'heartbeat', 'rest').
	 * @return array[] Unseen event payloads, oldest-first, each stamped with `_id`.
	 */
	public static function read_pending( int $user_id, string $consumer ): array {
		$consumer = sanitize_key( $consumer );
		if ( $user_id <= 0 || '' === $consumer ) {
			return array();
		}

		$cursor = (int) get_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, true );
		$rows   = self::fetch_unseen( $user_id, $cursor );

		if ( empty( $rows ) ) {
			return array();
		}

		$events = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['payload'] ) ) {
				continue; // Undecodable row: skipped for display, still advances the cursor below.
			}
			// The table id is authoritative — it is the key toast.js dedupes on.
			$payload        = $row['payload'];
			$payload['_id'] = $row['id'];
			$events[]       = $payload;
		}

		// Park the cursor at the head of the backlog, not at the last event shown.
		// This is what drops the un-shown remainder rather than replaying it.
		$head = (int) $rows[ array_key_last( $rows ) ]['id'];
		update_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, $head );

		return $events;
	}


	// ── Helpers ──────────────────────────────────────────────────────────────────

	/**
	 * Resolve the toast's "what you did" line for a points award.
	 *
	 * A points toast must ALWAYS say what the points were for — never a
	 * bare number or count. Resolution order:
	 *   1. An explicit human reason in the event metadata. Admin/manual
	 *      awards carry the reason the admin typed (PointsController sets
	 *      `metadata['reason']`), so the member sees e.g. "Community hero
	 *      this month" instead of a generic label.
	 *   2. The action's human label (self::action_label), which itself
	 *      never returns empty.
	 *
	 * @param Event $event The award event.
	 * @return string Non-empty, human-readable reason for the award.
	 */
	private static function resolve_award_detail( Event $event ): string {
		$reason = isset( $event->metadata['reason'] ) ? trim( (string) $event->metadata['reason'] ) : '';
		if ( '' !== $reason ) {
			return $reason;
		}

		return self::action_label( $event->action_id );
	}

	/**
	 * Human-readable label for an action_id — the toast's "what you did" line.
	 *
	 * Resolution order:
	 *   1. The registered action's own manifest label (e.g. "Publish a blog
	 *      post", "Leave a comment"). This is the canonical source — every
	 *      manifest trigger declares a `label`, so this covers WordPress core,
	 *      BuddyPress, WooCommerce, and every contrib integration without a
	 *      per-id map to maintain.
	 *   2. A small fallback map for ids that are NOT registered actions
	 *      (kudos are fired directly; manual/admin awards use 'manual' /
	 *      'manual_award').
	 *   3. A generic "Points awarded" — NEVER empty, so the toast always
	 *      states a reason rather than showing a contextless "+N points"
	 *      or a bare "xN" count.
	 *
	 * Before 1.5.2 this returned a hardcoded map keyed on ids like
	 * `post_publish` / `comment_publish` that never matched the real
	 * manifest ids (`wp_publish_post` / `wp_leave_comment`), so the detail
	 * line was null on every real award and toasts showed a bare "+N points"
	 * with no context.
	 *
	 * @param string $action_id The action ID to look up.
	 * @return string Translated, human-readable, non-empty label.
	 */
	private static function action_label( string $action_id ): string {
		// 1. Prefer the manifest's own label via the Registry.
		$def = \WBGam\Engine\Registry::get_action( $action_id );
		if ( is_array( $def ) && ! empty( $def['label'] ) ) {
			return (string) $def['label'];
		}

		// 2. Ids that aren't registered as Registry actions.
		$labels = array(
			'give_kudos'    => __( 'Gave kudos', 'wb-gamification' ),
			'receive_kudos' => __( 'Received kudos', 'wb-gamification' ),
			'manual'        => __( 'Manual award', 'wb-gamification' ),
			'manual_award'  => __( 'Manual award', 'wb-gamification' ),
		);
		if ( isset( $labels[ $action_id ] ) ) {
			return $labels[ $action_id ];
		}

		// 3. Last-resort generic — still states that points were awarded.
		return __( 'Points awarded', 'wb-gamification' );
	}
}
