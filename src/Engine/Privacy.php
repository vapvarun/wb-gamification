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
				'wb_gam_profile_public'          => __( 'Public profile enabled (per-user)', 'wb-gamification' ),
				'wb_gam_login_streak'            => __( 'Login bonus streak (current)', 'wb-gamification' ),
				'wb_gam_login_streak_max'        => __( 'Login bonus streak (best)', 'wb-gamification' ),
				'wb_gam_login_last_award'        => __( 'Login bonus last awarded', 'wb-gamification' ),
				'wb_gam_seen_first_earn_toast'   => __( 'Seen first-earn welcome toast', 'wb-gamification' ),
				'wb_gam_dismissed_welcome'       => __( 'Dismissed admin welcome card', 'wb-gamification' ),
				'wb_gam_dismissed_checklist'     => __( 'Dismissed admin setup checklist', 'wb-gamification' ),
				'wb_gam_setup_seen'              => __( 'Seen the setup wizard', 'wb-gamification' ),
				'wb_gam_level_id'                => __( 'Current level ID (cached)', 'wb-gamification' ),
				'wb_gam_level_name'              => __( 'Current level name (cached)', 'wb-gamification' ),
				'wb_gam_league_tier'             => __( 'Cohort league tier', 'wb-gamification' ),
				// Erased since 1.4.1, never exported until now. The member's own personal record is
				// their data; "we delete it but will not show it to you" is the wrong way round.
				'wb_gam_pr_best_week'            => __( 'Personal record — best week', 'wb-gamification' ),
				'wb_gam_sandboxed'               => __( 'Excluded from earning (sandboxed)', 'wb-gamification' ),
				// Written as `self::SOME_CONST` at their call sites, which is why Rule 11's grep never
				// saw them and this list went on looking complete. The award note is the one that
				// matters most: it is a staff member's written remark ABOUT this person, held on their
				// account, and it is exactly the kind of thing a subject-access request exists to
				// surface.
				'_wb_gam_last_award_note'        => __( 'Staff note on the most recent manual award', 'wb-gamification' ),
				'wb_gam_decayed_at'              => __( 'Points last decayed at', 'wb-gamification' ),
				'wb_gam_last_retention_nudge'    => __( 'Re-engagement nudge last sent', 'wb-gamification' ),
				'wb_gam_dismissed_wizard_notice' => __( 'Dismissed the setup-wizard notice', 'wb-gamification' ),
				'wb_gam_federate_events'         => __( 'Federate achievements to the fediverse (opt-in)', 'wb-gamification' ),
			);
			// Prefix families (`wb_gam_notif_cursor_<channel>`) are not knowable as literals — the
			// channel is part of the key. Ask MemberData which ones this member actually has, so the
			// export shows the same set the erase removes. Two different answers to "which keys are
			// yours" is how a member gets told about less data than we delete.
			foreach ( MemberData::user_meta_keys( $user_id ) as $found_key ) {
				if ( ! isset( $meta_groups[ $found_key ] ) && 0 === strpos( $found_key, 'wb_gam_notif_cursor_' ) ) {
					$meta_groups[ $found_key ] = sprintf(
						/* translators: %s: notification delivery channel, e.g. "footer". */
						__( 'Notification read cursor (%s)', 'wb-gamification' ),
						substr( $found_key, strlen( 'wb_gam_notif_cursor_' ) )
					);
				}
			}

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

		// CATCH-ALL: anything the curated groups above do not cover.
		//
		// The groups above are hand-written, and they are worth keeping -- an export a human cannot
		// read is a poor answer to "what do you hold on me?". But hand-written is also how they came
		// to omit the member's KUDOS, their cohort history, their redemptions, their challenge log,
		// their queued notifications and their intelligence profile. Seven tables in, ten tables out.
		//
		// So the curated groups stay, and everything they missed is appended here, straight from the
		// schema. A table added next month is in the export the day it is created, whether or not
		// anyone remembers this file exists.
		$covered = array( 'wb_gam_points', 'wb_gam_events', 'wb_gam_streaks', 'wb_gam_user_badges', 'wb_gam_submissions', 'wb_gam_member_prefs' );

		// Pass $covered IN, rather than filtering the result. The list is the same either way; the
		// difference is whether we read 50,000 ledger rows into memory before discarding them.
		//
		// And PAGE it, on the same $offset as the ledger. Two things were wrong here at once.
		//
		// It ran on EVERY page, outside the `1 === $page` guard that the curated groups sit inside. A
		// member with a 40-page export got their kudos, redemptions and queued notifications repeated
		// on all 40 pages -- measured: 850 queue rows exported 4250 times, 3404 colliding item_ids --
		// and paid the full memory cost of reading them 40 times.
		//
		// And the rows themselves were unbounded. Skipping the ledger fixed the two tables I had
		// actually looked at and left every other member table reading with no LIMIT, which is the same
		// defect wearing a shorter list. wb_gam_notifications_queue grows with every notification a
		// member is ever sent; "what is left after the skip is small" was an assumption I wrote down and
		// never measured.
		//
		// Each table now yields the same slice of rows the ledger does, so memory is bounded by the
		// page size, and the export is still COMPLETE: `$done` stays false while any table has more.
		$catch_all = MemberData::export_rows( $user_id, $covered, self::EXPORT_PAGE_SIZE, $offset );

		foreach ( $catch_all as $table => $rows ) {
			if ( ! $rows ) {
				continue;
			}

			// A full slice means there may be another one behind it. The ledger's own done-check
			// cannot see these tables, so a member whose queue is longer than their ledger would
			// otherwise have the tail of it silently cut off -- an export that looks complete and is
			// not, which is the worst thing this function can produce.
			if ( count( $rows ) >= self::EXPORT_PAGE_SIZE ) {
				$done = false;
			}

			foreach ( $rows as $i => $row ) {
				$items = array();

				foreach ( $row as $column => $value ) {
					$items[] = array(
						'name'  => (string) $column,
						'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
					);
				}

				$data_groups[] = array(
					'group_id'    => 'wb-gam-' . str_replace( '_', '-', $table ),
					'group_label' => sprintf(
						/* translators: %s: database table holding the member's data. */
						__( 'Gamification — %s', 'wb-gamification' ),
						str_replace( 'wb_gam_', '', $table )
					),
					// ABSOLUTE row index, not the per-page one. item_id must be unique across the whole
					// export: with a per-page index, page 2's first kudos row carries the same id as
					// page 1's and overwrites it in the archive. The ledger above already learned this.
					'item_id'     => $table . '-' . ( $offset + $i ),
					'data'        => $items,
				);
			}
		}

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

		// ONE purge path, shared with `deleted_user`.
		//
		// This used to hand-list the tables it deleted from, and that list was written once and then
		// drifted: notifications_queue (23k rows on the dev site), cohort_members (11k), user_intelligence,
		// redemptions, community-challenge contributions and api_keys were all added AFTER it and were
		// never added TO it. So a member who exercised their right to erasure was not, in fact, erased.
		//
		// The list was the bug. MemberData asks the schema which tables reference a member, so a table
		// added tomorrow is covered on the day it is created rather than the day someone remembers.
		$purged  = MemberData::purge( $user_id );
		$removed = array_sum( $purged );

		// Bust the per-type object-cache key matching what get_total reads. Without this, get_total
		// returns the cached pre-erase balance for up to the cache TTL after the user's data is gone.
		foreach ( $pt_slugs as $slug ) {
			wp_cache_delete( 'wb_gam_total_' . $user_id . '_' . $slug, 'wb_gamification' );
		}

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
