<?php
/**
 * Community notification contract.
 *
 * WB Gamification has no member-facing notification hook of its own today —
 * `NotificationBridge` only queues footer toasts for the CURRENT page load,
 * which a host community plugin (BuddyNext) cannot subscribe to. This class
 * is the one place that turns eight of the plugin's own event hooks into a
 * single new hook, `wb_gam_notification_created`, carrying a payload array a
 * host reads to show one bell row with WB Gamification's own words and link.
 * WB Gamification keeps sending its own transactional email where it already
 * does (see TransactionalEmailEngine) — this class never emails.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wb_gam_* is this plugin's documented hook prefix (see CLAUDE.md).

/**
 * Builds, declares and answers for the community notification contract.
 */
final class CommunityNotifications {

	/**
	 * Register the eight event hooks that become community notifications, plus
	 * the contract's types/visible filters.
	 */
	public static function init(): void {
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'on_badge_awarded' ), 20, 3 );
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_changed' ), 20, 3 );
		add_action( 'wb_gam_kudos_given', array( __CLASS__, 'on_kudos_given' ), 20, 4 );
		add_action( 'wb_gam_kudos_revoked', array( __CLASS__, 'on_kudos_revoked' ), 20, 1 );
		add_action( 'wb_gam_challenge_completed', array( __CLASS__, 'on_challenge_completed' ), 20, 2 );
		add_action( 'wb_gam_redemption_fulfilled', array( __CLASS__, 'on_redemption_fulfilled' ), 20, 2 );
		add_action( 'wb_gam_credential_expired', array( __CLASS__, 'on_credential_expired' ), 20, 2 );
		add_action( 'wb_gam_personal_record', array( __CLASS__, 'on_personal_record' ), 20, 5 );
		add_action( 'wb_gam_streak_milestone', array( __CLASS__, 'on_streak_milestone' ), 20, 2 );

		// A deleted badge definition takes every notification that named it —
		// both the award and any expiry notice — with it. One fire regardless
		// of how many members had earned it: purge() below matches by
		// (object_type, object_id) only, not per recipient.
		add_action( 'wb_gam_badge_deleted', array( __CLASS__, 'on_badge_deleted' ), 20, 1 );

		add_filter( 'wb_gam_community_notification_types', array( __CLASS__, 'filter_types' ) );
		add_filter( 'wb_gam_community_notification_visible', array( __CLASS__, 'filter_visible' ), 10, 3 );
	}

	// ── Event handlers ──────────────────────────────────────────────────────────

	/**
	 * BadgeEngine.php fires: do_action( 'wb_gam_badge_awarded', $user_id, $def, $badge_id ).
	 *
	 * @param int    $user_id  Member who earned the badge.
	 * @param array  $def      Badge definition (name, description, image_url, …).
	 * @param string $badge_id Badge slug.
	 */
	public static function on_badge_awarded( int $user_id, array $def, string $badge_id ): void {
		$name = (string) ( $def['name'] ?? '' );
		self::notify(
			$user_id,
			'badge_awarded',
			'badge',
			self::badge_object_id( $badge_id ),
			'' !== $name
				/* translators: %s: badge name. */
				? sprintf( __( 'You earned a new badge: %s.', 'wb-gamification' ), $name )
				: __( 'You earned a new badge.', 'wb-gamification' ),
			self::notification_url( $user_id ),
			'badge_awarded_' . sanitize_key( $badge_id )
		);
	}

	/**
	 * LevelEngine.php fires: do_action( 'wb_gam_level_changed', $user_id, $new_level, $old_level ).
	 * Only a climb is news — the same rule BuddyNext's retired bridge applied
	 * (`wb_gam_is_level_climb()`), now owned here since this plugin decides
	 * when to notify about its own events.
	 *
	 * @param int        $user_id   Member whose level changed.
	 * @param array|null $new_level New level row (id, name, min_points).
	 * @param array|null $old_level Previous level row, or null.
	 */
	public static function on_level_changed( int $user_id, ?array $new_level, ?array $old_level = null ): void {
		if ( ! wb_gam_is_level_climb( $new_level, $old_level ) ) {
			return;
		}
		$level_id   = (int) ( $new_level['id'] ?? 0 );
		$level_name = (string) ( $new_level['name'] ?? '' );
		self::notify(
			$user_id,
			'level_up',
			'level',
			$level_id,
			'' !== $level_name
				/* translators: %s: new level name, e.g. "Contributor". */
				? sprintf( __( 'You reached %s.', 'wb-gamification' ), $level_name )
				: __( 'You levelled up.', 'wb-gamification' ),
			self::notification_url( $user_id ),
			'level_up_' . $level_id
		);
	}

	/**
	 * KudosEngine.php fires: do_action( 'wb_gam_kudos_given', $giver_id, $receiver_id, $message, $kudos_id ).
	 *
	 * @param int    $giver_id    Member who gave kudos.
	 * @param int    $receiver_id Member who received kudos.
	 * @param string $message     Kudos message (unused here; the toast shows it, not the bell row).
	 * @param int    $kudos_id    Kudos row id.
	 */
	public static function on_kudos_given( int $giver_id, int $receiver_id, string $message = '', int $kudos_id = 0 ): void {
		unset( $message );
		$giver = get_userdata( $giver_id );
		self::notify(
			$receiver_id,
			'kudos_received',
			'kudos',
			$kudos_id,
			$giver
				/* translators: %s: display name of the member who gave the kudos. */
				? sprintf( __( '%s gave you kudos.', 'wb-gamification' ), $giver->display_name )
				: __( 'Someone gave you kudos.', 'wb-gamification' ),
			self::notification_url( $receiver_id ),
			'kudos_received_' . $kudos_id,
			$giver_id
		);
	}

	/**
	 * A revoked kudos takes its notification with it.
	 *
	 * KudosEngine.php fires: do_action( 'wb_gam_kudos_revoked', $kudos_id, $giver_id, $receiver_id, $reason, $admin_id ).
	 *
	 * @param int $kudos_id Revoked kudos row id.
	 */
	public static function on_kudos_revoked( int $kudos_id ): void {
		if ( $kudos_id > 0 ) {
			do_action( 'wb_gam_community_notification_removed', 'kudos', $kudos_id );
		}
	}

	/**
	 * ChallengeEngine.php fires: do_action( 'wb_gam_challenge_completed', $user_id, $challenge ).
	 *
	 * @param int   $user_id   Member who completed the challenge.
	 * @param array $challenge Challenge row (id, title, …).
	 */
	public static function on_challenge_completed( int $user_id, array $challenge = array() ): void {
		$id    = (int) ( $challenge['id'] ?? 0 );
		$title = (string) ( $challenge['title'] ?? '' );
		self::notify(
			$user_id,
			'challenge_completed',
			'challenge',
			$id,
			'' !== $title
				/* translators: %s: challenge title. */
				? sprintf( __( 'You completed %s.', 'wb-gamification' ), $title )
				: __( 'You completed a challenge.', 'wb-gamification' ),
			self::notification_url( $user_id ),
			'challenge_completed_' . $id
		);
	}

	/**
	 * RedemptionEngine.php fires: do_action( 'wb_gam_redemption_fulfilled', $redemption_id, $user_id ).
	 * WB Gamification's own email (TransactionalEmailEngine) fires on the
	 * REDEMPTION REQUEST (`wb_gam_points_redeemed`), not on fulfilment — there
	 * is no existing "your reward is ready" email to preserve or duplicate.
	 *
	 * @param int $redemption_id Redemption row id.
	 * @param int $user_id       Member the reward belongs to.
	 */
	public static function on_redemption_fulfilled( int $redemption_id, int $user_id ): void {
		$hub_url = self::hub_url();
		self::notify(
			$user_id,
			'reward_fulfilled',
			'redemption',
			$redemption_id,
			__( 'Your reward is ready.', 'wb-gamification' ),
			'' !== $hub_url ? $hub_url : self::notification_url( $user_id ),
			'reward_fulfilled_' . $redemption_id
		);
	}

	/**
	 * CredentialExpiryEngine.php fires: do_action( 'wb_gam_credential_expired', $user_id, $badge_id ).
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Expired badge/credential slug.
	 */
	public static function on_credential_expired( int $user_id, string $badge_id ): void {
		$def  = BadgeEngine::get_badge_def( $badge_id );
		$name = $def ? (string) $def['name'] : '';
		self::notify(
			$user_id,
			'credential_expired',
			'badge',
			self::badge_object_id( $badge_id ),
			'' !== $name
				/* translators: %s: badge name. */
				? sprintf( __( 'Your %s credential has expired.', 'wb-gamification' ), $name )
				: __( 'A badge credential of yours has expired.', 'wb-gamification' ),
			self::notification_url( $user_id ),
			'credential_expired_' . sanitize_key( $badge_id )
		);
	}

	/**
	 * PersonalRecordEngine.php fires: do_action( 'wb_gam_personal_record', $user_id, $period, $current, $previous, $message )
	 * on every award that lifts a running total past the previous best, so an
	 * active member's record can fire many times a day. Rather than one bell
	 * row per fire, every fire in the same period bucket (site-calendar day /
	 * week / month, via Clock) shares one `group_key` and says renotify => false:
	 * the first fire creates the member's row, every later one refreshes its text
	 * quietly (no re-surfacing, no push), so the member sees ONE row per bucket,
	 * current, however many times this fires underneath it.
	 *
	 * Matches the default the retired BuddyNext bridge applied (own decision,
	 * now made once, here, for every host): a daily best happens most days for
	 * an active member and is not news, and a first record over a trivial
	 * previous best is skipped. `wb_gam_personal_record_notify` lets a site
	 * change that.
	 *
	 * @param int    $user_id  Member.
	 * @param string $period   'day' | 'week' | 'month'.
	 * @param int    $current  New best.
	 * @param int    $previous Previous best.
	 * @param string $message  This plugin's own sentence for the record.
	 */
	public static function on_personal_record( int $user_id, string $period = '', int $current = 0, int $previous = 0, string $message = '' ): void {
		$buckets = array(
			'day'   => static fn(): string => Clock::site_date(),
			'week'  => static fn(): string => Clock::site_week(),
			'month' => static fn(): string => substr( Clock::site_date(), 0, 7 ),
		);
		if ( ! isset( $buckets[ $period ] ) ) {
			return;
		}

		/**
		 * Filter whether a personal record reaches a member's community inbox.
		 *
		 * Default: week and month records whose previous best was at least 10.
		 *
		 * @since 1.6.5
		 *
		 * @param bool   $notify   Whether to notify.
		 * @param int    $user_id  Member.
		 * @param string $period   'day' | 'week' | 'month'.
		 * @param int    $current  New best.
		 * @param int    $previous Previous best.
		 */
		$notify = (bool) apply_filters( 'wb_gam_personal_record_notify', 'day' !== $period && $previous >= 10, $user_id, $period, $current, $previous );
		if ( ! $notify ) {
			return;
		}

		self::notify(
			$user_id,
			'personal_record',
			'',
			0,
			'' !== $message ? $message : __( 'You set a new personal best.', 'wb-gamification' ),
			self::notification_url( $user_id ),
			'personal_record_' . $period . '_' . $buckets[ $period ](),
			0,
			false // A running notice: later bests in the bucket refresh the row quietly.
		);
	}

	/**
	 * StreakEngine.php fires: do_action( 'wb_gam_streak_milestone', $user_id, $streak_days ).
	 *
	 * @param int $user_id     Member.
	 * @param int $streak_days Streak length.
	 */
	public static function on_streak_milestone( int $user_id, int $streak_days ): void {
		self::notify(
			$user_id,
			'streak_milestone',
			'',
			0,
			sprintf(
				/* translators: %s: number of days in the streak. */
				_n( '%s-day streak. Keep it going!', '%s-day streak. Keep it going!', $streak_days, 'wb-gamification' ),
				number_format_i18n( $streak_days )
			),
			self::notification_url( $user_id ),
			'streak_milestone_' . $streak_days
		);
	}

	/**
	 * A badge definition was permanently deleted — remove every notification
	 * that named it, for every member who had one.
	 *
	 * @param string $badge_id Deleted badge slug.
	 */
	public static function on_badge_deleted( string $badge_id ): void {
		if ( '' === $badge_id ) {
			return;
		}
		do_action( 'wb_gam_community_notification_removed', 'badge', self::badge_object_id( $badge_id ) );
	}

	// ── Contract plumbing ───────────────────────────────────────────────────────

	/**
	 * Build the payload and fire the contract hook. The ONE place every
	 * handler above funnels through, so there is exactly one payload shape,
	 * one self-notify guard and one import guard for all eight event types.
	 *
	 * @param int    $user_id     Recipient.
	 * @param string $type        This plugin's own subtype slug.
	 * @param string $object_type 'badge' | 'level' | 'kudos' | 'challenge' | 'redemption' | ''.
	 * @param int    $object_id   Object id, or 0 when the type has no discrete object.
	 * @param string $message     Translated, plain-text sentence.
	 * @param string $url         Deep link.
	 * @param string $group_key   Merge key, per object + subtype, never per actor.
	 * @param int    $actor_id    Acting member, or 0 for a system/engine event.
	 * @param bool   $renotify    False for a running notice: a repeat with the same
	 *                            group_key refreshes the member's row without alerting.
	 */
	private static function notify( int $user_id, string $type, string $object_type, int $object_id, string $message, string $url, string $group_key, int $actor_id = 0, bool $renotify = true ): void {
		if ( $user_id <= 0 || '' === $message || '' === $url || ImportMode::is_active() || ( $actor_id > 0 && $actor_id === $user_id ) ) {
			return;
		}

		do_action(
			'wb_gam_notification_created',
			array(
				'recipient_id' => $user_id,
				'type'         => $type,
				'actor_id'     => $actor_id,
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'message'      => $message,
				'url'          => $url,
				'group_key'    => $group_key,
				'renotify'     => $renotify,
			)
		);
	}

	/**
	 * Where a member's own notification should link: the canonical, single
	 * "where does this member's name/profile link to" resolver
	 * ({@see MemberUrl::resolve()}) — BuddyPress when active, else this
	 * plugin's own public profile, else whatever `wb_gam_member_url` answers
	 * (the filter BuddyNext's GamificationBridge already hooks to point every
	 * member link at the BuddyNext profile). Falls back to the hub page, then
	 * the site home, only when that resolver has nothing (private profile, no
	 * BuddyPress, profiles switched off) — the achievement still happened, so
	 * the notification still gets a working link rather than being dropped for
	 * want of one.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	private static function notification_url( int $user_id ): string {
		$url = MemberUrl::resolve( $user_id );
		if ( '' !== $url ) {
			return $url;
		}
		$hub_url = self::hub_url();
		return '' !== $hub_url ? $hub_url : home_url( '/' );
	}

	/**
	 * This plugin's own hub page (leaderboard, rewards), or '' when unset or unpublished.
	 *
	 * @return string
	 */
	private static function hub_url(): string {
		$page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		return ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * A stable integer id for a badge slug, for the two contract types keyed on
	 * a badge (`badge_awarded`, `credential_expired`) and for their removal.
	 * `wb_gam_badge_defs.id` is a VARCHAR slug, not an int, and the contract's
	 * object_id (and BuddyNext's removal match) is typed int — so the slug is
	 * hashed rather than dropped. crc32() is deterministic and stable for a
	 * given slug for as long as the badge exists.
	 *
	 * Known limit: a 32-bit hash, so two badge slugs colliding on the same
	 * crc32 value would share removal scope. Revisit only if that is ever
	 * observed on a real catalogue (crc32 across a few hundred slugs is not a
	 * realistic collision risk).
	 *
	 * @param string $badge_id Badge slug.
	 * @return int
	 */
	private static function badge_object_id( string $badge_id ): int {
		return (int) crc32( $badge_id );
	}

	// ── Types + visibility ──────────────────────────────────────────────────────

	/**
	 * Declare every type this plugin fires through the contract.
	 *
	 * @param array<string,array<string,mixed>> $types Incoming types.
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter_types( array $types ): array {
		$defs = array(
			'badge_awarded'       => array(
				'label'       => __( 'Badges earned', 'wb-gamification' ),
				'description' => __( 'You earned a new badge.', 'wb-gamification' ),
			),
			'level_up'            => array(
				'label'       => __( 'Level-ups', 'wb-gamification' ),
				'description' => __( 'You reached a new level.', 'wb-gamification' ),
			),
			'kudos_received'      => array(
				'label'       => __( 'Kudos', 'wb-gamification' ),
				'description' => __( 'A member gave you kudos.', 'wb-gamification' ),
			),
			'challenge_completed' => array(
				'label'       => __( 'Challenges completed', 'wb-gamification' ),
				'description' => __( 'You completed a challenge.', 'wb-gamification' ),
			),
			'reward_fulfilled'    => array(
				'label'       => __( 'Rewards ready', 'wb-gamification' ),
				'description' => __( 'A reward you redeemed is ready.', 'wb-gamification' ),
			),
			'credential_expired'  => array(
				'label'       => __( 'Expired credentials', 'wb-gamification' ),
				'description' => __( 'A badge credential of yours expired.', 'wb-gamification' ),
			),
			'personal_record'     => array(
				'label'       => __( 'Personal records', 'wb-gamification' ),
				'description' => __( 'You set a new personal best.', 'wb-gamification' ),
			),
			'streak_milestone'    => array(
				'label'       => __( 'Streak milestones', 'wb-gamification' ),
				'description' => __( 'You reached a streak milestone.', 'wb-gamification' ),
			),
		);

		foreach ( $defs as $slug => $def ) {
			$types[ $slug ] = array(
				'label'       => $def['label'],
				'description' => $def['description'],
				'default_on'  => true,
			);
		}

		return $types;
	}

	/**
	 * Answer which of a member's own rows the viewer (always the recipient —
	 * these are notifications about the viewer's own points, badges, levels
	 * and streaks) may still see.
	 *
	 * Every type here is the recipient's OWN achievement; the plugin holds no
	 * "trashed"/"private"/"unpublished" state for points, levels, challenges,
	 * redemptions or streaks (there is nothing to hide behind — a level or a
	 * streak is either current or superseded, never withdrawn). The one
	 * cross-member case is `kudos_received`, naming the giver: reused via
	 * `Privacy::can_view_public_profile()`, the same predicate this plugin
	 * already uses everywhere else a member's identity is shown to someone
	 * else (recent-kudos feed, leaderboard). A deleted badge definition is not
	 * answered here — it is REMOVED outright by on_badge_deleted() above.
	 *
	 * @param array<int|string,bool>                                                                 $visible   Every key starts true.
	 * @param int                                                                                    $viewer_id Recipient viewing their bell.
	 * @param array<int|string,array{type?:string,object_type?:string,object_id?:int,actor_id?:int}> $targets   Rows on this page.
	 * @return array<int|string,bool>
	 */
	public static function filter_visible( array $visible, int $viewer_id, array $targets ): array {
		foreach ( $targets as $key => $target ) {
			if ( 'kudos_received' !== (string) ( $target['type'] ?? '' ) ) {
				continue;
			}
			$actor_id = (int) ( $target['actor_id'] ?? 0 );
			if ( $actor_id > 0 && ! Privacy::can_view_public_profile( $actor_id, $viewer_id ) ) {
				$visible[ $key ] = false;
			}
		}
		return $visible;
	}
}
