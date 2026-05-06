<?php
/**
 * Points Redemption Engine
 *
 * Allows members to spend earned points on defined reward items.
 * Smile.io / loyalty program model — integrates with WooCommerce coupons
 * when WooCommerce is active, falls back to custom reward items otherwise.
 *
 * Reward types:
 *   discount_pct   — % off WooCommerce order (requires WooCommerce)
 *   discount_fixed — Fixed amount off WooCommerce order (requires WooCommerce)
 *   free_shipping  — Free-shipping WooCommerce coupon (requires WooCommerce)
 *   free_product   — 100%-off coupon scoped to a specific product (requires WooCommerce)
 *   wbcom_credits  — Top up balance in a Wbcom Credits SDK slug
 *                    (requires the wbcom-credits-sdk to be loaded by another plugin)
 *   custom         — Admin-defined reward, fulfillment handled via hook
 *
 * Reward config (JSON in reward_config column) per type:
 *   discount_pct/discount_fixed: { "amount": 10 }
 *   free_product:                { "product_id": 42 }
 *   wbcom_credits:               { "slug": "mp", "amount": 100 }
 *   free_shipping / custom:      {}
 *
 * Flow:
 *   1. Admin defines reward items in wp_gam_redemption_items via admin UI
 *      or POST /redemptions/items (admin only).
 *   2. Member redeems: POST /redemptions with { item_id }.
 *   3. Engine validates member has sufficient points, deducts them,
 *      creates a redemption record, dispatches per-type fulfillment.
 *   4. WooCommerce types create a coupon; wbcom_credits tops up an SDK ledger;
 *      custom defers to the wb_gamification_points_redeemed action.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Allows members to spend earned points on defined reward items.
 *
 * @package WB_Gamification
 */
final class RedemptionEngine {

	private const CACHE_GROUP = 'wb_gamification';

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Get all active redemption items.
	 *
	 * @return array<int, array>
	 */
	public static function get_items(): array {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT id, title, description, points_cost, point_type, reward_type, reward_config, stock, is_active
			   FROM {$wpdb->prefix}wb_gam_redemption_items
			  WHERE is_active = 1
			  ORDER BY points_cost ASC",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get a single redemption item by ID.
	 *
	 * @param int $item_id Redemption item ID.
	 * @return array|null Item data array or null if not found.
	 */
	public static function get_item( int $item_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, description, points_cost, point_type, reward_type, reward_config, stock, is_active
				   FROM {$wpdb->prefix}wb_gam_redemption_items WHERE id = %d",
				$item_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Redeem an item for a user.
	 *
	 * @param int $user_id  User redeeming.
	 * @param int $item_id  Redemption item ID.
	 * @return array{ success: bool, redemption_id: int|null, coupon_code: string|null, error: string|null }
	 */
	public static function redeem( int $user_id, int $item_id ): array {
		global $wpdb;

		$item = self::get_item( $item_id );

		if ( ! $item || ! $item['is_active'] ) {
			return array(
				'success'       => false,
				'error'         => __( 'Reward item not found or inactive.', 'wb-gamification' ),
				'redemption_id' => null,
				'coupon_code'   => null,
			);
		}

		$cost = (int) $item['points_cost'];
		$type = ( new \WBGam\Services\PointTypeService() )->resolve( (string) ( $item['point_type'] ?? '' ) );

		// Check stock (quick pre-check before transaction).
		if ( null !== $item['stock'] && (int) $item['stock'] <= 0 ) {
			return array(
				'success'       => false,
				'error'         => __( 'This reward is out of stock.', 'wb-gamification' ),
				'redemption_id' => null,
				'coupon_code'   => null,
			);
		}

		// ── Atomic balance check + debit (prevents TOCTOU race condition) ────
		$wpdb->query( 'START TRANSACTION' );

		// Lock the user's point rows for this currency to prevent concurrent redemptions.
		$balance = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d AND point_type = %s FOR UPDATE",
				$user_id,
				$type
			)
		);

		if ( $balance < $cost ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'success'       => false,
				'error'         => sprintf(
					/* translators: 1: cost, 2: current balance */
					__( 'Insufficient points. This reward costs %1$d pts; you have %2$d.', 'wb-gamification' ),
					$cost,
					$balance
				),
				'redemption_id' => null,
				'coupon_code'   => null,
			);
		}

		// Debit points FIRST (inside the transaction).
		$event = new Event(
			array(
				'action_id' => 'points_redeemed',
				'user_id'   => $user_id,
				'metadata'  => array(
					'item_id'     => $item_id,
					'points_cost' => -$cost,
					'point_type'  => $type,
				),
			)
		);
		PointsEngine::debit( $user_id, $cost, 'redemption', $event->event_id, $type );

		// Atomic stock decrement (inside the transaction).
		if ( null !== $item['stock'] ) {
			$decremented = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}wb_gam_redemption_items SET stock = stock - 1 WHERE id = %d AND stock > 0",
					$item_id
				)
			);
			if ( ! $decremented ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'success'       => false,
					'error'         => __( 'This reward is out of stock.', 'wb-gamification' ),
					'redemption_id' => null,
					'coupon_code'   => null,
				);
			}
		}

		$wpdb->query( 'COMMIT' );

		// Create redemption record.
		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_redemptions',
			array(
				'user_id'     => $user_id,
				'item_id'     => $item_id,
				'points_cost' => $cost,
				'status'      => 'pending',
			),
			array( '%d', '%d', '%d', '%s' )
		);
		$redemption_id = (int) $wpdb->insert_id;

		// Fulfillment.
		$coupon_code = null;
		$config      = json_decode( $item['reward_config'] ?? '{}', true ) ?: array();

		$woo_types = array( 'discount_pct', 'discount_fixed', 'free_shipping', 'free_product' );

		if ( in_array( $item['reward_type'], $woo_types, true ) ) {
			$coupon_code = self::create_woo_coupon( $user_id, $item, $config, $redemption_id );
			$wpdb->update(
				$wpdb->prefix . 'wb_gam_redemptions',
				array(
					'status'      => $coupon_code ? 'fulfilled' : 'failed',
					'coupon_code' => $coupon_code,
				),
				array( 'id' => $redemption_id )
			);
		} elseif ( 'wbcom_credits' === $item['reward_type'] ) {
			$ok = self::topup_wbcom_credits( $user_id, $item, $config );
			$wpdb->update(
				$wpdb->prefix . 'wb_gam_redemptions',
				array( 'status' => $ok ? 'fulfilled' : 'failed' ),
				array( 'id' => $redemption_id )
			);
		} else {
			// Custom — fire hook for third-party fulfillment.
			$wpdb->update( $wpdb->prefix . 'wb_gam_redemptions', array( 'status' => 'pending_fulfillment' ), array( 'id' => $redemption_id ) );
		}

		wp_cache_delete( "wb_gam_total_{$user_id}", self::CACHE_GROUP );

		/**
		 * Fires after a redemption is created.
		 *
		 * @param int    $redemption_id Redemption record ID.
		 * @param int    $user_id       User who redeemed.
		 * @param array  $item          Reward item data.
		 * @param string|null $coupon_code WooCommerce coupon code, or null.
		 */
		do_action( 'wb_gamification_points_redeemed', $redemption_id, $user_id, $item, $coupon_code );

		return array(
			'success'       => true,
			'redemption_id' => $redemption_id,
			'coupon_code'   => $coupon_code,
			'error'         => null,
		);
	}

	// ── WooCommerce coupon creation ──────────────────────────────────────────

	/**
	 * Create a WooCommerce coupon code for a discount reward.
	 *
	 * @param int   $user_id       User redeeming the reward.
	 * @param array $item          Redemption item data.
	 * @param array $config        Decoded reward_config JSON.
	 * @param int   $redemption_id Redemption record ID used to seed the coupon code.
	 * @return string|null Generated coupon code or null on failure.
	 */
	private static function create_woo_coupon( int $user_id, array $item, array $config, int $redemption_id ): ?string {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$code   = strtoupper( 'WBG-' . substr( md5( $redemption_id . $user_id . microtime( true ) ), 0, 8 ) );
		$type   = $item['reward_type'];
		$amount = (float) ( $config['amount'] ?? 10 );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );

		switch ( $type ) {
			case 'discount_pct':
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( $amount );
				break;
			case 'discount_fixed':
				$coupon->set_discount_type( 'fixed_cart' );
				$coupon->set_amount( $amount );
				break;
			case 'free_shipping':
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( 0 );
				$coupon->set_free_shipping( true );
				break;
			case 'free_product':
				$product_id = (int) ( $config['product_id'] ?? 0 );
				if ( $product_id <= 0 ) {
					Log::warning( 'redemption: free_product missing product_id', array( 'item_id' => $item['id'] ?? 0 ) );
					return null;
				}
				$coupon->set_discount_type( 'percent' );
				$coupon->set_amount( 100 );
				$coupon->set_product_ids( array( $product_id ) );
				break;
			default:
				return null;
		}

		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_email_restrictions( array( $user->user_email ) );
		$coupon->set_individual_use( true );
		$coupon->set_date_expires( strtotime( '+30 days' ) );
		$coupon->set_description( sprintf( 'Redeemed via WB Gamification — %s', $item['title'] ) );
		$coupon->save();

		return $code;
	}

	/**
	 * Top up a Wbcom Credits SDK ledger when the reward type is `wbcom_credits`.
	 *
	 * Uses the SDK's static API; safe no-op if the SDK is not loaded on this
	 * install (e.g. the issuing plugin was deactivated). Logs the failure so
	 * the admin can see why the redemption sits as `failed`.
	 *
	 * @param int   $user_id User receiving the credits.
	 * @param array $item    Reward item row.
	 * @param array $config  Decoded reward_config: { slug: string, amount: int }.
	 * @return bool True on successful topup.
	 */
	private static function topup_wbcom_credits( int $user_id, array $item, array $config ): bool {
		if ( ! class_exists( '\Wbcom\Credits\Credits' ) ) {
			Log::warning(
				'redemption: wbcom_credits reward attempted but SDK not loaded',
				array( 'item_id' => $item['id'] ?? 0 )
			);
			return false;
		}

		$slug   = isset( $config['slug'] ) ? sanitize_key( (string) $config['slug'] ) : '';
		$amount = isset( $config['amount'] ) ? (int) $config['amount'] : 0;

		if ( '' === $slug || $amount <= 0 ) {
			Log::warning(
				'redemption: wbcom_credits reward has invalid config',
				array(
					'item_id' => $item['id'] ?? 0,
					'slug'    => $slug,
					'amount'  => $amount,
				)
			);
			return false;
		}

		$note   = sprintf( 'WB Gamification redemption — %s', $item['title'] );
		$result = \Wbcom\Credits\Credits::topup( $slug, $user_id, $amount, $note );

		if ( false === $result ) {
			Log::error(
				'redemption: wbcom_credits topup returned false',
				array(
					'user_id' => $user_id,
					'slug'    => $slug,
					'amount'  => $amount,
				)
			);
			return false;
		}

		return true;
	}

	// ── User redemption history ──────────────────────────────────────────────

	/**
	 * Get a user's redemption history.
	 *
	 * @param int $user_id User ID to retrieve history for.
	 * @param int $limit   Maximum number of records to return.
	 * @return array Array of redemption history rows.
	 */
	public static function get_user_redemptions( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.points_cost, r.status, r.coupon_code, r.created_at,
				        i.title, i.reward_type
				   FROM {$wpdb->prefix}wb_gam_redemptions r
				   JOIN {$wpdb->prefix}wb_gam_redemption_items i ON i.id = r.item_id
				  WHERE r.user_id = %d
				  ORDER BY r.created_at DESC
				  LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}
