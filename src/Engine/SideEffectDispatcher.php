<?php
/**
 * Side-effect dispatcher with failure capture + reconciliation.
 *
 * Closes Finding B and Finding #2 from
 * plan/STABILITY-AND-ARCHITECTURE-V2.md.
 *
 * Before this class: Engine::process() ran level-up, streak, and webhook
 * side effects inline AFTER the points commit. If any failed (DB drop,
 * fatal in a listener, gateway timeout), the failure was silent — caught
 * by the global logger but with no retry, no audit, no compensation. A
 * user could earn points but never level up.
 *
 * After this class: those side effects register here. dispatch() wraps
 * each in try/catch. Failures land in wb_gam_side_effect_failures with
 * retry counters. The reconciler cron `wb_gam_reconcile_side_effects`
 * replays pending failures up to MAX_RETRIES times before marking the
 * row 'exhausted' for human triage.
 *
 * Design contract:
 *   - Each registered side-effect MUST be idempotent. The reconciler
 *     re-fires from the original Event payload; if the handler already
 *     applied its effect, the second run must be a no-op.
 *   - Handlers receive a (Event, points) tuple. They do NOT receive
 *     mutable references. The dispatcher is the only thing that knows
 *     about retry state.
 *   - Handlers MUST NOT call PointsEngine::award (would recurse through
 *     Engine::process and trigger another dispatch cycle). Read-only or
 *     downstream-write only — same constraint as before, now formal.
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\Engine;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class SideEffectDispatcher {

	public const TABLE_SUFFIX   = 'wb_gam_side_effect_failures';
	public const RECONCILE_CRON = 'wb_gam_reconcile_side_effects';
	public const MAX_RETRIES    = 3;

	/**
	 * Registered side-effect handlers, keyed by slug.
	 *
	 * @var array<string, callable(Event, int): void>
	 */
	private static array $handlers = array();

	/**
	 * Boot hook — registers the reconciler cron handler. Called once
	 * from wb-gamification.php's engine bootstrap.
	 */
	public static function boot(): void {
		add_action( self::RECONCILE_CRON, array( __CLASS__, 'reconcile' ) );

		// Arm the recurring event on init, never at plugins_loaded: wp_schedule_event
		// resolves schedules via wp_get_schedules(), which fires the
		// cron_schedules filter — that must not run before init on WP 6.7+.
		if ( did_action( 'init' ) ) {
			self::maybe_schedule();
		} else {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		}
	}

	/**
	 * Arm the reconciler cron if not already scheduled. Idempotent — safe to
	 * call on every init.
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::RECONCILE_CRON ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::RECONCILE_CRON );
		}
	}

	/**
	 * Engines call this to register a named side effect.
	 *
	 * @param string                     $slug    Stable identifier (e.g. 'level_up').
	 *                                            Used as the dedupe key in the failures
	 *                                            table; rename means losing pending retries.
	 * @param callable(Event, int): void $handler Receives the Event and the points awarded.
	 *                                            Must be idempotent.
	 */
	public static function register( string $slug, callable $handler ): void {
		self::$handlers[ $slug ] = $handler;
	}

	/**
	 * Fan out to every registered handler. Called from Engine::process()
	 * AFTER the points commit. Each handler is isolated in try/catch so
	 * one failing side effect doesn't stop the others.
	 *
	 * @as-fire-once Called once per processed Event. Each handler is a
	 *               leaf-effect; handlers must not recurse into the
	 *               engine. The handler list is bounded by registration,
	 *               not by event flow.
	 */
	public static function dispatch( Event $event, int $points ): void {
		foreach ( self::$handlers as $slug => $handler ) {
			try {
				$handler( $event, $points );
			} catch ( Throwable $e ) {
				self::record_failure( $slug, $event, $points, $e );
			}
		}
	}

	/**
	 * Persist a failed dispatch for later replay.
	 */
	private static function record_failure( string $slug, Event $event, int $points, Throwable $e ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_SUFFIX,
			array(
				'event_id'        => $event->event_id,
				'user_id'         => $event->user_id,
				'side_effect'     => $slug,
				'points'          => $points,
				'event_payload'   => (string) wp_json_encode( self::serialize_event( $event ) ),
				'error_message'   => substr( $e->getMessage(), 0, 500 ),
				'retry_count'     => 0,
				'status'          => 'pending',
				'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		Log::warning(
			'SideEffectDispatcher: handler failed; queued for reconciliation.',
			array(
				'side_effect' => $slug,
				'event_id'    => $event->event_id,
				'user_id'     => $event->user_id,
				'error'       => $e->getMessage(),
			)
		);
	}

	/**
	 * Cron handler — replays pending failures up to MAX_RETRIES times.
	 *
	 * Pulls a bounded batch (50 per run) of pending rows whose
	 * last_attempt_at is older than 5 minutes (back-off window), bumps
	 * retry_count, re-fires the handler. On success the row is deleted;
	 * on failure retry_count++; when retry_count reaches MAX_RETRIES the
	 * status flips to 'exhausted' (human triage required).
	 */
	public static function reconcile(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// @clock-ok: last_attempt_at is written with gmdate() everywhere in this file (UTC) and the
		// retry bound below is gmdate() too. Same clock on both sides of the comparison.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_id, user_id, side_effect, points, event_payload, retry_count
				   FROM {$table}
				  WHERE status = %s
				    AND retry_count < %d
				    AND last_attempt_at < %s
				  ORDER BY id ASC
				  LIMIT 50",
				'pending',
				self::MAX_RETRIES,
				gmdate( 'Y-m-d H:i:s', time() - 300 )
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$slug    = (string) $row['side_effect'];
			$handler = self::$handlers[ $slug ] ?? null;

			if ( null === $handler ) {
				// Slug refers to a handler that's no longer registered
				// (engine removed, slug renamed). Mark exhausted so it
				// doesn't loop forever, log for triage.
				self::mark_exhausted( (int) $row['id'], 'handler_unregistered' );
				continue;
			}

			$event = self::deserialize_event( (string) $row['event_payload'] );
			if ( null === $event ) {
				self::mark_exhausted( (int) $row['id'], 'event_payload_unparseable' );
				continue;
			}

			try {
				$handler( $event, (int) $row['points'] );
				// Success — delete the row.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			} catch ( Throwable $e ) {
				$new_count = (int) $row['retry_count'] + 1;
				$status    = $new_count >= self::MAX_RETRIES ? 'exhausted' : 'pending';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'retry_count'     => $new_count,
						'status'          => $status,
						'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
						'error_message'   => substr( $e->getMessage(), 0, 500 ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s', '%s', '%s' ),
					array( '%d' )
				);

				if ( 'exhausted' === $status ) {
					Log::error(
						'SideEffectDispatcher: handler exhausted retries; needs human triage.',
						array(
							'failure_id'  => (int) $row['id'],
							'side_effect' => $slug,
							'event_id'    => (string) $row['event_id'],
							'user_id'     => (int) $row['user_id'],
							'error'       => $e->getMessage(),
						)
					);
				}
			}
		}
	}

	/**
	 * Mark a row exhausted with a specific reason (used when re-firing
	 * is impossible — handler gone, payload corrupt).
	 */
	private static function mark_exhausted( int $id, string $reason ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'          => 'exhausted',
				'error_message'   => substr( $reason, 0, 500 ),
				'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Reduce an Event to a JSON-safe array for storage in the failures table.
	 *
	 * @return array<string, mixed>
	 */
	private static function serialize_event( Event $event ): array {
		return array(
			'action_id'  => $event->action_id,
			'user_id'    => $event->user_id,
			'object_id'  => $event->object_id,
			'metadata'   => $event->metadata,
			'created_at' => $event->created_at,
			'event_id'   => $event->event_id,
		);
	}

	/**
	 * Reconstruct an Event from the stored payload.
	 */
	private static function deserialize_event( string $json ): ?Event {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		try {
			return new Event( $data );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Manually re-fire one failed side-effect now (admin-triggered).
	 *
	 * Unlike {@see self::reconcile()}, this ignores the retry_count ceiling and
	 * the back-off window — it exists to let an owner clear an 'exhausted' row
	 * the cron will never touch again, once they have fixed the underlying
	 * cause. Fires the handler immediately; deletes the row on success, updates
	 * the error and marks it 'exhausted' on failure.
	 *
	 * @param int $id Failure row id.
	 * @return array{success:bool,reason?:string,id:int}
	 */
	public static function retry( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, side_effect, points, event_payload FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return array(
				'success' => false,
				'reason'  => 'not_found',
				'id'      => $id,
			);
		}

		$handler = self::$handlers[ (string) $row['side_effect'] ] ?? null;
		if ( null === $handler ) {
			self::mark_exhausted( $id, 'handler_unregistered' );
			return array(
				'success' => false,
				'reason'  => 'handler_unregistered',
				'id'      => $id,
			);
		}

		$event = self::deserialize_event( (string) $row['event_payload'] );
		if ( null === $event ) {
			self::mark_exhausted( $id, 'event_payload_unparseable' );
			return array(
				'success' => false,
				'reason'  => 'event_payload_unparseable',
				'id'      => $id,
			);
		}

		try {
			$handler( $event, (int) $row['points'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			return array(
				'success' => true,
				'id'      => $id,
			);
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'          => 'exhausted',
					'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
					'error_message'   => substr( $e->getMessage(), 0, 500 ),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return array(
				'success' => false,
				'reason'  => 'retry_failed',
				'id'      => $id,
			);
		}
	}

	/**
	 * Count failure rows grouped by status (pending / exhausted).
	 *
	 * @return array<string,int> status => count.
	 */
	public static function get_failure_counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) ?: array();
		$counts = array();
		foreach ( $rows as $r ) {
			$counts[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $counts;
	}

	/**
	 * Most recent failure rows for the admin panel.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent_failures( int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_id, user_id, side_effect, error_message, retry_count, status, last_attempt_at
				   FROM {$table}
				  ORDER BY ( status = 'exhausted' ) DESC, last_attempt_at DESC
				  LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Test-helper: reset registered handlers between tests.
	 *
	 * @internal
	 */
	public static function reset_handlers(): void {
		self::$handlers = array();
	}

	/**
	 * Test-helper: read the current handler registry.
	 *
	 * @internal
	 * @return array<string, callable>
	 */
	public static function get_handlers(): array {
		return self::$handlers;
	}
}
