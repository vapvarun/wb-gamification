<?php
/**
 * WB Gamification — Privacy / GDPR
 *
 * Registers personal data exporter and eraser with the WordPress privacy
 * framework (Tools > Export Personal Data / Erase Personal Data).
 *
 * Data erased:
 *   - wb_gam_events         (all rows for user_id)
 *   - wb_gam_points         (all rows for user_id)
 *   - wb_gam_user_badges    (all rows for user_id)
 *   - wb_gam_streaks        (row for user_id)
 *   - wb_gam_challenge_log  (all rows for user_id)
 *   - wb_gam_kudos          (giver_id and receiver_id rows)
 *   - wb_gam_partners       (user_id_1 and user_id_2 rows)
 *   - wb_gam_member_prefs   (row for user_id)
 *   - User meta: wb_gam_pr_* keys
 *
 * Data exported:
 *   - Points total + history
 *   - Badges earned
 *   - Streak stats
 *   - Member preferences
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress privacy data exporters and erasers for gamification data.
 *
 * @package WB_Gamification
 */
final class Privacy {

	/**
	 * Register GDPR exporter and eraser callbacks with WordPress.
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	// ── Registration ────────────────────────────────────────────────────────

	/**
	 * Add the gamification data exporter to the WordPress exporters list.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array Modified exporters array.
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wb-gamification'] = array(
			'exporter_friendly_name' => __( 'WB Gamification', 'wb-gamification' ),
			'callback'               => array( __CLASS__, 'export_user_data' ),
		);
		return $exporters;
	}

	/**
	 * Add the gamification data eraser to the WordPress erasers list.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array Modified erasers array.
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['wb-gamification'] = array(
			'eraser_friendly_name' => __( 'WB Gamification', 'wb-gamification' ),
			'callback'             => array( __CLASS__, 'erase_user_data' ),
		);
		return $erasers;
	}

	// ── Exporter ────────────────────────────────────────────────────────────

	/**
	 * Export all gamification data for the given email address.
	 *
	 * @param string $email_address User email address to export data for.
	 * @param int    $page          1-based page (WordPress sends up to 500 items/page).
	 * @return array Export data array with 'data' and 'done' keys.
	 */
	public static function export_user_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = (int) $user->ID;
		global $wpdb;

		$data_groups = array();

		// Points summary.
		$total         = PointsEngine::get_total( $user_id );
		$data_groups[] = array(
			'group_id'    => 'wb-gam-points',
			'group_label' => __( 'Gamification Points', 'wb-gamification' ),
			'item_id'     => 'wb-gam-points-' . $user_id,
			'data'        => array(
				array(
					'name'  => __( 'Total Points', 'wb-gamification' ),
					'value' => (string) $total,
				),
			),
		);

		// Points history (all).
		$history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, points, created_at FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		foreach ( $history as $i => $row ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-points-history',
				'group_label' => __( 'Points History', 'wb-gamification' ),
				'item_id'     => 'wb-gam-ph-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Action', 'wb-gamification' ),
						'value' => $row['action_id'],
					),
					array(
						'name'  => __( 'Points', 'wb-gamification' ),
						'value' => $row['points'],
					),
					array(
						'name'  => __( 'Date', 'wb-gamification' ),
						'value' => $row['created_at'],
					),
				),
			);
		}

		// Badges.
		$badges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.name, b.description, ub.earned_at
				   FROM {$wpdb->prefix}wb_gam_user_badges ub
				   JOIN {$wpdb->prefix}wb_gam_badge_defs b ON b.id = ub.badge_id
				  WHERE ub.user_id = %d ORDER BY ub.earned_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();

		foreach ( $badges as $i => $row ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-badges',
				'group_label' => __( 'Earned Badges', 'wb-gamification' ),
				'item_id'     => 'wb-gam-badge-' . $i,
				'data'        => array(
					array(
						'name'  => __( 'Badge', 'wb-gamification' ),
						'value' => $row['name'],
					),
					array(
						'name'  => __( 'Description', 'wb-gamification' ),
						'value' => $row['description'],
					),
					array(
						'name'  => __( 'Earned At', 'wb-gamification' ),
						'value' => $row['earned_at'],
					),
				),
			);
		}

		// Streak.
		$streak = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT current_streak, longest_streak, last_active FROM {$wpdb->prefix}wb_gam_streaks WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		if ( $streak ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-streak',
				'group_label' => __( 'Activity Streak', 'wb-gamification' ),
				'item_id'     => 'wb-gam-streak-' . $user_id,
				'data'        => array(
					array(
						'name'  => __( 'Current Streak', 'wb-gamification' ),
						'value' => $streak['current_streak'],
					),
					array(
						'name'  => __( 'Longest Streak', 'wb-gamification' ),
						'value' => $streak['longest_streak'],
					),
					array(
						'name'  => __( 'Last Active', 'wb-gamification' ),
						'value' => $streak['last_active'],
					),
				),
			);
		}

		// Member preferences.
		$prefs = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT leaderboard_opt_out, show_rank, notification_mode FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		if ( $prefs ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-prefs',
				'group_label' => __( 'Gamification Preferences', 'wb-gamification' ),
				'item_id'     => 'wb-gam-prefs-' . $user_id,
				'data'        => array(
					array(
						'name'  => __( 'Leaderboard Opt-Out', 'wb-gamification' ),
						'value' => $prefs['leaderboard_opt_out'] ? __( 'Yes', 'wb-gamification' ) : __( 'No', 'wb-gamification' ),
					),
					array(
						'name'  => __( 'Show Rank', 'wb-gamification' ),
						'value' => $prefs['show_rank'] ? __( 'Yes', 'wb-gamification' ) : __( 'No', 'wb-gamification' ),
					),
					array(
						'name'  => __( 'Notification Mode', 'wb-gamification' ),
						'value' => $prefs['notification_mode'],
					),
				),
			);
		}

		return array(
			'data' => $data_groups,
			'done' => true,
		);
	}

	// ── Eraser ──────────────────────────────────────────────────────────────

	/**
	 * Erase all gamification data for the given email address.
	 *
	 * @param string $email_address User email address to erase data for.
	 * @param int    $page          1-based page number.
	 * @return array Result array with 'items_removed', 'items_retained', 'messages', and 'done' keys.
	 */
	public static function erase_user_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;
		global $wpdb;

		$removed = 0;

		// Events log.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_events', array( 'user_id' => $user_id ), array( '%d' ) );

		// Points ledger.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_points', array( 'user_id' => $user_id ), array( '%d' ) );

		// Earned badges.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_user_badges', array( 'user_id' => $user_id ), array( '%d' ) );

		// Streak.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_streaks', array( 'user_id' => $user_id ), array( '%d' ) );

		// Challenge log.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_challenge_log', array( 'user_id' => $user_id ), array( '%d' ) );

		// Kudos — as giver.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_kudos', array( 'giver_id' => $user_id ), array( '%d' ) );

		// Kudos — as receiver.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_kudos', array( 'receiver_id' => $user_id ), array( '%d' ) );

		// Accountability partners.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_partners', array( 'user_id_1' => $user_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wb_gam_partners', array( 'user_id_2' => $user_id ), array( '%d' ) );

		// Member preferences.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_member_prefs', array( 'user_id' => $user_id ), array( '%d' ) );

		// User meta (personal-record keys).
		$wpdb->delete( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- GDPR erasure must target a specific meta_key.
			$wpdb->usermeta,
			array(
				'user_id'  => $user_id,
				'meta_key' => 'wb_gam_pr_best_week', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			),
			array( '%d', '%s' )
		);

		// Bust object cache.
		wp_cache_delete( 'wb_gam_points_' . $user_id, 'wb_gamification' );

		/**
		 * Fires after a user's gamification data has been erased.
		 *
		 * @param int $user_id The user ID whose data was erased.
		 */
		do_action( 'wb_gamification_user_data_erased', $user_id );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed > 0
				? array(
					sprintf(
						/* translators: %d = number of database rows removed */
						__( 'Gamification data removed (%d rows).', 'wb-gamification' ),
						$removed
					),
				)
				: array(),
			'done'           => true,
		);
	}
}
