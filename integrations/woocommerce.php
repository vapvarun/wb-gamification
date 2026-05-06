<?php
/**
 * WB Gamification — WooCommerce Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Fires only when WooCommerce is active.
 *
 * Actions covered:
 *   Order completed         — woocommerce_order_status_completed
 *   First purchase ever     — woocommerce_order_status_completed (once only)
 *   Product reviewed        — comment_post on product post type
 *   Wishlist item added     — yith_wcwl_added_to_wishlist (YITH Wishlist)
 *
 * Points scale example:
 *   First purchase  →  50 pts (loyalty hook — reward the relationship, not just the spend)
 *   Each order      →  25 pts
 *   Leave a review  →  15 pts (quality signal — drives social proof)
 *   Add to wishlist →   5 pts (intent signal)
 *
 * @package WB_Gamification
 * @see     https://woocommerce.github.io/code-reference/
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	return [];
}

return [
	'plugin'   => 'WooCommerce',
	'version'  => '1.0.0',
	'triggers' => [

		[
			'id'             => 'wc_order_completed',
			'label'          => 'Complete a purchase',
			'description'    => 'Awarded each time a customer completes an order.',
			'hook'           => 'woocommerce_order_status_completed',
			'user_callback'  => function ( int $order_id ): int {
				$order = wc_get_order( $order_id );
				return $order ? (int) $order->get_customer_id() : 0;
			},
			'default_points' => 25,
			'category'       => 'commerce',
			'icon'           => 'icon-shopping-cart',
			'repeatable'     => true,
			'cooldown'       => 0,
		],

		[
			'id'             => 'wc_first_purchase',
			'label'          => 'Complete first purchase ever',
			'description'    => 'Awarded once on a customer\'s very first completed order.',
			'hook'           => 'woocommerce_order_status_completed',
			'user_callback'  => function ( int $order_id ): int {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return 0;
				}
				$customer_id = (int) $order->get_customer_id();
				if ( ! $customer_id ) {
					return 0;
				}
				// Count completed orders for this customer.
				$completed = wc_get_orders( [
					'customer' => $customer_id,
					'status'   => 'completed',
					'limit'    => 2,
					'return'   => 'ids',
				] );
				return count( $completed ) === 1 ? $customer_id : 0;
			},
			'default_points' => 50,
			'category'       => 'commerce',
			'icon'           => 'icon-star',
			'repeatable'     => false,
		],

		[
			'id'             => 'wc_product_reviewed',
			'label'          => 'Leave a product review',
			'description'    => 'Awarded when an approved product review is posted.',
			'hook'           => 'comment_post',
			'user_callback'  => function ( int $comment_id, int|string $approved ): int {
				if ( 1 !== (int) $approved ) {
					return 0;
				}
				$comment = get_comment( $comment_id );
				if ( ! $comment || empty( $comment->user_id ) ) {
					return 0;
				}
				$post = get_post( $comment->comment_post_ID );
				if ( ! $post || 'product' !== $post->post_type ) {
					return 0;
				}
				return (int) $comment->user_id;
			},
			'default_points' => 15,
			'category'       => 'commerce',
			'icon'           => 'icon-star-half',
			'repeatable'     => true,
			'cooldown'       => 86400, // One review point per product per day.
		],

		[
			'id'             => 'wc_wishlist_add',
			'label'          => 'Add a product to wishlist (YITH)',
			'description'    => 'Awarded when a member adds any product to their YITH wishlist.',
			'hook'           => 'yith_wcwl_added_to_wishlist',
			'user_callback'  => function ( int $product_id, int $wishlist_id, int $user_id ): int {
				return $user_id > 0 ? $user_id : (int) get_current_user_id();
			},
			'default_points' => 5,
			'category'       => 'commerce',
			'icon'           => 'icon-heart',
			'repeatable'     => true,
			'cooldown'       => 300,
		],

		[
			'id'                => 'wc_add_to_cart',
			'label'             => 'Add a product to cart',
			'description'       => 'Awarded when a logged-in member adds a product to their cart. Cooldown limits farming.',
			// WC fires: do_action( 'woocommerce_add_to_cart', $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ).
			'hook'              => 'woocommerce_add_to_cart',
			'user_callback'     => function ( string $cart_item_key, int $product_id ): int {
				$user_id = (int) get_current_user_id();
				return $user_id > 0 ? $user_id : 0;
			},
			'metadata_callback' => function ( string $cart_item_key, int $product_id ): array {
				return array( 'product_id' => $product_id );
			},
			'default_points'    => 1,
			'category'          => 'commerce',
			'icon'              => 'icon-shopping-cart',
			'repeatable'        => true,
			'cooldown'          => 300,
		],

	],
];
