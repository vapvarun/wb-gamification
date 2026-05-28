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
//   - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
//     established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
//     Plugin Check auto-detects `wb_gamification` from the text-domain header
//     and doesn't share the .phpcs.xml prefix list; hooks like
//     `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
//   - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
//     functions exported under `wb_gam_*` are documented in `src/Extensions/`.
//   - PluginCheck.Security.DirectDB.UnescapedDBParameter +
//     WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
//     table work. Table names are interpolated from `{$wpdb->prefix}` plus
//     literal constants (no user input); user-supplied values pass through
//     `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
//     interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Bridges gamification events to front-end notifications via transients and Interactivity API.
 *
 * @package WB_Gamification
 */
final class NotificationBridge {

	private const TRANSIENT_PREFIX = 'wb_gam_notif_';
	private const TRANSIENT_TTL    = 300; // 5 minutes
	/**
	 * Maximum events kept per-user in the queue. Old events evict on push.
	 * Bounded so a noisy account can't blow the option/transient size.
	 *
	 * @var int
	 */
	private const QUEUE_MAX_EVENTS = 50;
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

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register action hooks for event collection and footer rendering.
	 */
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

		// v2.2 — daily prune of the durable queue table.
		add_action( self::PRUNE_CRON, array( __CLASS__, 'prune_queue' ) );
		if ( ! wp_next_scheduled( self::PRUNE_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_CRON );
		}
		// Skip toasts — surface "you hit the daily cap / cooldown" to the
		// member so they understand why no points appeared. Pre-1.4.1 the
		// engine fired `wb_gam_award_skipped` in 6 places (PointsEngine +
		// Registry + Jetonomy) with no internal listener — pure dead-letter.
		// Closes audit/DATA-FLOW-AWARD-2026-05-27.md §G17.
		add_action( 'wb_gam_award_skipped', array( __CLASS__, 'on_award_skipped' ), 99, 4 );

		// Output markup + seed script once, in the footer.
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
	}

	/**
	 * Push a "skip toast" — informs the member why an action they just
	 * performed did NOT award points. Only fires for reasons the member
	 * can act on (cap hit, cooldown active). Silent for engine-internal
	 * reasons (self-action, sandboxed) where a toast would be confusing.
	 *
	 * @since 1.4.1
	 *
	 * @param int    $user_id   User who would have been awarded.
	 * @param string $action_id Action that was skipped.
	 * @param string $reason    Closed-set reason from PointsEngine::passes_rate_limits.
	 * @param array  $context   Optional context (daily_cap_used, cooldown_seconds, etc.).
	 */
	public static function on_award_skipped( int $user_id, string $action_id, string $reason, array $context = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		// Only the member-facing reasons get a toast — internal vetoes
		// (sandboxed, self_action, pre_change_veto) confuse the user.
		$user_facing_reasons = array( 'cooldown', 'daily_cap', 'weekly_cap' );
		if ( ! in_array( $reason, $user_facing_reasons, true ) ) {
			return;
		}

		$message = '';
		switch ( $reason ) {
			case 'cooldown':
				$message = __( "You're on cooldown for this action — try again in a bit.", 'wb-gamification' );
				break;
			case 'daily_cap':
				$message = __( "You've hit your daily limit for this action. Resets tomorrow.", 'wb-gamification' );
				break;
			case 'weekly_cap':
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
					__( 'See your full progress — points, badges, levels, leaderboard — at %s', 'wb-gamification' ),
					wp_make_link_relative( $hub_url )
				)
				: __( 'Earn more points by being active on the site — every action counts.', 'wb-gamification' );

			self::push(
				$user_id,
				array(
					'type'    => 'welcome',
					'message' => __( 'Welcome — you just earned your first points!', 'wb-gamification' ),
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

		self::push(
			$user_id,
			array(
				'type'    => 'points',
				'points'  => $points,
				'message' => sprintf(
					/* translators: 1: signed integer (e.g. "+5"), 2: currency label ("points", "XP", "Coins"). */
					__( '+%1$d %2$s', 'wb-gamification' ),
					$points,
					$label
				),
				'detail'  => self::action_label( $event->action_id ),
			)
		);
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
			/* translators: %s: new level name (e.g. "Champion"). */
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
				/* translators: %d: number of consecutive days. */
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
				'type'    => 'kudos',
				'message' => __( 'Someone gave you kudos!', 'wb-gamification' ),
				'detail'  => $message ?: null,
				'icon'    => 'icon-heart-handshake',
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
				role="alertdialog"
				aria-modal="true"
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
				role="alertdialog"
				aria-modal="true"
				aria-label="<?php esc_attr_e( 'Streak milestone!', 'wb-gamification' ); ?>"
			>
				<div class="wb-gam-overlay__card">
					<p class="wb-gam-overlay__eyebrow">&#x1F525; <?php esc_html_e( 'Streak milestone!', 'wb-gamification' ); ?></p>
					<p class="wb-gam-overlay__streak-days">
						<span data-wp-text="state.streakMilestone.days"></span>
						<?php esc_html_e( 'days', 'wb-gamification' ); ?>
					</p>
					<p class="wb-gam-overlay__sub"><?php esc_html_e( 'Keep showing up — you\'re on fire!', 'wb-gamification' ); ?></p>
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

	// ── Transient helpers ────────────────────────────────────────────────────────

	/**
	 * Append a notification event to the user's pending queue.
	 *
	 * @param int   $user_id User to notify.
	 * @param array $event   Notification event data.
	 */
	private static function push( int $user_id, array $event ): void {
		if ( $user_id <= 0 ) {
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

		$key    = self::TRANSIENT_PREFIX . $user_id;
		$events = get_transient( $key );
		$existing_events = is_array( $events ) ? $events : array();

		// Assign a monotonic id so consumers can checkpoint reads instead
		// of destructively flushing the queue. Use the largest existing
		// id + 1 (not count) so removing old events doesn't recycle ids.
		$last_id = 0;
		foreach ( $existing_events as $existing ) {
			if ( isset( $existing['_id'] ) && (int) $existing['_id'] > $last_id ) {
				$last_id = (int) $existing['_id'];
			}
		}

		// Transient-reset recovery: when the transient was wiped (TTL or
		// `wp_cache_flush`) but per-consumer cursors in user_meta still
		// reflect a prior queue's high-water mark, new events restart at
		// `_id = 1` but every cursor is `>= 1000` from the old queue —
		// every `read_pending` returns empty and the user never sees
		// another toast for the lifetime of their account. Walk the
		// three cursor metas and bump $last_id to their max before
		// stamping the new event. Closes
		// audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G13.
		if ( empty( $existing_events ) ) {
			$consumers = array( 'footer', 'heartbeat', 'rest' );
			foreach ( $consumers as $consumer ) {
				$cursor = (int) get_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, true );
				if ( $cursor > $last_id ) {
					$last_id = $cursor;
				}
			}
		}

		$event['_id'] = $last_id + 1;
		$event['_ts'] = time();
		$events       = $existing_events;
		$events[]     = $event;

		// Bound queue size — drop oldest first.
		if ( count( $events ) > self::QUEUE_MAX_EVENTS ) {
			$events = array_slice( $events, -self::QUEUE_MAX_EVENTS );
		}

		set_transient( $key, $events, self::TRANSIENT_TTL );

		// v2.2 — durability dual-write. The transient above is the legacy
		// path consumers still read from; the table is the new durable
		// store that survives wp_cache_flush and feeds the SSE writer
		// (stage 2 of the realtime transport rollout). When this commit
		// has run on an install for one TTL cycle, readers can switch
		// to table-first with the transient as fallback — but that's a
		// follow-up; this commit is risk-free additive write only.
		self::persist_to_queue_table( $user_id, $event );
	}

	/**
	 * Daily cron: delete rows older than RETENTION_SECONDS from the
	 * durable queue table. Bounded query (LIMIT 5000 per run) so a
	 * sudden 24-hour backlog doesn't lock the table.
	 *
	 * @as-fire-once Daily cron tick. Bounded delete; cannot recurse.
	 */
	public static function prune_queue(): void {
		if ( ! get_option( 'wb_gam_feature_notifications_queue_v1' ) ) {
			return;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_notifications_queue WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
	}

	/**
	 * Append one notification event to the durable queue table.
	 *
	 * @param int   $user_id Member id.
	 * @param array $event   Notification payload (already stamped with _id, _ts).
	 */
	private static function persist_to_queue_table( int $user_id, array $event ): void {
		global $wpdb;

		// Feature-flag gated by the DbUpgrader migration. If the table
		// doesn't exist yet (e.g. very-early-boot before the migration
		// runs), silent skip — the transient still has the data.
		if ( ! get_option( 'wb_gam_feature_notifications_queue_v1' ) ) {
			return;
		}

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
	}

	/**
	 * Read pending events for a user that the given consumer has not yet delivered.
	 *
	 * Non-destructive: each consumer (footer render, heartbeat tick, REST
	 * poll) maintains its own user-meta cursor. Same toast can be
	 * delivered once to each independent surface without a race.
	 *
	 * v2.2b: reads now prefer the durable wb_gam_notifications_queue table
	 * over the transient. Transient stays as a fallback for the case
	 * where the migration hasn't run yet (very early boot on first
	 * activation). When the table is the source of truth, cursors in
	 * user_meta track the table's globally-monotonic auto-increment id —
	 * the same cursor namespace key (CURSOR_META_PREFIX + consumer) is
	 * reused. Pre-v2.2b cursors stored transient-`_id` values which are
	 * RESET to 1 on cache flush; table ids are never reset, so any
	 * lingering pre-v2.2b cursor is at worst harmlessly low (sees more
	 * events than expected once) and self-heals on first read.
	 *
	 * @param int    $user_id  Member id.
	 * @param string $consumer Cursor namespace (e.g. 'footer', 'heartbeat', 'rest').
	 *                         Each consumer gets its own cursor in user_meta.
	 * @return array[] Unseen events for this consumer.
	 */
	public static function read_pending( int $user_id, string $consumer ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$consumer = sanitize_key( $consumer );
		if ( '' === $consumer ) {
			return array();
		}

		// Prefer the durable table when the migration has run. Falls
		// through to the transient path on installs that haven't seen
		// ensure_notifications_queue_table() yet.
		if ( get_option( 'wb_gam_feature_notifications_queue_v1' ) ) {
			return self::read_pending_from_table( $user_id, $consumer );
		}

		$key    = self::TRANSIENT_PREFIX . $user_id;
		$events = get_transient( $key );
		if ( ! is_array( $events ) || empty( $events ) ) {
			return array();
		}

		$cursor   = (int) get_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, true );
		$unseen   = array();
		$max_seen = $cursor;
		foreach ( $events as $event ) {
			$id = (int) ( $event['_id'] ?? 0 );
			if ( $id > $cursor ) {
				$unseen[]  = $event;
				$max_seen = $id > $max_seen ? $id : $max_seen;
			}
		}

		if ( ! empty( $unseen ) ) {
			update_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, $max_seen );
		}

		return $unseen;
	}

	/**
	 * Table-first read path for the durable queue. Returns events with
	 * id > cursor for this consumer, decoded into the same shape the
	 * transient path returns (so callers don't branch on storage).
	 *
	 * @param int    $user_id  Member id.
	 * @param string $consumer Cursor namespace.
	 * @return array[] Unseen events.
	 */
	private static function read_pending_from_table( int $user_id, string $consumer ): array {
		global $wpdb;

		$cursor = (int) get_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload_json
				   FROM {$wpdb->prefix}wb_gam_notifications_queue
				  WHERE user_id = %d AND id > %d
				  ORDER BY id ASC
				  LIMIT %d",
				$user_id,
				$cursor,
				self::QUEUE_MAX_EVENTS
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$unseen   = array();
		$max_seen = $cursor;
		foreach ( $rows as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			// Overwrite _id with the table's authoritative id so callers
			// + the toast.js dedupe key stay coherent regardless of which
			// path produced the event.
			$payload['_id'] = (int) $row['id'];
			$unseen[]       = $payload;
			$max_seen       = max( $max_seen, (int) $row['id'] );
		}

		if ( ! empty( $unseen ) ) {
			update_user_meta( $user_id, self::CURSOR_META_PREFIX . $consumer, $max_seen );
		}

		return $unseen;
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	/**
	 * Human-readable label for common action_ids.
	 *
	 * @param string $action_id The action ID to look up.
	 * @return string|null Translated label or null if unknown.
	 */
	private static function action_label( string $action_id ): ?string {
		$labels = array(
			'register'            => __( 'for joining', 'wb-gamification' ),
			'post_publish'        => __( 'for publishing', 'wb-gamification' ),
			'comment_publish'     => __( 'for commenting', 'wb-gamification' ),
			'bp_activity_post'    => __( 'for posting', 'wb-gamification' ),
			'bp_activity_like'    => __( 'for liking', 'wb-gamification' ),
			'bp_activity_comment' => __( 'for commenting', 'wb-gamification' ),
			'bp_profile_updated'  => __( 'for updating profile', 'wb-gamification' ),
			'give_kudos'          => __( 'for giving kudos', 'wb-gamification' ),
			'receive_kudos'       => __( 'for receiving kudos', 'wb-gamification' ),
		);

		return $labels[ $action_id ] ?? null;
	}
}
