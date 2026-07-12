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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Registers WordPress privacy data exporters and erasers for gamification data.
 *
 * @package WB_Gamification
 */
final class Privacy {

	/**
	 * Items per export page.
	 *
	 * WordPress's privacy framework calls an exporter repeatedly with an
	 * incrementing `$page` for as long as it returns `done => false`, precisely so
	 * that no exporter ever has to hold a member's entire history in memory. 500 is
	 * the convention core itself uses.
	 *
	 * @since 1.6.4
	 * @var int
	 */
	private const EXPORT_PAGE_SIZE = 500;

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

		// Member-level toggle — opt-OUT model (default ON, see
		// ProfilePage::is_publicly_visible). Only an explicit '0' is private.
		return '0' !== (string) get_user_meta( $target_id, 'wb_gam_profile_public', true );
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
		$page    = max( 1, $page );
		$offset  = ( $page - 1 ) * self::EXPORT_PAGE_SIZE;
		global $wpdb;

		// The two unbounded sets — a member's points ledger and their event log —
		// are streamed across pages. Everything else (balances, badges, streak,
		// preferences, submissions) is bounded per member and ships on page 1.
		//
		// Until 1.6.4 this method ACCEPTED $page and never referenced it: every
		// call selected the member's ENTIRE history with no LIMIT and returned
		// `done => true`. WordPress's privacy framework is built around ~500 items
		// per page precisely so an exporter never has to hold a whole dataset in
		// memory — this one opted out of that contract and then paid for it.
		//
		// The rows are not the expensive part; the expansion is. Each ledger row
		// becomes a data_group carrying four name/value pairs, and each event row
		// six — including its metadata JSON blob. That is roughly 1.5-2 KB of PHP
		// heap per row, so a member with 50,000 ledger rows (and members typically
		// have MORE event rows than ledger rows, since events are logged even when
		// no points are awarded) exhausts a 256 MB limit and fatals. A GDPR request
		// from your most engaged member was the one guaranteed to fail.
		$points_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d", $user_id )
		);
		$events_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events WHERE user_id = %d", $user_id )
		);

		$data_groups = array();

		// Points summary — one row per currency on multi-currency sites.
		$balances     = PointsEngine::get_totals_by_type( $user_id );
		$pt_service   = new \WBGam\Services\PointTypeService();
		$summary_rows = array();
		foreach ( $pt_service->list() as $pt ) {
			$slug           = (string) $pt['slug'];
			$label          = (string) $pt['label'];
			$total          = (int) ( $balances[ $slug ] ?? 0 );
			$summary_rows[] = array(
				'name'  => sprintf(
					/* translators: %s: currency label (e.g. "Points", "Coins"). */
					__( 'Total %s', 'wb-gamification' ),
					$label
				),
				'value' => (string) $total,
			);
		}
		// Page 1 only — like the other bounded groups. Its item_id is derived from
		// the user id, so emitting it on every page would collide with itself.
		if ( 1 === $page ) {
			$data_groups[] = array(
				'group_id'    => 'wb-gam-points',
				'group_label' => __( 'Gamification Points', 'wb-gamification' ),
				'item_id'     => 'wb-gam-points-' . $user_id,
				'data'        => $summary_rows,
			);
		}

		// Points history — the first of the two streamed sets.
		//
		// Slice of the ledger for THIS page only. `$offset` walks the combined
		// (ledger, then events) stream, so once the ledger is exhausted the
		// remainder of the page is filled from the event log below.
		$hist_offset = min( $offset, $points_total );
		$hist_limit  = max( 0, min( self::EXPORT_PAGE_SIZE, $points_total - $hist_offset ) );

		$history = array();
		if ( $hist_limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paginated personal-data export.
			$history = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, points, point_type, created_at
					   FROM {$wpdb->prefix}wb_gam_points
					  WHERE user_id = %d
					  ORDER BY created_at DESC, id DESC
					  LIMIT %d OFFSET %d",
					$user_id,
					$hist_limit,
					$hist_offset
				),
				ARRAY_A
			) ?: array();
		}

		foreach ( $history as $i => $row ) {
			// item_id must be unique across the WHOLE export, not within a page.
			// A per-page loop index would make page 2's first row collide with
			// page 1's and silently overwrite it in the exported archive.
			$item_index    = $hist_offset + $i;
			$data_groups[] = array(
				'group_id'    => 'wb-gam-points-history',
				'group_label' => __( 'Points History', 'wb-gamification' ),
				'item_id'     => 'wb-gam-ph-' . $item_index,
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

		// Bounded per-member groups — badges, streak, preferences, meta,
		// submissions. These ship ONCE, on page 1. Emitting them on every page of
		// a paginated export would repeat a member's badges on all 40 pages.
		if ( 1 === $page ) {
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

			// Per-user toggles + derived caches stored in user_meta. Each is T2
			// personal data and belongs in the export under data portability.
			$meta_groups = array(
				'wb_gam_profile_public'        => __( 'Public profile enabled (per-user)', 'wb-gamification' ),
				'wb_gam_login_streak'          => __( 'Login bonus streak (current)', 'wb-gamification' ),
				'wb_gam_login_streak_max'      => __( 'Login bonus streak (best)', 'wb-gamification' ),
				'wb_gam_login_last_award'      => __( 'Login bonus last awarded', 'wb-gamification' ),
				'wb_gam_seen_first_earn_toast' => __( 'Seen first-earn welcome toast', 'wb-gamification' ),
				'wb_gam_dismissed_welcome'     => __( 'Dismissed admin welcome card', 'wb-gamification' ),
				'wb_gam_dismissed_checklist'   => __( 'Dismissed admin setup checklist', 'wb-gamification' ),
				'wb_gam_setup_seen'            => __( 'Seen the setup wizard', 'wb-gamification' ),
				'wb_gam_level_id'              => __( 'Current level ID (cached)', 'wb-gamification' ),
				'wb_gam_level_name'            => __( 'Current level name (cached)', 'wb-gamification' ),
				'wb_gam_league_tier'           => __( 'Cohort league tier', 'wb-gamification' ),
				'wb_gam_sandboxed'             => __( 'Excluded from earning (sandboxed)', 'wb-gamification' ),
			);
			$meta_rows   = array();
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
						array(
							'name'  => __( 'Action', 'wb-gamification' ),
							'value' => $row['action_id'],
						),
						array(
							'name'  => __( 'Evidence', 'wb-gamification' ),
							'value' => (string) $row['evidence'],
						),
						array(
							'name'  => __( 'Evidence URL', 'wb-gamification' ),
							'value' => (string) $row['evidence_url'],
						),
						array(
							'name'  => __( 'Status', 'wb-gamification' ),
							'value' => $row['status'],
						),
						array(
							'name'  => __( 'Reviewer notes', 'wb-gamification' ),
							'value' => (string) $row['notes'],
						),
						array(
							'name'  => __( 'Submitted', 'wb-gamification' ),
							'value' => $row['created_at'],
						),
						array(
							'name'  => __( 'Reviewed', 'wb-gamification' ),
							'value' => (string) ( $row['reviewed_at'] ?? '' ),
						),
					),
				);
			}

			// Full event log (immutable T2 record). Belongs in the export under
			// data portability — it's the authoritative log of what the user did
			// on the site (with metadata context). Distinct from points-history,
			// which is the derived ledger.
		}

		// Event log — the second streamed set. It picks up wherever the ledger
		// stream ran out, so a page is always full until the export is done.
		$consumed_by_history = count( $history );
		$ev_room             = max( 0, self::EXPORT_PAGE_SIZE - $consumed_by_history );
		$ev_offset           = max( 0, $offset - $points_total );
		$ev_limit            = max( 0, min( $ev_room, $events_total - $ev_offset ) );

		$events = array();
		if ( $ev_limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paginated personal-data export.
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, object_id, metadata, point_type, site_id, created_at
					   FROM {$wpdb->prefix}wb_gam_events
					  WHERE user_id = %d
					  ORDER BY created_at DESC, id DESC
					  LIMIT %d OFFSET %d",
					$user_id,
					$ev_limit,
					$ev_offset
				),
				ARRAY_A
			) ?: array();
		}
		foreach ( $events as $i => $row ) {
			$item_index    = $ev_offset + $i;
			$data_groups[] = array(
				'group_id'    => 'wb-gam-events',
				'group_label' => __( 'Activity Event Log', 'wb-gamification' ),
				'item_id'     => 'wb-gam-event-' . $item_index,
				'data'        => array(
					array(
						'name'  => __( 'Action', 'wb-gamification' ),
						'value' => $row['action_id'],
					),
					array(
						'name'  => __( 'Object ID', 'wb-gamification' ),
						'value' => (string) ( $row['object_id'] ?? '' ),
					),
					array(
						'name'  => __( 'Metadata', 'wb-gamification' ),
						'value' => (string) ( $row['metadata'] ?? '' ),
					),
					array(
						'name'  => __( 'Currency', 'wb-gamification' ),
						'value' => (string) ( $row['point_type'] ?? '' ),
					),
					array(
						'name'  => __( 'Site ID', 'wb-gamification' ),
						'value' => (string) ( $row['site_id'] ?? '' ),
					),
					array(
						'name'  => __( 'Date', 'wb-gamification' ),
						'value' => $row['created_at'],
					),
				),
			);
		}

		// Done only when the combined (ledger + events) stream is exhausted.
		// Returning done=true early is how the pre-1.6.4 code got away with
		// ignoring $page -- and how a large member's export silently truncated
		// if it did not fatal first.
		$done = ( $offset + self::EXPORT_PAGE_SIZE ) >= ( $points_total + $events_total );

		return array(
			'data' => $data_groups,
			'done' => $done,
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
		// Removing a member's badges changes every badge's rarity — drop the
		// cached aggregation (BadgeEngine owns the table and the cache).
		BadgeEngine::flush_rarity_cache();

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

		// User meta — personal-record keys + v1.0 sprint additions + derived
		// caches. All of these are user-scoped data and must be removed under
		// GDPR Art. 17. The list mirrors the export side above; bin/coding-
		// rules-check.sh Rule 11 enforces both lists stay in sync as new
		// surfaces are added.
		$user_meta_keys = array(
			'wb_gam_pr_best_week',         // personal-record best week.
			'wb_gam_login_streak',         // login bonus engine — current streak.
			'wb_gam_login_streak_max',     // login bonus engine — best streak.
			'wb_gam_login_last_award',     // login bonus engine — last award timestamp.
			'wb_gam_seen_first_earn_toast', // notification bridge — one-time flag.
			'wb_gam_dismissed_welcome',    // settings page — admin welcome dismissal.
			'wb_gam_dismissed_checklist',  // settings page — admin setup-checklist dismissal.
			'wb_gam_setup_seen',           // SetupWizard — admin wizard dismissal flag.
			'wb_gam_profile_public',       // member's own privacy choice.
			'wb_gam_level_id',             // LevelEngine — denormalized current level (cache).
			'wb_gam_level_name',           // LevelEngine — denormalized level name (cache).
			'wb_gam_league_tier',          // CohortEngine — current cohort league tier.
			'wb_gam_sandboxed',            // Access settings — per-user earning veto.
			// Notification consumer cursors — NotificationBridge writes one
			// per consumer (footer/heartbeat/rest). Pre-1.4.1 these three
			// integers survived GDPR erase as orphan rows keyed on the
			// deleted user's id (audit/DATA-FLOW-NOTIFICATIONS-2026-05-27.md §G8).
			'wb_gam_notif_cursor_footer',
			'wb_gam_notif_cursor_heartbeat',
			'wb_gam_notif_cursor_rest',
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
