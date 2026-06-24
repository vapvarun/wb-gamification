<?php
/**
 * Async Award Evaluator
 *
 * Reduces per-award synchronous DB queries by deferring non-critical
 * evaluators (PersonalRecordEngine and other heavy listeners) to a
 * single batched Action Scheduler job that runs after the request
 * completes.
 *
 * Flow:
 *   1. Hooked to `wb_gam_points_awarded` at priority 50
 *      (after sync listeners like BadgeEngine/ChallengeEngine, before
 *      NotificationBridge at 99).
 *   2. Collects award events in a static array during the request.
 *   3. On WordPress `shutdown` hook, enqueues ONE Action Scheduler
 *      async job containing all collected events.
 *   4. When AS processes the job, each registered evaluator callback
 *      is invoked for every event in the batch.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Batches non-critical award evaluators into a single async Action Scheduler job.
 *
 * @package WB_Gamification
 */
final class AsyncEvaluator {

	/**
	 * Action Scheduler hook name for the async batch job.
	 *
	 * @var string
	 */
	private const HOOK = 'wb_gam_async_evaluate';

	/**
	 * Queued award events collected during this request.
	 *
	 * @var array<int, array{user_id: int, action_id: string, object_id: int, metadata: array, points: int}>
	 */
	private static array $queue = [];

	/**
	 * Whether the shutdown flush has been registered for this request.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Evaluator callbacks to run asynchronously on each queued event.
	 *
	 * Callback signature: function( int $user_id, array $event_data, int $points ): void
	 *
	 * @var callable[]
	 */
	private static array $evaluators = [];

	/**
	 * Register the Action Scheduler handler for processing batched events.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ) );
	}

	/**
	 * Register an evaluator callback for async processing.
	 *
	 * Callback signature: function( int $user_id, array $event_data, int $points ): void
	 *
	 * @param callable $callback Evaluator to invoke on each batched event.
	 * @return void
	 */
	public static function register( callable $callback ): void {
		self::$evaluators[] = $callback;
	}

	/**
	 * Queue an award event for async evaluation.
	 *
	 * Called from the `wb_gam_points_awarded` hook at priority 50.
	 * The Event object is decomposed into a plain array because the readonly
	 * value object has no serialize support.
	 *
	 * @param int   $user_id User who just earned points.
	 * @param Event $event   The event that triggered the award.
	 * @param int   $points  Points awarded.
	 * @return void
	 */
	public static function enqueue( int $user_id, Event $event, int $points ): void {
		self::$queue[] = [
			'user_id'   => $user_id,
			'action_id' => $event->action_id,
			'object_id' => $event->object_id,
			'metadata'  => $event->metadata,
			'points'    => $points,
		];

		if ( ! self::$shutdown_registered ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Flush the queue by enqueuing a single Action Scheduler async job.
	 *
	 * Runs on the WordPress `shutdown` hook so all points awarded during the
	 * request are batched into one job.
	 *
	 * @as-fire-once Shutdown-time drain — runs once per request when WordPress
	 *               tears down. The handler (self::HOOK) processes the batch
	 *               and resets self::$queue; it does not re-enter flush_queue.
	 * @return void
	 */
	public static function flush_queue(): void {
		if ( empty( self::$queue ) ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler indexes the args column and rejects any payload
			// whose JSON encoding exceeds 8000 characters. A busy request (such
			// as a demo seed) can accumulate far more events than fit in one job,
			// so split the queue into size-bounded chunks and enqueue one job per
			// chunk. process_batch() already iterates a batch, so several smaller
			// batches are handled identically to one large batch.
			foreach ( self::chunk_queue( self::$queue ) as $chunk ) {
				as_enqueue_async_action( self::HOOK, [ $chunk ], 'wb_gamification' );
			}
		}

		self::$queue = [];
	}

	/**
	 * Split the event queue into chunks whose JSON-encoded Action Scheduler args
	 * stay under Action Scheduler's 8000-character column limit (with headroom).
	 *
	 * Each chunk is measured exactly as flush_queue() enqueues it ( [ $chunk ] ).
	 * A single event whose own JSON already exceeds the budget is placed in its
	 * own chunk so it never drags other events down with it.
	 *
	 * @param array<int, array<string, mixed>> $queue Accumulated events.
	 * @return array<int, array<int, array<string, mixed>>> List of event chunks.
	 */
	private static function chunk_queue( array $queue ): array {
		$max_bytes = 7000; // Headroom under AS's 8000-char limit for its wrapper.
		$chunks    = [];
		$current   = [];

		foreach ( $queue as $item ) {
			$candidate   = $current;
			$candidate[] = $item;

			if ( ! empty( $current ) && strlen( (string) wp_json_encode( [ $candidate ] ) ) > $max_bytes ) {
				$chunks[] = $current;
				$current  = [ $item ];
			} else {
				$current = $candidate;
			}
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Process a batch of award events asynchronously.
	 *
	 * Called by Action Scheduler when it picks up the queued job.
	 * Iterates over every event in the batch and invokes each registered
	 * evaluator callback.
	 *
	 * @param array $batch Array of event data arrays from flush_queue().
	 * @return void
	 */
	public static function process_batch( array $batch ): void {
		foreach ( $batch as $item ) {
			$user_id = (int) ( $item['user_id'] ?? 0 );
			$points  = (int) ( $item['points'] ?? 0 );

			if ( $user_id <= 0 ) {
				continue;
			}

			$event_data = $item;

			foreach ( self::$evaluators as $callback ) {
				if ( is_callable( $callback ) ) {
					call_user_func( $callback, $user_id, $event_data, $points );
				}
			}
		}
	}
}
