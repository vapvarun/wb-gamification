<?php
/**
 * WB Gamification — WooCommerce refund handler.
 *
 * Reverses `wc_order_completed` points for orders paid before 1.6.8 when they are
 * refunded. Newer awards are reversed by the manifest's reverse_on.
 *
 * WC fires:
 *   do_action( 'woocommerce_order_status_refunded', $order_id, $order, $status_transition )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\Integrations\WooCommerce;

use WBGam\Engine\Event;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Takes back the order award for orders paid before 1.6.8.
 *
 * Since 1.6.8 the award records its order and the manifest's reverse_on settles a refund exactly.
 * Older awards have no order id, so this debits the award value once per order, and records the
 * order on the debit so a refund processed again takes nothing more.
 *
 * @package WB_Gamification
 */
final class RefundHandler {

	private const ACTION_ID_AWARD = 'wc_order_completed';
	private const ACTION_ID_DEBIT = 'wc_order_refunded';

	/**
	 * Wire the refund hook when WooCommerce is active.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refund' ), 10, 1 );
	}

	/**
	 * Debit a pre-1.6.8 order award once.
	 *
	 * @param int $order_id Refunded order.
	 */
	public static function on_refund( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return;
		}

		// Only orders paid before per-object tracking started; a later order that paid nothing has
		// nothing to take back.
		$since = (string) get_option( PointsEngine::OBJECT_AWARDS_SINCE_OPTION, '' );
		$paid  = $order->get_date_paid();
		if ( '' === $since || ! $paid || gmdate( 'Y-m-d H:i:s', $paid->getTimestamp() ) >= $since ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		$points      = self::resolve_award_points();
		if ( $customer_id <= 0 || $points <= 0 ) {
			return; // Guest checkouts can't be debited.
		}

		// An award that knows its order is settled by reverse_on; a debit already written for this
		// order means the refund was processed before.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live ledger read inside the refund.
		$settled = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}wb_gam_points WHERE object_id = %d AND action_id IN ( %s, %s ) LIMIT 1",
				$order_id,
				self::ACTION_ID_AWARD,
				self::ACTION_ID_DEBIT
			)
		);
		if ( $settled ) {
			return;
		}

		// Debit the currency the award was paid in.
		$action     = Registry::get_action( self::ACTION_ID_AWARD );
		$point_type = is_array( $action ) ? (string) ( $action['point_type'] ?? '' ) : '';
		$resolved   = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );

		// Only when the member still holds the award value: a legacy order whose award was lost or
		// capped must not push a balance below zero.
		if ( PointsEngine::get_total( $customer_id, $resolved ) < $points ) {
			return;
		}

		PointsEngine::debit(
			$customer_id,
			$points,
			self::ACTION_ID_DEBIT,
			new Event(
				array(
					'action_id'  => self::ACTION_ID_DEBIT,
					'user_id'    => $customer_id,
					'object_id'  => $order_id,
					'point_type' => $resolved,
					'metadata'   => array( 'points_cost' => -$points ),
				)
			),
			$resolved
		);
	}

	/**
	 * Points the order award pays on this site.
	 */
	private static function resolve_award_points(): int {
		$action  = Registry::get_action( self::ACTION_ID_AWARD );
		$default = is_array( $action ) ? (int) ( $action['default_points'] ?? 0 ) : 0;
		return (int) get_option( 'wb_gam_points_' . self::ACTION_ID_AWARD, $default );
	}
}
