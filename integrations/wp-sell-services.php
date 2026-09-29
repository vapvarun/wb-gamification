<?php
/**
 * WB Gamification - WP Sell Services Integration Manifest
 *
 * Auto-loaded by ManifestLoader when WP Sell Services is active. Pure manifest, same pattern as
 * Listora and Career Board - triggers surface in Settings and the Setup Wizard automatically.
 *
 * Hook signatures verified against wp-sell-services 1.7.2:
 *   do_action( 'wpss_order_completed', int $order_id, ServiceOrder $order )   OrderWorkflowManager
 *   do_action( 'wpss_review_created', int $review_id, int $order_id )         ReviewService
 *
 * Both are marketplace transactions, so both refuse a member dealing with themselves: a seller who
 * orders their own service, or reviews it, earns nothing. Orders a dispute restores never reach
 * `wpss_order_completed` (WPSS returns before firing it), so a reopened order cannot pay twice.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/wp-sell-services/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPSS_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'WP Sell Services',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'wpss_order_completed',
			'label'             => static fn(): string => __( 'Complete a service order', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the seller when an order they delivered is completed. An order a seller places with themselves earns nothing.', 'wb-gamification' ),
			// Fires: do_action( 'wpss_order_completed', int $order_id, ServiceOrder $order ). The seller is $order->vendor_id.
			'hook'              => 'wpss_order_completed',
			'user_callback'     => function ( int $order_id, $order = null ): int {
				if ( ! is_object( $order ) ) {
					return 0;
				}
				$vendor_id = (int) ( $order->vendor_id ?? 0 );
				return $vendor_id > 0 && $vendor_id !== (int) ( $order->customer_id ?? 0 ) ? $vendor_id : 0;
			},
			'metadata_callback' => function ( int $order_id, $order = null ): array {
				return array( 'order_id' => $order_id );
			},
			'default_points'    => 20,
			'category'          => 'commerce',
			'icon'              => 'icon-package-check',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'wpss_review_created',
			'label'             => static fn(): string => __( 'Review a service', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded to the buyer when their review is published. Reviews held for moderation earn nothing, and a seller reviewing their own order earns nothing.', 'wb-gamification' ),
			// Fires: do_action( 'wpss_review_created', int $review_id, int $order_id ). The reviewer is read through WPSS's own service.
			'hook'              => 'wpss_review_created',
			'user_callback'     => function ( int $review_id, int $order_id = 0 ): int {
				if ( ! class_exists( '\WPSellServices\Services\ReviewService' ) ) {
					return 0;
				}
				$review = ( new \WPSellServices\Services\ReviewService() )->get( $review_id );
				// 'approved' is WPSellServices\Models\Review::STATUS_APPROVED, spelled out so this file needs no class from a plugin that may be absent.
				if ( ! $review || 'approved' !== $review->status ) {
					return 0;
				}
				$order = class_exists( '\WPSellServices\Services\OrderService' ) ? ( new \WPSellServices\Services\OrderService() )->get( $order_id ) : null;
				if ( $order && (int) $order->vendor_id === (int) $review->reviewer_id ) {
					return 0;
				}
				return (int) $review->reviewer_id;
			},
			'metadata_callback' => function ( int $review_id, int $order_id = 0 ): array {
				return array(
					'review_id' => $review_id,
					'order_id'  => $order_id,
				);
			},
			'default_points'    => 10,
			'category'          => 'commerce',
			'icon'              => 'icon-star',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 60,
		),
	),
);
