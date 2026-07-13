<?php
/**
 * WB Gamification Webhook Dispatcher
 *
 * Fires HMAC-signed outbound webhooks on gamification events.
 * Delivery is queued via Action Scheduler to avoid blocking page loads.
 * Failed deliveries are retried up to 3 times with exponential back-off.
 *
 * Supported event types:
 *   points_awarded | badge_earned | level_changed
 *   streak_milestone | challenge_completed | kudos_given
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Fires HMAC-signed outbound webhooks on gamification events via Action Scheduler.
 *
 * @package WB_Gamification
 */
final class WebhookDispatcher {

	private const AS_ACTION       = 'wb_gam_send_webhook';
	private const AS_RETRY_ACTION = 'wb_gam_webhook_retry';
	private const MAX_RETRIES     = 3;
	private const LOG_MAX_ENTRIES = 50;

	/**
	 * Register Action Scheduler delivery and retry hooks,
	 * plus WordPress action listeners for all supported event types.
	 *
	 * Called once during Engine::init().
	 */
	public static function init(): void {
		// Primary delivery.
		add_action( self::AS_ACTION, array( self::class, 'deliver' ), 10, 4 );

		// Retry delivery.
		add_action( self::AS_RETRY_ACTION, array( self::class, 'handle_retry' ), 10, 3 );

		// Wire the four event types that aren't dispatched inline by their engines.
		add_action( 'wb_gam_level_changed', array( self::class, 'on_level_changed' ), 50, 3 );
		add_action( 'wb_gam_streak_milestone', array( self::class, 'on_streak_milestone' ), 50, 2 );
		add_action( 'wb_gam_challenge_completed', array( self::class, 'on_challenge_completed' ), 50, 2 );
		// kudos_given fires with 4 args (giver, receiver, message, kudos_id);
		// pre-1.4.1 the dispatcher only accepted 3, so kudos_id silently
		// dropped from outbound webhook payloads — subscribers couldn't
		// dedupe retries against a kudos row id or re-fetch the row from
		// `/wb-gamification/v1/kudos/{id}`. Closes audit
		// DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G12.
		add_action( 'wb_gam_kudos_given', array( self::class, 'on_kudos_given' ), 50, 4 );

		// Redemption event — Zapier/Make/n8n integrations want a webhook
		// when a member redeems a reward. Closes audit
		// DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G2. TransactionalEmailEngine
		// already subscribes to the same hook (commit ab7e79e); both run
		// in parallel as independent consumers.
		add_action( 'wb_gam_points_redeemed', array( self::class, 'on_redemption' ), 50, 4 );
	}

	// ── Hook callbacks for wired event types ────────────────────────────────────

	/**
	 * Dispatch a webhook when a user's level changes.
	 *
	 * @param int        $user_id   User whose level changed.
	 * @param array|null $new_level New level data (id, name, min_points).
	 * @param array|null $old_level Previous level data.
	 */
	public static function on_level_changed( int $user_id, ?array $new_level, ?array $old_level ): void {
		self::dispatch(
			'level_changed',
			$user_id,
			null,
			0,
			array(
				'new_level_id'   => $new_level['id'] ?? null,
				'new_level_name' => $new_level['name'] ?? null,
				'old_level_id'   => $old_level['id'] ?? null,
				'old_level_name' => $old_level['name'] ?? null,
			)
		);
	}

	/**
	 * Dispatch a webhook when a user reaches a streak milestone.
	 *
	 * @param int $user_id     User who reached the milestone.
	 * @param int $streak_days Current streak day count.
	 */
	public static function on_streak_milestone( int $user_id, int $streak_days ): void {
		self::dispatch(
			'streak_milestone',
			$user_id,
			null,
			0,
			array( 'streak_days' => $streak_days )
		);
	}

	/**
	 * Dispatch a webhook when a user completes a challenge.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Challenge data array.
	 */
	public static function on_challenge_completed( int $user_id, array $challenge ): void {
		self::dispatch(
			'challenge_completed',
			$user_id,
			null,
			0,
			array(
				'challenge_id'   => $challenge['id'] ?? null,
				'challenge_name' => $challenge['title'] ?? ( $challenge['name'] ?? null ),
			)
		);
	}

	/**
	 * Dispatch a webhook when kudos are given.
	 *
	 * Dispatch a webhook when a user redeems a reward.
	 *
	 * Subscribes to `wb_gam_points_redeemed` — same hook the transactional
	 * email engine listens on. Both consumers fire independently. Closes
	 * audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G2.
	 *
	 * @param int         $redemption_id Row id in `wb_gam_redemptions`.
	 * @param int         $user_id       User who redeemed.
	 * @param array       $item          Reward item snapshot (id, title, points_cost, reward_type, ...).
	 * @param string|null $coupon_code Generated coupon code (WC types) or null.
	 */
	public static function on_redemption( int $redemption_id, int $user_id, array $item, ?string $coupon_code = null ): void {
		self::dispatch(
			'redemption',
			$user_id,
			null,
			0,
			array(
				'redemption_id' => $redemption_id,
				'item_id'       => (int) ( $item['id'] ?? 0 ),
				'item_title'    => (string) ( $item['title'] ?? '' ),
				'points_cost'   => (int) ( $item['points_cost'] ?? 0 ),
				'reward_type'   => (string) ( $item['reward_type'] ?? '' ),
				'coupon_code'   => $coupon_code,
			)
		);
	}

	/**
	 * @param int    $giver_id    User who gave kudos.
	 * @param int    $receiver_id User who received kudos.
	 * @param string $message     Kudos message.
	 * @param int    $kudos_id    DB row id (added 1.4.1 — see §G12). Optional
	 *                            default 0 so legacy 3-arg fires don't fatal.
	 */
	public static function on_kudos_given( int $giver_id, int $receiver_id, string $message, int $kudos_id = 0 ): void {
		self::dispatch(
			'kudos_given',
			$giver_id,
			null,
			0,
			array(
				'receiver_id' => $receiver_id,
				'message'     => $message,
				'kudos_id'    => $kudos_id,
			)
		);
	}

	// ── Core dispatch & delivery ────────────────────────────────────────────────

	/**
	 * Queue outbound webhooks for a gamification event.
	 *
	 * Reads active webhook registrations from wb_gam_webhooks and schedules
	 * an async Action Scheduler task for each matching subscription.
	 *
	 * @param string     $event_type  Gamification event type.
	 * @param int        $user_id     User who triggered the event.
	 * @param Event|null $event       Full event object, or null for non-point events (e.g. badge_awarded).
	 * @param int        $points      Points awarded (relevant for points_awarded type).
	 * @param array      $extra_data  Additional event-specific data merged into the payload's data field.
	 * @as-fire-once One enqueue per (webhook subscription × event). The async
	 *               handler is self::deliver, which HTTP-POSTs and logs; it
	 *               does not call dispatch() again.
	 */
	public static function dispatch(
		string $event_type,
		int $user_id,
		?Event $event = null,
		int $points = 0,
		array $extra_data = array()
	): void {
		global $wpdb;

		$webhooks = $wpdb->get_results(
			"SELECT id, url, secret, events FROM {$wpdb->prefix}wb_gam_webhooks WHERE is_active = 1",
			ARRAY_A
		);

		if ( empty( $webhooks ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		// Deterministic payload shape: every key always present, with explicit
		// nulls for missing values. Pre-1.4.1 used `array_filter` which dropped
		// any empty / zero / false / null entry — so subscribers saw a
		// different field set per call and couldn't write a stable schema.
		// HMAC signature reproducibility also depends on this — the same
		// logical event must produce the same bytes. Closes audit
		// DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G14.
		$data = array_merge(
			array(
				'action_id' => $event ? $event->action_id : null,
				'event_id'  => $event ? $event->event_id : null,
				'object_id' => $event && $event->object_id > 0 ? $event->object_id : null,
				'metadata'  => $event ? $event->metadata : null,
				'points'    => $points > 0 ? $points : null,
			),
			$extra_data
		);

		$payload = wp_json_encode(
			array(
				'event'      => $event_type,
				'site_url'   => get_site_url(),
				'timestamp'  => $event ? $event->created_at : gmdate( 'Y-m-d\TH:i:s\Z' ),
				'user_id'    => $user_id,
				'user_email' => $user ? $user->user_email : '',
				'data'       => $data,
			)
		);

		if ( false === $payload ) {
			return;
		}

		foreach ( $webhooks as $webhook ) {
			$subscribed = json_decode( $webhook['events'], true );
			if ( ! is_array( $subscribed ) || ! in_array( $event_type, $subscribed, true ) ) {
				continue;
			}

			$signature = self::sign( $payload, $webhook['secret'] );

			as_enqueue_async_action(
				self::AS_ACTION,
				array( (int) $webhook['id'], $webhook['url'], $signature, $payload ),
				'wb-gamification'
			);
		}
	}

	/**
	 * Deliver a single webhook payload via HTTP POST.
	 *
	 * On success the delivery is logged. On failure a retry is scheduled
	 * with exponential back-off (2 min, 4 min, 8 min) up to 3 attempts.
	 *
	 * @param int    $webhook_id  DB ID of the webhook registration (for logging).
	 * @param string $url         Destination URL.
	 * @param string $signature   HMAC-SHA256 hex digest of the payload.
	 * @param string $payload     JSON-encoded payload body.
	 */
	public static function deliver(
		int $webhook_id,
		string $url,
		string $signature,
		string $payload
	): void {
		$decoded    = json_decode( $payload, true );
		$event_type = $decoded['event'] ?? 'unknown';

		$response = wp_remote_post(
			$url,
			array(
				'body'    => $payload,
				'headers' => array(
					'Content-Type'       => 'application/json',
					'X-WB-Gam-Signature' => 'sha256=' . $signature,
					'X-WB-Gam-Site'      => get_site_url(),
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log_delivery( $webhook_id, $event_type, 0, false );
			self::maybe_schedule_retry( $webhook_id, $url, $signature, $payload, 0 );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			self::log_delivery( $webhook_id, $event_type, $status_code, false );
			// Terminal 4xx — receiver permanently refuses (revoked endpoint,
			// bad secret, gone). Don't waste retries on something that won't
			// improve in 14 minutes. 408 / 425 / 429 are transient and DO
			// retry. Closes audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G6.
			if ( self::is_terminal_status( $status_code ) ) {
				Log::warning(
					'WebhookDispatcher: dropping retry on permanent 4xx response.',
					array(
						'webhook_id'  => $webhook_id,
						'event'       => $event_type,
						'status_code' => $status_code,
					)
				);
				return;
			}
			self::maybe_schedule_retry( $webhook_id, $url, $signature, $payload, 0 );
			return;
		}

		self::log_delivery( $webhook_id, $event_type, $status_code, true );
	}

	/**
	 * Whether a 4xx response should stop further retries.
	 *
	 * 4xx generally indicates a client error the receiver won't recover from
	 * in the retry window (revoked endpoint, wrong secret, gone). The three
	 * transient exceptions get retried like 5xx — 408 (request timeout),
	 * 425 (too early), 429 (too many requests).
	 *
	 * @since 1.4.1
	 *
	 * @param int $status_code HTTP status from the receiver.
	 * @return bool True for permanent 4xx; false for transient 4xx or 5xx.
	 */
	private static function is_terminal_status( int $status_code ): bool {
		if ( $status_code < 400 || $status_code >= 500 ) {
			return false;
		}
		return ! in_array( $status_code, array( 408, 425, 429 ), true );
	}

	/**
	 * Handle a scheduled retry delivery.
	 *
	 * Re-fetches the subscription row from `wb_gam_webhooks` on every
	 * retry — `(url, secret)` are read fresh so a secret rotation that
	 * happens between the initial dispatch and the eventual retry doesn't
	 * leave the retry holding a stale HMAC the receiver will reject. The
	 * payload is the only stable input (it represents a frozen moment in
	 * the event timeline); URL + signature get re-derived.
	 *
	 * If the subscription was deleted between dispatch and retry, the
	 * retry is dropped (no logging — nothing to log against).
	 *
	 * Closes audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G5.
	 *
	 * @param int   $webhook_id  DB ID of the webhook.
	 * @param array $meta        Retry metadata containing the original payload (URL + signature
	 *                           are now re-derived from the current row, ignored if present).
	 * @param int   $retry_count Current retry attempt number (1-based).
	 */
	public static function handle_retry( int $webhook_id, array $meta, int $retry_count ): void {
		$payload = $meta['payload'] ?? '';
		$decoded = json_decode( $payload, true );
		$event   = $decoded['event'] ?? 'unknown';

		if ( empty( $payload ) ) {
			return;
		}

		// Re-fetch current url + secret. If the subscription was deleted or disabled in between, drop
		// the retry.
		//
		// This selected a `status` column. There is no such column -- the table has `is_active`. MySQL
		// answered "Unknown column 'status' in 'field list'", $wpdb swallowed it, get_row() returned
		// NULL, and the guard below read that as "the subscription is gone" and returned.
		//
		// So EVERY webhook retry, on every site, was silently discarded. Not some. Every one. And it
		// looked exactly like a working system: the first delivery attempt still fired, the retry was
		// still scheduled, the job still ran -- and then quietly did nothing. The only symptom was a
		// customer's endpoint never receiving the delivery that failed the first time.
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT url, secret, is_active FROM {$wpdb->prefix}wb_gam_webhooks WHERE id = %d",
				$webhook_id
			),
			ARRAY_A
		);

		if ( ! $row || empty( $row['is_active'] ) ) {
			return;
		}

		$url       = (string) ( $row['url'] ?? '' );
		$secret    = (string) ( $row['secret'] ?? '' );
		$signature = hash_hmac( 'sha256', $payload, $secret );

		if ( '' === $url ) {
			return;
		}

		$response = wp_remote_post(
			$url,
			array(
				'body'    => $payload,
				'headers' => array(
					'Content-Type'       => 'application/json',
					'X-WB-Gam-Signature' => 'sha256=' . $signature,
					'X-WB-Gam-Site'      => get_site_url(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log_delivery( $webhook_id, $event, 0, false );
			self::maybe_schedule_retry( $webhook_id, $url, $signature, $payload, $retry_count );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			self::log_delivery( $webhook_id, $event, $status_code, false );
			// Same terminal-4xx logic as deliver() — don't keep retrying a
			// receiver that's permanently refusing. Audit §G6.
			if ( self::is_terminal_status( $status_code ) ) {
				Log::warning(
					'WebhookDispatcher: dropping retry on permanent 4xx response.',
					array(
						'webhook_id'  => $webhook_id,
						'event'       => $event,
						'status_code' => $status_code,
						'attempt'     => $retry_count,
					)
				);
				return;
			}
			self::maybe_schedule_retry( $webhook_id, $url, $signature, $payload, $retry_count );
			return;
		}

		self::log_delivery( $webhook_id, $event, $status_code, true );
	}

	// ── Retry scheduling ────────────────────────────────────────────────────────

	/**
	 * Schedule a retry with exponential back-off if retries remain.
	 *
	 * Delay: 2^retry_count minutes (2 min, 4 min, 8 min).
	 *
	 * @param int    $webhook_id  Webhook DB ID.
	 * @param string $url         Destination URL.
	 * @param string $signature   HMAC signature.
	 * @param string $payload     JSON payload.
	 * @param int    $current_retry Current retry count (0 = first failure).
	 * @as-fire-once Per-failure escalation. Bounded by self::MAX_RETRIES, the
	 *               next_retry guard above. Each call schedules exactly one
	 *               retry; the retry handler is self::handle_retry which
	 *               re-enters maybe_schedule_retry only after a new attempt
	 *               fails — never reflexively.
	 */
	private static function maybe_schedule_retry(
		int $webhook_id,
		string $url,
		string $signature,
		string $payload,
		int $current_retry
	): void {
		$next_retry = $current_retry + 1;
		if ( $next_retry > self::MAX_RETRIES ) {
			Log::error(
				'WebhookDispatcher: delivery permanently failed after exhausting retries.',
				array(
					'webhook_id' => $webhook_id,
					'url'        => $url,
					'attempts'   => $current_retry,
				)
			);
			return;
		}

		$delay = (int) pow( 2, $next_retry ) * 60; // 2min, 4min, 8min.

		as_schedule_single_action(
			time() + $delay,
			self::AS_RETRY_ACTION,
			array(
				$webhook_id,
				array(
					'url'       => $url,
					'signature' => $signature,
					'payload'   => $payload,
				),
				$next_retry,
			),
			'wb-gamification'
		);
	}

	// ── Delivery log ────────────────────────────────────────────────────────────

	/**
	 * Record a delivery attempt in the webhook's log.
	 *
	 * Stored as a non-autoloaded option. Each webhook keeps the last 50 entries.
	 *
	 * @param int    $webhook_id  Webhook DB ID.
	 * @param string $event       Event type that was dispatched.
	 * @param int    $status_code HTTP status code (0 for connection failures).
	 * @param bool   $success     Whether the delivery was successful.
	 */
	private static function log_delivery( int $webhook_id, string $event, int $status_code, bool $success ): void {
		$log_key = 'wb_gam_webhook_log_' . $webhook_id;
		$log     = get_option( $log_key, array() );

		array_unshift(
			$log,
			array(
				'event'       => $event,
				'status_code' => $status_code,
				'success'     => $success,
				'timestamp'   => current_time( 'mysql' ),
			)
		);

		// Keep only last N entries.
		$log = array_slice( $log, 0, self::LOG_MAX_ENTRIES );

		update_option( $log_key, $log, false );
	}

	/**
	 * Retrieve the delivery log for a webhook.
	 *
	 * @param int $webhook_id Webhook DB ID.
	 * @return array[] Array of log entries, newest first.
	 */
	public static function get_delivery_log( int $webhook_id ): array {
		return get_option( 'wb_gam_webhook_log_' . $webhook_id, array() );
	}

	/**
	 * Clear the delivery log for a webhook (e.g. on deletion).
	 *
	 * @param int $webhook_id Webhook DB ID.
	 */
	public static function clear_delivery_log( int $webhook_id ): void {
		delete_option( 'wb_gam_webhook_log_' . $webhook_id );
	}

	// ── Signing ─────────────────────────────────────────────────────────────────

	/**
	 * Generate an HMAC-SHA256 signature for a payload string.
	 *
	 * @param string $payload The JSON payload to sign.
	 * @param string $secret  The webhook secret key.
	 * @return string         Hex-encoded HMAC digest.
	 */
	private static function sign( string $payload, string $secret ): string {
		return hash_hmac( 'sha256', $payload, $secret );
	}
}
