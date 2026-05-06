<?php
/**
 * PointTypeConversionService
 *
 * Business logic for currency conversion. Atomic debit-from + credit-to with
 * a single shared event_id linking the two ledger rows.
 *
 * Per the canonical Wbcom 7-layer architecture (`plan/ARCHITECTURE.md`),
 * Service classes own business logic and depend on Repository for SQL.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Services;

use WBGam\Engine\Event;
use WBGam\Engine\PointsEngine;
use WBGam\Repository\PointTypeConversionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic, validated, rate-limited currency conversion.
 */
final class PointTypeConversionService {

	private PointTypeConversionRepository $repo;
	private PointTypeService $types;

	/**
	 * @param PointTypeConversionRepository|null $repo  Optional DI for tests.
	 * @param PointTypeService|null              $types Optional DI for tests.
	 */
	public function __construct( ?PointTypeConversionRepository $repo = null, ?PointTypeService $types = null ) {
		$this->repo  = $repo ?? new PointTypeConversionRepository();
		$this->types = $types ?? new PointTypeService();
	}

	/**
	 * List every active conversion rule, augmented with from/to label + icon
	 * for display.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function list_active(): array {
		$rules = $this->repo->all_active();
		foreach ( $rules as &$rule ) {
			$rule['from'] = $this->types->get( (string) $rule['from_type'] );
			$rule['to']   = $this->types->get( (string) $rule['to_type'] );
		}
		return $rules;
	}

	/**
	 * Find the rule that converts `$from` → `$to`, validated.
	 *
	 * @param string $from From-type slug.
	 * @param string $to   To-type slug.
	 * @return array<string,mixed>|null
	 */
	public function find_rule( string $from, string $to ): ?array {
		return $this->repo->find( $from, $to );
	}

	/**
	 * Create a new conversion rule.
	 *
	 * @param array<string,mixed> $input Untrusted input.
	 * @return array{ok:bool,error?:string,id?:int}
	 */
	public function create_rule( array $input ): array {
		$from = $this->types->resolve( (string) ( $input['from_type'] ?? '' ) );
		$to   = $this->types->resolve( (string) ( $input['to_type'] ?? '' ) );

		if ( $from === $to ) {
			return array(
				'ok'    => false,
				'error' => 'same_type',
			);
		}
		if ( ! $this->types->get( $from ) || ! $this->types->get( $to ) ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_type',
			);
		}
		if ( $this->repo->find( $from, $to ) ) {
			return array(
				'ok'    => false,
				'error' => 'pair_exists',
			);
		}

		$from_amount = (int) ( $input['from_amount'] ?? 0 );
		$to_amount   = (int) ( $input['to_amount'] ?? 0 );
		if ( $from_amount < 1 || $to_amount < 1 ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_rate',
			);
		}

		$id = $this->repo->insert(
			array(
				'from_type'        => $from,
				'to_type'          => $to,
				'from_amount'      => $from_amount,
				'to_amount'        => $to_amount,
				'min_convert'      => isset( $input['min_convert'] ) ? (int) $input['min_convert'] : 1,
				'cooldown_seconds' => isset( $input['cooldown_seconds'] ) ? (int) $input['cooldown_seconds'] : 0,
				'max_per_day'      => isset( $input['max_per_day'] ) ? (int) $input['max_per_day'] : 0,
			)
		);

		return $id > 0
			? array(
				'ok' => true,
				'id' => $id,
			)
			: array(
				'ok'    => false,
				'error' => 'insert_failed',
			);
	}

	/**
	 * Update an existing rule.
	 *
	 * @param int                 $id    Rule ID.
	 * @param array<string,mixed> $input Fields to update.
	 * @return array{ok:bool,error?:string}
	 */
	public function update_rule( int $id, array $input ): array {
		if ( ! $this->repo->find_by_id( $id ) ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		return $this->repo->update( $id, $input )
			? array( 'ok' => true )
			: array(
				'ok'    => false,
				'error' => 'update_failed',
			);
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule ID.
	 * @return array{ok:bool,error?:string}
	 */
	public function delete_rule( int $id ): array {
		if ( ! $this->repo->find_by_id( $id ) ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		return $this->repo->delete( $id )
			? array( 'ok' => true )
			: array(
				'ok'    => false,
				'error' => 'delete_failed',
			);
	}

	/**
	 * Convert a member's balance from one currency to another atomically.
	 *
	 * Flow:
	 *   1. Resolve + validate from/to slugs (must be different, both registered).
	 *   2. Look up the active rule. No rule → 'no_rule'.
	 *   3. Check `min_convert` — input below minimum is rejected (UX hint).
	 *   4. Cooldown check (default 0 = none).
	 *   5. Daily-cap check (default 0 = unlimited).
	 *   6. Compute units = floor( amount / from_amount ); reject if 0.
	 *   7. Compute debit = units × from_amount, credit = units × to_amount.
	 *   8. START TRANSACTION + lock from-type balance with FOR UPDATE.
	 *   9. Reject if balance < debit.
	 *  10. Debit + Credit (PointsEngine::debit + PointsEngine::award), both
	 *      tagged with synthetic action_id `convert_<from>_to_<to>` so
	 *      analytics + history surfaces can group them.
	 *  11. COMMIT.
	 *  12. Fire `wb_gam_point_type_converted` action.
	 *
	 * @param int    $user_id User performing the conversion.
	 * @param string $from    Source currency slug.
	 * @param string $to      Destination currency slug.
	 * @param int    $amount  Source-currency amount the user wants to spend.
	 * @return array{ok:bool,error?:string,debit?:int,credit?:int,units?:int}
	 */
	public function convert( int $user_id, string $from, string $to, int $amount ): array {
		if ( $user_id <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_user',
			);
		}

		$from = $this->types->resolve( $from );
		$to   = $this->types->resolve( $to );
		if ( $from === $to ) {
			return array(
				'ok'    => false,
				'error' => 'same_type',
			);
		}

		$rule = $this->repo->find( $from, $to );
		if ( ! $rule ) {
			return array(
				'ok'    => false,
				'error' => 'no_rule',
			);
		}

		$min = (int) ( $rule['min_convert'] ?? 1 );
		if ( $amount < $min ) {
			return array(
				'ok'    => false,
				'error' => 'below_min',
			);
		}

		// Cooldown — default 0 means no cooldown applied.
		$cooldown = (int) ( $rule['cooldown_seconds'] ?? 0 );
		if ( $cooldown > 0 ) {
			$last = $this->repo->last_conversion_at( $user_id, $from, $to );
			if ( $last && ( current_time( 'timestamp' ) - strtotime( $last ) ) < $cooldown ) {
				return array(
					'ok'    => false,
					'error' => 'cooldown',
				);
			}
		}

		// Daily cap — default 0 means unlimited.
		$max_per_day = (int) ( $rule['max_per_day'] ?? 0 );
		if ( $max_per_day > 0 && $this->repo->count_today( $user_id, $from, $to ) >= $max_per_day ) {
			return array(
				'ok'    => false,
				'error' => 'daily_cap',
			);
		}

		// Compute units — floor at $rule['from_amount']. Caller can spend
		// 250 points at a 100→1 rate and receive 2 coins (50 points refunded
		// to the from-balance via the floor).
		$from_amount = max( 1, (int) $rule['from_amount'] );
		$to_amount   = max( 1, (int) $rule['to_amount'] );
		$units       = intdiv( $amount, $from_amount );
		if ( $units < 1 ) {
			return array(
				'ok'    => false,
				'error' => 'below_unit',
			);
		}

		$debit_amount  = $units * $from_amount;
		$credit_amount = $units * $to_amount;

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		// Lock from-balance row.
		$balance = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND point_type = %s FOR UPDATE",
				$user_id,
				$from
			)
		);

		if ( $balance < $debit_amount ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'      => false,
				'error'   => 'insufficient',
				'balance' => $balance,
				'needed'  => $debit_amount,
			);
		}

		$action_debit  = sprintf( 'convert_%s_to_%s', $from, $to );
		$action_credit = sprintf( 'convert_%s_to_%s', $from, $to );

		// Shared event_id ties the two ledger rows together for audit + replay.
		$debit_event = new Event(
			array(
				'action_id' => $action_debit,
				'user_id'   => $user_id,
				'metadata'  => array(
					'point_type'    => $from,
					'convert_to'    => $to,
					'units'         => $units,
					'rule_id'       => (int) $rule['id'],
					'credit_amount' => $credit_amount,
				),
			)
		);
		$shared_event_id = $debit_event->event_id;

		$ok_debit = PointsEngine::debit( $user_id, $debit_amount, $action_debit, $shared_event_id, $from );
		if ( ! $ok_debit ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'    => false,
				'error' => 'debit_failed',
			);
		}

		// Mirror event row for audit grouping (debit row already inserted via
		// PointsEngine::debit; we record the convert event separately so the
		// repo's count_today() / last_conversion_at() helpers find it).
		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_events',
			array(
				'id'         => $shared_event_id,
				'user_id'    => $user_id,
				'action_id'  => $action_debit,
				'metadata'   => wp_json_encode( $debit_event->metadata ),
				'point_type' => $from,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// Credit the destination type. Use PointsEngine::award which routes
		// through the standard pipeline (rate-limit checks etc. — we set the
		// action's $action config to skip caps via the override-aware
		// resolver below would be ideal, but for simplicity we award via a
		// direct ledger insert.
		$credit_inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_points',
			array(
				'event_id'   => $shared_event_id,
				'user_id'    => $user_id,
				'action_id'  => $action_credit,
				'points'     => $credit_amount,
				'point_type' => $to,
				'object_id'  => null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( ! $credit_inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'    => false,
				'error' => 'credit_failed',
			);
		}

		wp_cache_delete( "wb_gam_total_{$user_id}_{$from}", 'wb_gamification' );
		wp_cache_delete( "wb_gam_total_{$user_id}_{$to}", 'wb_gamification' );

		$wpdb->query( 'COMMIT' );

		/**
		 * Fires after a successful currency conversion.
		 *
		 * @param int    $user_id   User who converted.
		 * @param string $from      Source currency slug.
		 * @param string $to        Destination currency slug.
		 * @param int    $debit     Source amount debited.
		 * @param int    $credit    Destination amount credited.
		 * @param array  $rule      The matched conversion rule row.
		 */
		do_action( 'wb_gam_point_type_converted', $user_id, $from, $to, $debit_amount, $credit_amount, $rule );

		return array(
			'ok'     => true,
			'debit'  => $debit_amount,
			'credit' => $credit_amount,
			'units'  => $units,
		);
	}
}
