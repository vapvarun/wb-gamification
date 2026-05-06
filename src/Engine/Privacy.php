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

	// ── Permission helpers (single source of truth for the privacy model) ───
	//
	// Every REST permission_callback, block render, shortcode, and profile-page
	// renderer that exposes member data MUST consult one of these two methods.
	// Local copies of the privacy logic are forbidden — see plan/PRIVACY-MODEL.md
	// § Cross-cutting principles #2 ("No callback-deferred enforcement").

	/**
	 * Can the viewer read the target member's T1 (achievements) data?
	 *
	 * T1 = display_name, level, badges, points total, badges count, level
	 * progress %. Public when both site + member switches are ON, OR when
	 * the viewer is the target themselves OR an admin.
	 *
	 * @param int      $target_id User whose data is requested.
	 * @param int|null $viewer_id Defaults to current user ID (0 for anon).
	 * @return bool True if the viewer may read T1 data for the target.
	 */
	public static function can_view_public_profile( int $target_id, ?int $viewer_id = null ): bool {
		if ( $target_id <= 0 ) {
			return false;
		}
		$viewer_id = $viewer_id ?? get_current_user_id();

		// Self or admin always allowed.
		if ( $viewer_id > 0 && ( $viewer_id === $target_id || user_can( $viewer_id, 'manage_options' ) ) ) {
			return true;
		}

		// Site-level kill switch.
		if ( ! (bool) get_option( 'wb_gam_profile_public_enabled', true ) ) {
			return false;
		}

		// Member-level toggle.
		return (bool) get_user_meta( $target_id, 'wb_gam_profile_public', true );
	}

	/**
	 * Can the viewer read the target member's T2 (behavioral history) data?
	 *
	 * T2 = points history rows, event log with metadata, streak heatmap,
	 * last_active timestamp, preferences object, points_by_type breakdown.
	 * Always private to self + admin only — never togglable. This is the
	 * trust line the plugin holds (see plan/PRIVACY-MODEL.md § The mental
	 * model).
	 *
	 * @param int      $target_id User whose data is requested.
	 * @param int|null $viewer_id Defaults to current user ID.
	 * @return bool True if the viewer may read T2 data for the target.
	 */
	public static function can_view_private_history( int $target_id, ?int $viewer_id = null ): bool {
		if ( $target_id <= 0 ) {
			return false;
		}
		$viewer_id = $viewer_id ?? get_current_user_id();
		if ( $viewer_id <= 0 ) {
			return false;
		}
		return $viewer_id === $target_id || user_can( $viewer_id, 'manage_options' );
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

		// Points summary — one row per currency on multi-currency sites.
		$balances     = PointsEngine::get_totals_by_type( $user_id );
		$pt_service   = new \WBGam\Services\PointTypeService();
		$summary_rows = array();
		foreach ( $pt_service->list() as $pt ) {
			$slug   = (string) $pt['slug'];
			$label  = (string) $pt['label'];
			$total  = (int) ( $balances[ $slug ] ?? 0 );
			$summary_rows[] = array(
				'name'  => sprintf(
					/* translators: %s: currency label (e.g. "Points", "Coins"). */
					__( 'Total %s', 'wb-gamification' ),
					$label
				),
				'value' => (string) $total,
			);
		}
		$data_groups[] = array(
			'group_id'    => 'wb-gam-points',
			'group_label' => __( 'Gamification Points', 'wb-gamification' ),
			'item_id'     => 'wb-gam-points-' . $user_id,
			'data'        => $summary_rows,
		);

		// Points history (all currencies).
		$history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, points, point_type, created_at FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d ORDER BY created_at DESC",
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
						'name'  => __( 'Currency', 'wb-gamification' ),
						'value' => (string) ( $row['point_type'] ?? '' ),
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

		// Per-user toggles stored in user_meta (v1.0 sprint additions). Each
		// is T2 personal data and belongs in the export under data portability.
		$meta_groups = array(
			'wb_gam_profile_public'         => __( 'Public profile enabled (per-user)', 'wb-gamification' ),
			'wb_gam_login_streak'           => __( 'Login bonus streak (current)', 'wb-gamification' ),
			'wb_gam_login_streak_max'       => __( 'Login bonus streak (best)', 'wb-gamification' ),
			'wb_gam_login_last_award'       => __( 'Login bonus last awarded', 'wb-gamification' ),
			'wb_gam_seen_first_earn_toast'  => __( 'Seen first-earn welcome toast', 'wb-gamification' ),
			'wb_gam_dismissed_welcome'      => __( 'Dismissed admin welcome card', 'wb-gamification' ),
		);
		$meta_rows = array();
		foreach ( $meta_groups as $key => $label ) {
			$value = get_user_meta( $user_id, $key, true );
			if ( '' === $value || null === $value ) {
				continue;
			}
			$meta_rows[] = array(
				'name'  => $label,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		if ( $meta_rows ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-user-meta',
				'group_label' => __( 'Gamification Personal Settings', 'wb-gamification' ),
				'item_id'     => 'wb-gam-user-meta-' . $user_id,
				'data'        => $meta_rows,
			);
		}

		// UGC submissions (v1.0 sprint).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-shot personal-data export query.
		$submissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, evidence, evidence_url, status, notes, created_at, reviewed_at
				   FROM {$wpdb->prefix}wb_gam_submissions
				  WHERE user_id = %d
				  ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();
		foreach ( $submissions as $i => $row ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-submissions',
				'group_label' => __( 'Achievement Submissions', 'wb-gamification' ),
				'item_id'     => 'wb-gam-submission-' . $i,
				'data'        => array(
					array( 'name' => __( 'Action', 'wb-gamification' ),       'value' => $row['action_id'] ),
					array( 'name' => __( 'Evidence', 'wb-gamification' ),     'value' => (string) $row['evidence'] ),
					array( 'name' => __( 'Evidence URL', 'wb-gamification' ), 'value' => (string) $row['evidence_url'] ),
					array( 'name' => __( 'Status', 'wb-gamification' ),       'value' => $row['status'] ),
					array( 'name' => __( 'Reviewer notes', 'wb-gamification' ), 'value' => (string) $row['notes'] ),
					array( 'name' => __( 'Submitted', 'wb-gamification' ),    'value' => $row['created_at'] ),
					array( 'name' => __( 'Reviewed', 'wb-gamification' ),     'value' => (string) ( $row['reviewed_at'] ?? '' ) ),
				),
			);
		}

		// Full event log (immutable T2 record). Belongs in the export under
		// data portability — it's the authoritative log of what the user did
		// on the site (with metadata context). Distinct from points-history,
		// which is the derived ledger.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-shot personal-data export query.
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, object_id, metadata, point_type, site_id, created_at
				   FROM {$wpdb->prefix}wb_gam_events
				  WHERE user_id = %d
				  ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: array();
		foreach ( $events as $i => $row ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-events',
				'group_label' => __( 'Activity Event Log', 'wb-gamification' ),
				'item_id'     => 'wb-gam-event-' . $i,
				'data'        => array(
					array( 'name' => __( 'Action', 'wb-gamification' ),    'value' => $row['action_id'] ),
					array( 'name' => __( 'Object ID', 'wb-gamification' ), 'value' => (string) ( $row['object_id'] ?? '' ) ),
					array( 'name' => __( 'Metadata', 'wb-gamification' ),  'value' => (string) ( $row['metadata'] ?? '' ) ),
					array( 'name' => __( 'Currency', 'wb-gamification' ),  'value' => (string) ( $row['point_type'] ?? '' ) ),
					array( 'name' => __( 'Site ID', 'wb-gamification' ),   'value' => (string) ( $row['site_id'] ?? '' ) ),
					array( 'name' => __( 'Date', 'wb-gamification' ),      'value' => $row['created_at'] ),
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

		// Snapshot the per-type currencies BEFORE we delete user_totals so we
		// can bust the matching object-cache keys after the transaction commits.
		$pt_slugs = array_column(
			(array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT point_type FROM {$wpdb->prefix}wb_gam_user_totals WHERE user_id = %d",
					$user_id
				),
				ARRAY_A
			),
			'point_type'
		);

		$removed = 0;

		// Atomic erase — every delete must succeed or none. Without the
		// transaction, an interruption between deletes leaves an inconsistent
		// half-erased state (e.g. ledger gone but user_totals intact, which
		// would let get_total() keep returning the stale balance).
		$wpdb->query( 'START TRANSACTION' );

		// Events log.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_events', array( 'user_id' => $user_id ), array( '%d' ) );

		// Points ledger.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_points', array( 'user_id' => $user_id ), array( '%d' ) );

		// Materialised user-totals — must mirror the ledger delete, otherwise
		// the user's row keeps a non-zero balance after GDPR erase. Same
		// applies to the leaderboard cache (snapshot retains the user's rank).
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_user_totals', array( 'user_id' => $user_id ), array( '%d' ) );
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_leaderboard_cache', array( 'user_id' => $user_id ), array( '%d' ) );

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

		// Member preferences.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_member_prefs', array( 'user_id' => $user_id ), array( '%d' ) );

		// UGC submissions (v1.0 sprint). The user's own submissions are deleted
		// outright. Submissions where the erased user was the *reviewer* (admin
		// action) are anonymized — reviewer_id zeroed but the row retained, so
		// the audit trail of what was approved/rejected isn't lost when an
		// admin departs.
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'wb_gam_submissions', array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_submissions',
			array( 'reviewer_id' => 0 ),
			array( 'reviewer_id' => $user_id ),
			array( '%d' ),
			array( '%d' )
		);

		// User meta — personal-record keys + v1.0 sprint additions. All of
		// these are user-scoped data and must be removed under GDPR Art. 17.
		$user_meta_keys = array(
			'wb_gam_pr_best_week',         // personal-record best week.
			'wb_gam_login_streak',         // login bonus engine — current streak.
			'wb_gam_login_streak_max',     // login bonus engine — best streak.
			'wb_gam_login_last_award',     // login bonus engine — last award timestamp.
			'wb_gam_seen_first_earn_toast', // notification bridge — one-time flag.
			'wb_gam_dismissed_welcome',    // settings page — admin welcome dismissal.
			'wb_gam_profile_public',       // member's own privacy choice.
		);
		foreach ( $user_meta_keys as $meta_key ) {
			delete_user_meta( $user_id, $meta_key );
		}

		$wpdb->query( 'COMMIT' );

		// Bust the per-type object-cache key matching what get_total reads.
		// Without this loop, get_total returns the cached pre-erase balance
		// for up to the cache TTL after the user's data is gone.
		foreach ( $pt_slugs as $slug ) {
			wp_cache_delete( PointsEngine::cache_key_total( $user_id, (string) $slug ), 'wb_gamification' );
		}

		/**
		 * Fires after a user's gamification data has been erased.
		 *
		 * @param int $user_id The user ID whose data was erased.
		 */
		do_action( 'wb_gam_user_data_erased', $user_id );

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
