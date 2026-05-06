<?php
/**
 * WB Gamification — WooCommerce refund handler.
 *
 * Reverses points awarded for `wc_order_completed` when an order is refunded.
 * Without this, customers can buy + refund repeatedly to farm points; this
 * keeps the ledger honest.
 *
 * WC fires:
 *   do_action( 'woocommerce_order_status_refunded', $order_id, $order, $status_transition )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\Integrations\WooCommerce;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Debits the customer's points balance when their order is refunded.
 *
 * @package WB_Gamification
 */
final class RefundHandler {

	private const ACTION_ID_AWARD = 'wc_order_completed';
	private const ACTION_ID_DEBIT = 'wc_order_refunded';

	/**
	 * Wire the WC refund hook.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refund' ), 10, 1 );
	}

	/**
	 * Reverse the purchase points for a refunded order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function on_refund( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id <= 0 ) {
			return; // Guest checkouts can't be debited.
		}

		$points = self::resolve_award_points();
		if ( $points <= 0 ) {
			return;
		}

		// Resolve the currency the original award flowed into — defaults to the
		// primary type when the action manifest doesn't declare a point_type.
		// Refund must debit the same currency, not blanket-debit primary.
		$action     = Registry::get_action( self::ACTION_ID_AWARD );
		$point_type = is_array( $action ) ? (string) ( $action['point_type'] ?? '' ) : '';
		$resolved   = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );

		// Debit only if the customer has at least the award value left in that
		// currency — avoids tipping a freshly-zero balance into the negatives
		// for legacy orders whose original award was lost or capped.
		if ( PointsEngine::get_total( $customer_id, $resolved ) < $points ) {
			return;
		}

		PointsEngine::debit( $customer_id, $points, self::ACTION_ID_DEBIT, '', $resolved );
	}

	/**
	 * Resolve the current `wc_order_completed` points value (admin override or manifest default).
	 */
	private static function resolve_award_points(): int {
		$default = 0;
		$action  = Registry::get_action( self::ACTION_ID_AWARD );
		if ( is_array( $action ) ) {
			$default = (int) ( $action['default_points'] ?? 0 );
		}
		return (int) get_option( 'wb_gam_points_' . self::ACTION_ID_AWARD, $default );
	}
}
