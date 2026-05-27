<?php
/**
 * Transaction — re-entrant atomic-write wrapper for the points engine.
 *
 * Every multi-table mutation in the plugin (award/debit/redeem/etc.) must
 * be atomic: the ledger row, the events row, the materialised totals row,
 * and any feature-specific row (redemption record, conversion record) all
 * commit together or roll back together. MySQL doesn't support nested
 * transactions, so this class implements a depth-counter pattern: the
 * outermost call to {@see run()} opens the transaction; inner calls
 * participate without opening a new one. The transaction commits only
 * when the outermost closure returns a truthy value AND its own depth
 * unwinds to zero.
 *
 * Usage — single-table:
 *
 *     Transaction::run( function () {
 *         global $wpdb;
 *         return $wpdb->insert( ... );    // truthy → commit, false → rollback
 *     } );
 *
 * Usage — multi-table composition (the redeem flow):
 *
 *     return Transaction::run( function () use ( $user_id, $cost, $item_id, $event ) {
 *         $debit = PointsEngine::debit( $user_id, $cost, 'redemption', $event );
 *         if ( ! $debit['success'] ) {
 *             return false; // PointsEngine::debit also called Transaction::run
 *                          // but at inner depth — its return value cascades up.
 *         }
 *         if ( ! self::decrement_stock( $item_id ) ) {
 *             return false;
 *         }
 *         return self::insert_redemption_record( $user_id, $item_id, $cost );
 *     } );
 *
 * Why explicit `return false` for rollback:
 *
 *   PHP exceptions also trigger rollback (and re-throw) — use those when
 *   the failure is genuinely exceptional. `return false` is the soft path
 *   for "validation failed, no points have been moved, this is a normal
 *   business-rule outcome." Both paths correctly unwind the depth counter.
 *
 * Why re-entrant rather than per-call:
 *
 *   PointsEngine::debit needs its own transaction when called standalone
 *   (e.g. from Jetonomy integration), but must NOT issue START/COMMIT
 *   when nested inside RedemptionEngine::redeem (which already opened a
 *   transaction for the broader flow). The depth counter ensures the
 *   outer scope owns the transaction lifecycle; inner scopes participate.
 *
 * @package WB_Gamification
 * @since   1.4.1
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Re-entrant transaction wrapper.
 *
 * @package WB_Gamification
 * @since   1.4.1
 */
final class Transaction {

	/**
	 * Nesting depth. 0 = outside any transaction; 1+ = inside one.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Result-tracker: if any nested closure returned false, the outermost
	 * frame rolls back even when its own closure returned truthy. This
	 * prevents "outer scope happily commits the partial state that inner
	 * scope already declared a failure on" — a real footgun without it.
	 *
	 * @var bool
	 */
	private static bool $aborted = false;

	/**
	 * Run a closure inside a MySQL transaction.
	 *
	 * Returns whatever the closure returns. The closure can:
	 *   - Return any truthy value → commit + return value
	 *   - Return `false` or `null` → rollback + return false/null
	 *   - Throw → rollback + re-throw
	 *
	 * Nesting is safe: only the outermost call opens/closes the transaction.
	 *
	 * @param callable $fn Closure executing the atomic work.
	 * @return mixed       Whatever $fn returned (or null on cascading rollback).
	 */
	public static function run( callable $fn ): mixed {
		global $wpdb;

		$is_outermost = ( 0 === self::$depth );

		if ( $is_outermost ) {
			$wpdb->query( 'START TRANSACTION' );
			self::$aborted = false;
		}

		++self::$depth;

		try {
			$result = $fn();
		} catch ( \Throwable $e ) {
			--self::$depth;
			self::$aborted = true;
			if ( $is_outermost ) {
				$wpdb->query( 'ROLLBACK' );
				self::$aborted = false;
			}
			throw $e;
		}

		--self::$depth;

		// A falsy return from any frame marks the whole transaction aborted.
		// Even if an outer frame's closure later returns truthy, the rollback
		// signal propagates — partial state from a "failed" inner frame must
		// never commit.
		if ( false === $result || null === $result ) {
			self::$aborted = true;
		}

		if ( $is_outermost ) {
			if ( self::$aborted ) {
				$wpdb->query( 'ROLLBACK' );
				self::$aborted = false;
				// Outer caller sees the inner-frame failure as its own outcome.
				if ( false !== $result && null !== $result ) {
					return false;
				}
			} else {
				$wpdb->query( 'COMMIT' );
			}
		}

		return $result;
	}

	/**
	 * Whether the current execution is inside a Transaction::run frame.
	 *
	 * Engine code that wants to take advantage of an outer transaction
	 * without opening a new one can branch on this. Most callers should
	 * just call run() unconditionally — that's the whole point of the
	 * re-entrant design.
	 *
	 * @return bool
	 */
	public static function in_transaction(): bool {
		return self::$depth > 0;
	}

	/**
	 * Test-only: reset the depth + aborted state.
	 *
	 * Mockery/PHPUnit tests that exercise the failure path can leave the
	 * depth counter non-zero. Calling this in tearDown() prevents test
	 * pollution. Not for production use.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$depth   = 0;
		self::$aborted = false;
	}
}
