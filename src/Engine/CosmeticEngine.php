<?php
/**
 * Cosmetic Rewards Engine
 *
 * Avatar frames, profile decorations, and profile themes that members
 * can earn or unlock. Purely visual — cosmetics do not affect points,
 * leaderboard ranking, or any other gamification state.
 *
 * Khan Academy / Steam model:
 *   - Cosmetics signal investment and community tenure.
 *   - Members can own multiple cosmetics and activate one per type.
 *   - Awarded via: admin grant, point redemption, or automation hook.
 *
 * Cosmetic types:
 *   avatar_frame  — CSS ring/border overlaid on the BuddyPress avatar.
 *   profile_badge — Small decorative badge pinned to the profile card.
 *   profile_theme — Colour theme applied to the member's profile page.
 *
 * BuddyPress integration:
 *   - Hooks bp_get_the_member_avatar_url to inject the active frame CSS class.
 *   - Adds wb-gam-frame-{id} class to the avatar wrapper when a frame is active.
 *
 * REST surface: provided by MembersController (GET /members/{id}/cosmetics,
 * POST /members/{id}/cosmetics/{cosmetic_id}/activate).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Avatar frames, profile decorations, and profile themes that members can earn or unlock.
 *
 * @package WB_Gamification
 */
final class CosmeticEngine {

	private const CACHE_GROUP = 'wb_gamification';

	// ── Boot ─────────────────────────────────────────────────────────────────

	/**
	 * Register BuddyPress and redemption hooks for cosmetic integration.
	 */
	public static function init(): void {
		// Inject active frame CSS class onto BuddyPress avatar wrapper.
		add_filter( 'bp_get_the_member_avatar_class', array( __CLASS__, 'inject_avatar_frame_class' ), 10, 2 );

		// Award cosmetics when a custom-type redemption fires with cosmetic config.
		add_action( 'wb_gamification_points_redeemed', array( __CLASS__, 'handle_redemption' ), 10, 4 );
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Grant a cosmetic to a user.
	 *
	 * Safe to call multiple times — uses INSERT IGNORE so duplicates are no-ops.
	 *
	 * @param int    $user_id     User receiving the cosmetic.
	 * @param string $cosmetic_id Cosmetic identifier.
	 * @return bool               True if newly granted (false if already owned).
	 */
	public static function grant( int $user_id, string $cosmetic_id ): bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}wb_gam_user_cosmetics
				 (user_id, cosmetic_id, is_active) VALUES (%d, %s, 0)",
				$user_id,
				$cosmetic_id
			)
		);

		if ( $result ) {
			wp_cache_delete( "wb_gam_cosmetics_{$user_id}", self::CACHE_GROUP );

			/**
			 * Fires after a cosmetic is granted to a user.
			 *
			 * @param int    $user_id     User who received the cosmetic.
			 * @param string $cosmetic_id Cosmetic identifier.
			 */
			do_action( 'wb_gamification_cosmetic_granted', $user_id, $cosmetic_id );
		}

		return (bool) $result;
	}

	/**
	 * Activate a cosmetic for a user (deactivates others of the same type).
	 *
	 * @param int    $user_id     User activating the cosmetic.
	 * @param string $cosmetic_id Cosmetic identifier.
	 * @return bool               True on success, false if user doesn't own it.
	 */
	public static function activate( int $user_id, string $cosmetic_id ): bool {
		global $wpdb;

		// Verify ownership.
		$owns = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_cosmetics
				 WHERE user_id = %d AND cosmetic_id = %s",
				$user_id,
				$cosmetic_id
			)
		);

		if ( ! $owns ) {
			return false;
		}

		$cosmetic = self::get_cosmetic( $cosmetic_id );
		if ( ! $cosmetic ) {
			return false;
		}

		// Deactivate all cosmetics of the same type for this user.
		$ids_of_same_type = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uc.cosmetic_id
				   FROM {$wpdb->prefix}wb_gam_user_cosmetics uc
				   JOIN {$wpdb->prefix}wb_gam_cosmetics c ON c.id = uc.cosmetic_id
				  WHERE uc.user_id = %d AND c.type = %s",
				$user_id,
				$cosmetic['type']
			)
		);

		if ( $ids_of_same_type ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids_of_same_type ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is implode(',', array_fill(..., '%s')), safe.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}wb_gam_user_cosmetics
				    SET is_active = 0
				  WHERE user_id = %d AND cosmetic_id IN ($placeholders)",
					array_merge( array( $user_id ), $ids_of_same_type )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Activate the requested cosmetic.
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_user_cosmetics',
			array( 'is_active' => 1 ),
			array(
				'user_id'     => $user_id,
				'cosmetic_id' => $cosmetic_id,
			),
			array( '%d' ),
			array( '%d', '%s' )
		);

		wp_cache_delete( "wb_gam_cosmetics_{$user_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Get all cosmetics owned by a user.
	 *
	 * @param int $user_id User to query.
	 * @return array<int, array{ cosmetic_id: string, name: string, type: string, asset_url: string, css_class: string, is_active: bool }>
	 */
	public static function get_user_cosmetics( int $user_id ): array {
		$cache_key = "wb_gam_cosmetics_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uc.cosmetic_id, c.name, c.type, c.asset_url, c.css_class, uc.is_active
				   FROM {$wpdb->prefix}wb_gam_user_cosmetics uc
				   JOIN {$wpdb->prefix}wb_gam_cosmetics c ON c.id = uc.cosmetic_id
				  WHERE uc.user_id = %d AND c.is_active = 1
				  ORDER BY c.type, uc.awarded_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		foreach ( $rows as &$row ) {
			$row['is_active'] = (bool) $row['is_active'];
		}
		unset( $row );

		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, 300 );

		return $rows;
	}

	/**
	 * Get the active cosmetic of a given type for a user.
	 *
	 * @param int    $user_id User to query.
	 * @param string $type    Cosmetic type (avatar_frame, profile_badge, profile_theme).
	 * @return array|null     Cosmetic data, or null if none active.
	 */
	public static function get_active( int $user_id, string $type ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT uc.cosmetic_id, c.name, c.type, c.asset_url, c.css_class
				   FROM {$wpdb->prefix}wb_gam_user_cosmetics uc
				   JOIN {$wpdb->prefix}wb_gam_cosmetics c ON c.id = uc.cosmetic_id
				  WHERE uc.user_id = %d AND c.type = %s AND uc.is_active = 1
				  LIMIT 1",
				$user_id,
				$type
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	// ── BuddyPress integration ────────────────────────────────────────────────

	/**
	 * Inject active avatar frame CSS class into the BP avatar element.
	 *
	 * @param string $class   Existing CSS classes.
	 * @param int    $user_id Member being rendered (0 if unknown).
	 * @return string
	 */
	public static function inject_avatar_frame_class( string $class, int $user_id ): string {
		if ( ! $user_id ) {
			return $class;
		}

		$frame = self::get_active( $user_id, 'avatar_frame' );
		if ( ! $frame || empty( $frame['css_class'] ) ) {
			return $class;
		}

		return trim( $class . ' wb-gam-frame ' . sanitize_html_class( $frame['css_class'] ) );
	}

	// ── Redemption handler ───────────────────────────────────────────────────

	/**
	 * Award a cosmetic when a 'custom' redemption item has cosmetic config.
	 *
	 * @param int         $redemption_id Redemption record ID.
	 * @param int         $user_id       User who redeemed.
	 * @param array       $item          Reward item data.
	 * @param string|null $coupon_code   Not used for cosmetic rewards.
	 */
	public static function handle_redemption( int $redemption_id, int $user_id, array $item, ?string $coupon_code ): void {
		if ( 'custom' !== $item['reward_type'] ) {
			return;
		}

		$config      = json_decode( $item['reward_config'] ?? '{}', true ) ?: array();
		$cosmetic_id = $config['cosmetic_id'] ?? '';

		if ( ! $cosmetic_id ) {
			return;
		}

		self::grant( $user_id, $cosmetic_id );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Fetch a single cosmetic definition row by ID.
	 *
	 * @param string $cosmetic_id Cosmetic identifier.
	 * @return array|null Cosmetic row, or null if not found.
	 */
	private static function get_cosmetic( string $cosmetic_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, type, asset_url, css_class, award_type, cost, is_active
				   FROM {$wpdb->prefix}wb_gam_cosmetics WHERE id = %s",
				$cosmetic_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}
}
