<?php
/**
 * WB Gamification — WooCommerce Integration Manifest
 *
 * Auto-loaded by ManifestLoader. Fires only when WooCommerce is active.
 *
 * Actions covered:
 *   Order paid             — woocommerce_payment_complete (fires once
 *                            per order when payment succeeds — handles
 *                            both stores that move paid orders to
 *                            "processing" awaiting shipment AND stores
 *                            that auto-complete digital orders).
 *   First purchase ever    — woocommerce_payment_complete (once only)
 *   Product reviewed       — comment_post on product post type
 *   Wishlist item added    — yith_wcwl_added_to_wishlist (YITH Wishlist)
 *   Add to cart            — woocommerce_add_to_cart
 *
 * Points scale example:
 *   First purchase  →  50 pts (loyalty hook — reward the relationship, not just the spend)
 *   Each order      →  25 pts
 *   Leave a review  →  15 pts (quality signal — drives social proof)
 *   Add to wishlist →   5 pts (intent signal)
 *
 * NOTE: pre-1.4.1 wc_order_completed + wc_first_purchase listened on
 * `woocommerce_order_status_completed`, which only fires when an admin
 * explicitly marks an order as completed. For stores where paid orders
 * sit in "processing" until shipment (the WC default for physical
 * goods), members never earned points for their purchases — they had
 * to wait for the admin to mark complete, sometimes days later. The
 * symptom on Basecamp #9925589914 was "WC events other than
 * add_to_cart not firing." Moving to `woocommerce_payment_complete`
 * fires once when payment succeeds regardless of subsequent
 * status — payment_complete is WC's canonical "money in hand" hook.
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
			'label'          => 'Pay for an order',
			'description'    => 'Awarded each time a customer pays for an order (fires once per order on payment success).',
			'hook'           => 'woocommerce_payment_complete',
			'user_callback'  => function ( int $order_id ): int {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return 0;
				}
				// Fall through customer_id → billing_email → fail. Guests
				// who later create an account using the same email pick up
				// retroactive credit; admins testing through the cart with
				// their own login still resolve via customer_id.
				$customer_id = (int) $order->get_customer_id();
				if ( $customer_id > 0 ) {
					return $customer_id;
				}
				$billing_email = (string) $order->get_billing_email();
				if ( '' !== $billing_email ) {
					$user = get_user_by( 'email', $billing_email );
					return $user ? (int) $user->ID : 0;
				}
				return 0;
			},
			'default_points' => 25,
			'category'       => 'commerce',
			'icon'           => 'icon-shopping-cart',
			'repeatable'     => true,
			'cooldown'       => 0,
			// One award per order completion — already deduplicated by the
			// hook itself (status_completed only fires when an order moves
			// to completed). Running sync removes the Action Scheduler
			// dependency that turned a "complete checkout → see points"
			// expectation into a "wait for the next AS tick" surprise.
			'async'          => false,
		],

		[
			'id'             => 'wc_first_purchase',
			'label'          => 'Make first purchase ever',
			'description'    => 'Awarded once on a customer\'s very first paid order.',
			'hook'           => 'woocommerce_payment_complete',
			'user_callback'  => function ( int $order_id ): int {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return 0;
				}
				$customer_id = (int) $order->get_customer_id();
				if ( ! $customer_id ) {
					$billing_email = (string) $order->get_billing_email();
					if ( '' !== $billing_email ) {
						$user        = get_user_by( 'email', $billing_email );
						$customer_id = $user ? (int) $user->ID : 0;
					}
				}
				if ( ! $customer_id ) {
					return 0;
				}
				// Count all PAID orders for this customer (processing OR
				// completed). woocommerce_payment_complete fires after the
				// current order has already moved to its paid state, so
				// the count includes it. count === 1 → this IS the first
				// paid order; > 1 means the customer has paid before.
				$paid = wc_get_orders(
					[
						'customer' => $customer_id,
						'status'   => array( 'processing', 'completed' ),
						'limit'    => 2,
						'return'   => 'ids',
					]
				);
				return count( $paid ) === 1 ? $customer_id : 0;
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
				$post = get_post( (int) $comment->comment_post_ID );
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
			// Review submission is user-initiated and low-frequency. Run
			// sync so the reviewer's points appear immediately after the
			// comment posts.
			'async'          => false,
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
