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

/**
 * Bridges gamification events to front-end notifications via transients and Interactivity API.
 *
 * @package WB_Gamification
 */
final class NotificationBridge {

	private const TRANSIENT_PREFIX = 'wb_gam_notif_';
	private const TRANSIENT_TTL    = 300; // 5 minutes

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register action hooks for event collection and footer rendering.
	 */
	public static function init(): void {
		// Collect events from action hooks.
		add_action( 'wb_gam_points_awarded', array( __CLASS__, 'on_points_awarded' ), 99, 3 );
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'on_badge_awarded' ), 99, 3 );
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_changed' ), 99, 3 );
		add_action( 'wb_gam_streak_milestone', array( __CLASS__, 'on_streak_milestone' ), 99, 2 );
		add_action( 'wb_gam_challenge_completed', array( __CLASS__, 'on_challenge_completed' ), 99, 2 );
		add_action( 'wb_gam_kudos_given', array( __CLASS__, 'on_kudos_given' ), 99, 4 );

		// Output markup + seed script once, in the footer.
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
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

		self::push(
			$user_id,
			array(
				'type'    => 'points',
				'points'  => $points,
				'message' => sprintf(
					/* translators: %d: number of points awarded. */
					_n( '+%d point', '+%d points', $points, 'wb-gamification' ),
					$points
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
	 * LevelEngine fires: do_action( 'wb_gam_level_changed', $user_id, $old_level_id, $new_level_id )
	 *
	 * @param int $user_id      User who levelled up.
	 * @param int $old_level_id Previous level ID (unused, retained for hook signature).
	 * @param int $new_level_id New level ID.
	 */
	public static function on_level_changed( int $user_id, int $old_level_id, int $new_level_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new_level = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, icon_url FROM {$wpdb->prefix}wb_gam_levels WHERE id = %d",
				$new_level_id
			),
			ARRAY_A
		) ?: array();

		self::push(
			$user_id,
			array(
				'type'      => 'level_up',
				'levelName' => $new_level['name'] ?? '',
				'iconUrl'   => $new_level['icon_url'] ?? '',
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
				'type' => 'streak_milestone',
				'days' => $streak_days,
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

		$events = self::flush( $user_id );

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
			<!-- Toast stack -->
			<div
				class="wb-gam-toasts"
				role="region"
				aria-label="<?php esc_attr_e( 'Notifications', 'wb-gamification' ); ?>"
				aria-live="polite"
				aria-relevant="additions"
			>
				<template data-wp-each--toast="state.toasts" data-wp-each-key="context.toast.id">
					<div
						class="wb-gam-toast"
						data-wp-bind--data-type="context.toast.type"
					>
						<span class="wb-gam-toast__icon" data-wp-bind--class="state.toastIconClass" aria-hidden="true"></span>
						<div class="wb-gam-toast__body">
							<strong class="wb-gam-toast__message" data-wp-text="context.toast.message"></strong>
							<span
								class="wb-gam-toast__detail"
								data-wp-text="context.toast.detail"
								data-wp-bind--hidden="!context.toast.detail"
							></span>
						</div>
						<button
							class="wb-gam-toast__close"
							aria-label="<?php esc_attr_e( 'Dismiss', 'wb-gamification' ); ?>"
							data-wp-on--click="actions.dismissToast"
						>&#x2715;</button>
					</div>
				</template>
			</div>

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

		$key      = self::TRANSIENT_PREFIX . $user_id;
		$events   = get_transient( $key ) ?: array();
		$events[] = $event;
		set_transient( $key, $events, self::TRANSIENT_TTL );
	}

	/**
	 * Read and delete all pending events for a user.
	 *
	 * @param int $user_id User whose events to flush.
	 * @return array[]
	 */
	private static function flush( int $user_id ): array {
		$key    = self::TRANSIENT_PREFIX . $user_id;
		$events = get_transient( $key );
		delete_transient( $key );
		return is_array( $events ) ? $events : array();
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
