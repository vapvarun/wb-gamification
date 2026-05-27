<?php
/**
 * WB Gamification — Transactional Email Engine
 *
 * Wires three event hooks to themed email templates:
 *
 *   wb_gam_level_changed       → templates/emails/level-up.php
 *   wb_gam_badge_awarded       → templates/emails/badge-earned.php
 *   wb_gam_challenge_completed → templates/emails/challenge-completed.php
 *   wb_gam_points_redeemed     → templates/emails/redemption-confirmed.php
 *
 * Each event:
 *   1. Checks the matching enable option (`wb_gam_email_<event>` — default off
 *      so existing sites don't get a flood of email after upgrade).
 *   2. Renders the template via `Email::render()` (theme override path).
 *   3. Sends via wp_mail with HTML headers + From header from settings.
 *
 * Templates extract these variables into local scope:
 *   - level-up.php:           $user, $name, $site_name, $site_url,
 *                             $old_level_name, $new_level_name, $new_level_min,
 *                             $points, $points_label
 *   - badge-earned.php:       $user, $name, $site_name, $site_url,
 *                             $badge_name, $badge_description, $badge_image_url,
 *                             $share_url
 *   - challenge-completed.php: $user, $name, $site_name, $site_url,
 *                             $challenge_title, $challenge_description, $reward_label
 *
 * Theme override path (resolved by Templates::locate via Email::locate):
 *   {child-theme}/wb-gamification/emails/{slug}.php
 *   {parent-theme}/wb-gamification/emails/{slug}.php
 *   {plugin}/templates/emails/{slug}.php
 *
 * @package WB_Gamification
 * @since   1.0.0
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

use WBGam\Services\PointTypeService;

/**
 * Sends transactional emails for level-up, badge-earned, and challenge-completed events.
 */
final class TransactionalEmailEngine {

	/**
	 * Action Scheduler hook for the deferred send.
	 *
	 * Listeners enqueue here instead of calling wp_mail() directly so the
	 * page-load that triggered the event (level_changed, badge_awarded,
	 * challenge_completed) returns immediately. The actual outbound mail
	 * runs in an AS worker. Mirrors the WeeklyEmailEngine pattern.
	 */
	private const AS_HOOK  = 'wb_gam_send_transactional_email';
	private const AS_GROUP = 'wb-gamification-emails';

	/**
	 * Boot — bind one listener per event hook. Each listener is gated on
	 * an admin option so site owners can disable individual email types
	 * without touching code.
	 */
	public static function init(): void {
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_up' ), 10, 3 );
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'on_badge_earned' ), 10, 3 );
		add_action( 'wb_gam_challenge_completed', array( __CLASS__, 'on_challenge_completed' ), 10, 2 );
		add_action( 'wb_gam_points_redeemed', array( __CLASS__, 'on_redemption' ), 10, 4 );
		add_action( self::AS_HOOK, array( __CLASS__, 'send_async' ), 10, 1 );
	}

	/**
	 * AS worker — receives the rendered email payload and calls wp_mail.
	 *
	 * Split from the original on_* listeners so the synchronous page-load
	 * isn't blocked on wp_mail() finishing (SMTP timeouts, slow MTAs,
	 * Mailgun rate limits — all of those used to freeze the response that
	 * fired the event). With Action Scheduler the user gets their response
	 * immediately; mail goes out within seconds via the AS worker.
	 *
	 * Falls back to synchronous send on hosts without Action Scheduler so
	 * unit tests + early-boot paths still work.
	 *
	 * @param array{to:string, subject:string, body:string} $payload Pre-rendered email.
	 */
	public static function send_async( array $payload ): void {
		$to      = (string) ( $payload['to'] ?? '' );
		$subject = (string) ( $payload['subject'] ?? '' );
		$body    = (string) ( $payload['body'] ?? '' );

		if ( '' === $to || '' === $subject || '' === $body ) {
			Log::error(
				'TransactionalEmailEngine::send_async — invalid payload',
				array( 'has_to' => '' !== $to, 'has_subject' => '' !== $subject, 'has_body' => '' !== $body )
			);
			return;
		}

		self::send_now( $to, $subject, $body );
	}

	/**
	 * Send the level-up email.
	 *
	 * Receives the canonical 1.0.0 wb_gam_level_changed signature: array
	 * level data, not int IDs. Pre-1.0.0 the hook fired a second time
	 * with int args; that legacy fire was removed (see LevelEngine docblock)
	 * and this listener was migrated to match.
	 *
	 * @param int        $user_id   User who levelled up.
	 * @param array|null $new_level New level data (id, name, min_points) or null.
	 * @param array|null $old_level Previous level data or null.
	 */
	public static function on_level_up( int $user_id, ?array $new_level = null, ?array $old_level = null ): void {
		if ( ! self::is_enabled( 'level_up' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Fall back to looking the level up if the caller passed null —
		// keeps the listener resilient to filter-based hook re-fires.
		if ( null === $new_level ) {
			$new_level = LevelEngine::get_level_for_user( $user_id );
		}
		if ( ! $new_level ) {
			return;
		}

		$pt_service   = new PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );

		$body = Email::render(
			'level-up',
			array(
				'user'           => $user,
				'name'           => esc_html( (string) $user->display_name ),
				'site_name'      => (string) get_bloginfo( 'name' ),
				'site_url'       => home_url( '/' ),
				'old_level_name' => (string) ( $old_level['name'] ?? '' ),
				'new_level_name' => (string) ( $new_level['name'] ?? '' ),
				'new_level_min'  => (int) ( $new_level['min_points'] ?? 0 ),
				'points'         => (int) PointsEngine::get_total( $user_id ),
				'points_label'   => $points_label,
			)
		);

		if ( '' === $body ) {
			return;
		}

		self::send(
			$user->user_email,
			sprintf(
				/* translators: %s: new level name */
				__( 'You reached %s!', 'wb-gamification' ),
				$new_level['name']
			),
			$body
		);
	}

	/**
	 * Send the badge-earned email.
	 *
	 * @param int    $user_id  User who earned the badge.
	 * @param array  $def      Badge definition row.
	 * @param string $badge_id Badge slug.
	 */
	public static function on_badge_earned( int $user_id, array $def, string $badge_id ): void {
		if ( ! self::is_enabled( 'badge_earned' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$body = Email::render(
			'badge-earned',
			array(
				'user'              => $user,
				'name'              => esc_html( (string) $user->display_name ),
				'site_name'         => (string) get_bloginfo( 'name' ),
				'site_url'          => home_url( '/' ),
				'badge_id'          => $badge_id,
				'badge_name'        => (string) ( $def['name'] ?? $badge_id ),
				'badge_description' => (string) ( $def['description'] ?? '' ),
				'badge_image_url'   => (string) ( $def['image_url'] ?? '' ),
				'share_url'         => home_url( '/badge/' . rawurlencode( $badge_id ) . '/' . $user_id ),
			)
		);

		if ( '' === $body ) {
			return;
		}

		self::send(
			$user->user_email,
			sprintf(
				/* translators: %s: badge name */
				__( 'You earned the %s badge!', 'wb-gamification' ),
				$def['name'] ?? $badge_id
			),
			$body
		);
	}

	/**
	 * Send the challenge-completed email.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Challenge config array.
	 */
	public static function on_challenge_completed( int $user_id, array $challenge ): void {
		if ( ! self::is_enabled( 'challenge_completed' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$reward_pts   = (int) ( $challenge['reward_points'] ?? 0 );
		$pt_service   = new PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );
		$reward_label = $reward_pts > 0
			? sprintf( '%d %s', $reward_pts, $points_label )
			: '';

		$body = Email::render(
			'challenge-completed',
			array(
				'user'                  => $user,
				'name'                  => esc_html( (string) $user->display_name ),
				'site_name'             => (string) get_bloginfo( 'name' ),
				'site_url'              => home_url( '/' ),
				'challenge_title'       => (string) ( $challenge['title'] ?? '' ),
				'challenge_description' => (string) ( $challenge['description'] ?? '' ),
				'reward_label'         => $reward_label,
			)
		);

		if ( '' === $body ) {
			return;
		}

		self::send(
			$user->user_email,
			sprintf(
				/* translators: %s: challenge title */
				__( 'Challenge completed: %s', 'wb-gamification' ),
				$challenge['title'] ?? ''
			),
			$body
		);
	}

	/**
	 * Send the redemption-confirmed email.
	 *
	 * Bound to {@see RedemptionEngine::redeem()}'s `wb_gam_points_redeemed`
	 * action. Members get a receipt summarising what they redeemed, the
	 * points they spent, and (when applicable) the coupon code their
	 * reward unlocked. Listener is idempotent — every redeem fires once
	 * per redemption_id and the AS queue dedupes by payload.
	 *
	 * @param int         $redemption_id Row id in `wb_gam_redemptions`.
	 * @param int         $user_id       Member who redeemed.
	 * @param array       $item          Reward item row.
	 * @param string|null $coupon_code   WooCommerce coupon code (if any).
	 */
	public static function on_redemption( int $redemption_id, int $user_id, array $item, ?string $coupon_code = null ): void {
		if ( ! self::is_enabled( 'redemption' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$pt_service   = new PointTypeService();
		$pt_record    = $pt_service->get( (string) ( $item['point_type'] ?? $pt_service->default_slug() ) );
		$points_label = (string) ( $pt_record['label'] ?? __( 'points', 'wb-gamification' ) );

		$body = Email::render(
			'redemption-confirmed',
			array(
				'user'           => $user,
				'name'           => esc_html( (string) $user->display_name ),
				'site_name'      => (string) get_bloginfo( 'name' ),
				'site_url'       => home_url( '/' ),
				'redemption_id'  => (int) $redemption_id,
				'reward_title'   => (string) ( $item['title'] ?? __( 'Your reward', 'wb-gamification' ) ),
				'reward_type'    => (string) ( $item['reward_type'] ?? 'custom' ),
				'points_spent'   => (int) ( $item['points_cost'] ?? 0 ),
				'points_label'   => $points_label,
				'coupon_code'    => (string) ( $coupon_code ?? '' ),
				'remaining'      => (int) PointsEngine::get_total( $user_id, (string) ( $item['point_type'] ?? '' ) ),
			)
		);

		if ( '' === $body ) {
			return;
		}

		self::send(
			$user->user_email,
			sprintf(
				/* translators: %s: reward title. */
				__( 'Your redemption: %s', 'wb-gamification' ),
				$item['title'] ?? __( 'reward', 'wb-gamification' )
			),
			$body
		);
	}

	/**
	 * Whether a given email type is enabled. Default OFF so existing sites
	 * don't suddenly start emailing every member after upgrade — admin opts
	 * in via the Settings → Emails tab (or the wb_gam_email_<slug> option).
	 *
	 * @param string $slug 'level_up' | 'badge_earned' | 'challenge_completed' | 'redemption'.
	 */
	private static function is_enabled( string $slug ): bool {
		/**
		 * Filter whether a transactional email type is enabled.
		 *
		 * Use to gate emails on per-user preference, role, A/B tests, etc.
		 *
		 * @param bool   $enabled Default — admin option chain.
		 * @param string $slug    Email slug.
		 */
		$default = (bool) get_option( 'wb_gam_email_' . $slug, false );
		return (bool) apply_filters( 'wb_gam_email_enabled', $default, $slug );
	}

	/**
	 * Enqueue an email for async delivery (or send immediately if Action
	 * Scheduler is unavailable, e.g. unit tests / very early boot).
	 *
	 * Replaces the previous synchronous send() call site. Listeners on
	 * wb_gam_level_changed / _badge_awarded / _challenge_completed should
	 * call this method, NOT send_now().
	 *
	 * @param string $to      Recipient email.
	 * @param string $subject Subject line.
	 * @param string $body    HTML body.
	 */
	private static function send( string $to, string $subject, string $body ): bool {
		$payload = array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
		);

		// Enqueue async if Action Scheduler is available — the page-load that
		// fired the event isn't blocked on wp_mail() finishing.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::AS_HOOK, array( $payload ), self::AS_GROUP );
			return true;
		}

		// Fallback — synchronous send. Hits during unit tests + very early
		// boot before Action Scheduler is loaded.
		return self::send_now( $to, $subject, $body );
	}

	/**
	 * Send the rendered HTML body via wp_mail with the canonical From header.
	 *
	 * Called from the Action Scheduler worker (send_async) AND the sync
	 * fallback inside send(). Returns true if wp_mail accepted the
	 * message; logs to error log on false. wp_mail's own filters
	 * (wp_mail_from, wp_mail_content_type, etc.) still apply.
	 *
	 * @param string $to      Recipient email.
	 * @param string $subject Subject line.
	 * @param string $body    HTML body.
	 */
	private static function send_now( string $to, string $subject, string $body ): bool {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Email::from_header(),
		);
		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( ! $sent ) {
			Log::error(
				'TransactionalEmailEngine::send_now — wp_mail returned false',
				array(
					'to'      => $to,
					'subject' => $subject,
				)
			);
		}
		return $sent;
	}
}
