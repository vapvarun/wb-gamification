<?php
/**
 * WB Gamification Engine
 *
 * Single entry point for all gamification events.
 * All award paths flow through Engine::process() — never directly
 * to PointsEngine::award().
 *
 * Pipeline:
 *   1. Validate event
 *   2. Action-enabled + rate-limit checks  (registered actions only)
 *   3. Enrich metadata via filter
 *   4. Before-evaluate gate (can abort)
 *   5. Persist to wb_gam_events  (immutable source of truth)
 *   6. Calculate points  (option + RuleEngine multipliers)
 *   7. Write to wb_gam_points  (with event_id FK)
 *   8. Fire hooks + dispatch webhooks
 *   9. Level-up + streak updates
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class Engine {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		WebhookDispatcher::init();

		// Action Scheduler handler for async event processing.
		add_action( 'wb_gam_process_event_async', array( __CLASS__, 'handle_async' ) );
	}

	/**
	 * Queue event processing asynchronously via Action Scheduler.
	 *
	 * Use for high-volume repeatable actions where synchronous processing would
	 * add latency on the request path. Rate limits are checked synchronously
	 * before queueing so obviously-blocked actions are rejected immediately.
	 *
	 * Falls back to synchronous processing if Action Scheduler is unavailable.
	 *
	 * Note: There is a narrow race window between the sync rate-limit check and
	 * the actual DB write (which happens when AS processes the job). In practice
	 * this is harmless for cooldown-based limits because the AS queue is ordered
	 * per group and jobs run sequentially.
	 *
	 * @param Event $event The event to queue.
	 * @return bool True if queued (or synchronously processed as fallback).
	 */
	public static function process_async( Event $event ): bool {
		if ( $event->user_id <= 0 || '' === $event->action_id ) {
			return false;
		}

		// Sync gate: validate + rate-limit checks (fast, no writes).
		$action = Registry::get_action( $event->action_id );
		if ( null !== $action ) {
			if ( ! (bool) get_option( 'wb_gam_enabled_' . $event->action_id, true ) ) {
				return false;
			}
			if ( ! PointsEngine::passes_rate_limits( $event->user_id, $event->action_id, $action ) ) {
				return false;
			}
		}

		// Fallback: synchronous if AS not available (e.g. unit tests, early boot).
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return self::process( $event );
		}

		as_enqueue_async_action(
			'wb_gam_process_event_async',
			array(
				array(
					'action_id'  => $event->action_id,
					'user_id'    => $event->user_id,
					'object_id'  => $event->object_id,
					'metadata'   => $event->metadata,
					'event_id'   => $event->event_id,
					'created_at' => $event->created_at,
				),
			),
			'wb-gamification'
		);

		return true;
	}

	/**
	 * Action Scheduler callback — reconstructs Event and runs full pipeline.
	 *
	 * @param array $event_data Serialised event data passed by process_async().
	 */
	public static function handle_async( array $event_data ): void {
		if ( empty( $event_data['action_id'] ) || empty( $event_data['user_id'] ) ) {
			return;
		}

		self::process( new Event( $event_data ) );
	}

	/**
	 * Process a gamification event through the full rule pipeline.
	 *
	 * @param Event $event The normalised event to process.
	 * @return bool        True if points were awarded.
	 */
	public static function process( Event $event ): bool {
		if ( $event->user_id <= 0 || '' === $event->action_id ) {
			return false;
		}

		$action = Registry::get_action( $event->action_id );

		// Registered-action checks: enabled + rate limits.
		if ( null !== $action ) {
			if ( ! (bool) get_option( 'wb_gam_enabled_' . $event->action_id, true ) ) {
				return false;
			}
			if ( ! PointsEngine::passes_rate_limits( $event->user_id, $event->action_id, $action ) ) {
				return false;
			}
		}

		/**
		 * Enrich event metadata before rule evaluation.
		 * Add quality signals, word counts, AI scores, etc.
		 *
		 * @param array<string, mixed> $metadata Current metadata.
		 * @param Event                $event    The event being processed.
		 */
		$enriched = (array) apply_filters( 'wb_gamification_event_metadata', $event->metadata, $event );

		if ( $enriched !== $event->metadata ) {
			$event = new Event(
				array(
					'action_id'  => $event->action_id,
					'user_id'    => $event->user_id,
					'object_id'  => $event->object_id,
					'metadata'   => $enriched,
					'created_at' => $event->created_at,
					'event_id'   => $event->event_id,
				)
			);
		}

		/**
		 * Gate to block event processing.
		 *
		 * Return false to abort without recording anything.
		 *
		 * @param bool  $proceed Whether to proceed.
		 * @param Event $event   The event.
		 */
		if ( ! (bool) apply_filters( 'wb_gamification_before_evaluate', true, $event ) ) {
			return false;
		}

		// Persist the raw event — source of truth, never deleted (except GDPR).
		self::persist_event( $event );

		// Determine base points.
		if ( null !== $action ) {
			$points = (int) get_option( 'wb_gam_points_' . $event->action_id, $action['default_points'] );
		} else {
			// Manual / unregistered awards carry the points value in metadata.
			$points = (int) ( $event->metadata['points'] ?? 0 );
		}

		/**
		 * Filter points before rule multipliers are applied.
		 *
		 * Quality signals (word_count, activity_type, etc.) are available in
		 * $event->metadata when the action registered a metadata_callback.
		 *
		 * @param int    $points    Base points (from admin option or action default).
		 * @param string $action_id Action ID.
		 * @param int    $user_id   User ID.
		 * @param Event  $event     Full event object (metadata available).
		 */
		$points = (int) apply_filters( 'wb_gamification_points_for_action', $points, $event->action_id, $event->user_id, $event );

		// Apply any stored rule multipliers (day-of-week, order-total, etc.).
		$points = RuleEngine::apply_multipliers( $points, $event );

		if ( $points <= 0 ) {
			return false;
		}

		// Write the derived ledger row.
		if ( ! PointsEngine::insert_point_row( $event, $points ) ) {
			return false;
		}

		// Bust the per-user total cache.
		wp_cache_delete( "wb_gam_total_{$event->user_id}", 'wb_gamification' );

		/**
		 * Fires after points are awarded.
		 *
		 * ⚠ BREAKING CHANGE (Phase 0):
		 *   Old: ( int $user_id, string $action_id, int $points )
		 *   New: ( int $user_id, Event  $event,     int $points )
		 *   Use $event->action_id for the action ID; full metadata available via $event->metadata.
		 *
		 * @param int   $user_id User who earned the points.
		 * @param Event $event   Full event object.
		 * @param int   $points  Points awarded.
		 */
		do_action( 'wb_gamification_points_awarded', $event->user_id, $event, $points );

		// Side-effects.
		LevelEngine::maybe_level_up( $event->user_id );
		StreakEngine::record_activity( $event->user_id );
		WebhookDispatcher::dispatch( 'points_awarded', $event->user_id, $event, $points, array() );

		return true;
	}

	/**
	 * Persist a raw event to the immutable event log.
	 *
	 * The event log is the source of truth for all derived state.
	 * Points, badges, and levels can all be replayed from this table.
	 */
	private static function persist_event( Event $event ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_events',
			array(
				'id'         => $event->event_id,
				'user_id'    => $event->user_id,
				'action_id'  => $event->action_id,
				'object_id'  => $event->object_id ?: null,
				'metadata'   => ! empty( $event->metadata ) ? wp_json_encode( $event->metadata ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}
}
