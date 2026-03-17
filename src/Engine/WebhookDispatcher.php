<?php
/**
 * WB Gamification Webhook Dispatcher
 *
 * Fires HMAC-signed outbound webhooks on gamification events.
 * Delivery is queued via Action Scheduler to avoid blocking page loads.
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

final class WebhookDispatcher {

	private const AS_ACTION = 'wb_gam_send_webhook';

	/**
	 * Register the Action Scheduler delivery hook.
	 * Called once during Engine::init().
	 */
	public static function init(): void {
		add_action( self::AS_ACTION, [ self::class, 'deliver' ], 10, 4 );
	}

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
		array $extra_data = []
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
				[
					'action_id' => $event ? $event->action_id : null,
					'event_id'  => $event ? $event->event_id  : null,
					'object_id' => $event ? ( $event->object_id ?: null ) : null,
					'metadata'  => $event ? ( $event->metadata ?: null ) : null,
					'points'    => $points > 0 ? $points : null,
				],
				$extra_data
			)
		);

		$payload = wp_json_encode(
			[
				'event'      => $event_type,
				'site_url'   => get_site_url(),
				'timestamp'  => $event ? $event->created_at : gmdate( 'Y-m-d\TH:i:s\Z' ),
				'user_id'    => $user_id,
				'user_email' => $user ? $user->user_email : '',
				'data'       => $data,
			]
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
				[ (int) $webhook['id'], $webhook['url'], $signature, $payload ],
				'wb-gamification'
			);
		}
	}

	/**
	 * Deliver a single webhook payload via HTTP POST.
	 *
	 * Called by Action Scheduler. Throwing an exception causes AS to retry.
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
		$response = wp_remote_post(
			$url,
			[
				'body'    => $payload,
				'headers' => [
					'Content-Type'       => 'application/json',
					'X-WB-Gam-Signature' => 'sha256=' . $signature,
					'X-WB-Gam-Site'      => get_site_url(),
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			// Throw so Action Scheduler marks the action as failed and retries.
			throw new \RuntimeException(
				sprintf(
					'WB Gamification webhook delivery failed (ID %d): %s',
					$webhook_id,
					$response->get_error_message()
				)
			);
		}
	}

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
