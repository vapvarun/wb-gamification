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
		add_action( 'wb_gam_kudos_given', array( self::class, 'on_kudos_given' ), 50, 3 );
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
	 * @param int    $giver_id    User who gave kudos.
	 * @param int    $receiver_id User who received kudos.
	 * @param string $message     Kudos message.
	 */
	public static function on_kudos_given( int $giver_id, int $receiver_id, string $message ): void {
		self::dispatch(
			'kudos_given',
			$giver_id,
			null,
			0,
			array(
				'receiver_id' => $receiver_id,
				'message'     => $message,
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

		$data = array_filter(
			array_merge(
				array(
					'action_id' => $event ? $event->action_id : null,
					'event_id'  => $event ? $event->event_id : null,
					'object_id' => $event ? ( $event->object_id ?: null ) : null,
					'metadata'  => $event ? ( $event->metadata ?: null ) : null,
					'points'    => $points > 0 ? $points : null,
				),
				$extra_data
			)
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
		$decoded     = json_decode( $payload, true );
		$event_type  = $decoded['event'] ?? 'unknown';

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
			self::maybe_schedule_retry( $webhook_id, $url, $signature, $payload, 0 );
			return;
		}

		self::log_delivery( $webhook_id, $event_type, $status_code, true );
	}

	/**
	 * Handle a scheduled retry delivery.
	 *
	 * @param int   $webhook_id  DB ID of the webhook.
	 * @param array $meta        Retry metadata containing url, signature, payload, retry_count.
	 * @param int   $retry_count Current retry attempt number (1-based).
	 */
	public static function handle_retry( int $webhook_id, array $meta, int $retry_count ): void {
		$url       = $meta['url'] ?? '';
		$signature = $meta['signature'] ?? '';
		$payload   = $meta['payload'] ?? '';
		$decoded   = json_decode( $payload, true );
		$event     = $decoded['event'] ?? 'unknown';

		if ( empty( $url ) || empty( $payload ) ) {
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
