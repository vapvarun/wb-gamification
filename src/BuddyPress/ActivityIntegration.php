<?php
/**
 * WB Gamification — BuddyPress activity integration orchestrator.
 *
 * BP activity-type registration + delegation. Each event type lives in a
 * dedicated poster under `WBGam\BuddyPress\Stream\`:
 *
 *      - BadgeStream     ← wb_gam_badge_awarded
 *      - LevelStream     ← wb_gam_level_changed
 *      - KudosStream     ← wb_gam_kudos_given
 *      - ChallengeStream ← wb_gam_challenge_completed
 *
 *    Stream toggles (1 = enabled, 0 = disabled, default 1):
 *      wb_gam_bp_stream_badge_earned
 *      wb_gam_bp_stream_level_changed
 *      wb_gam_bp_stream_kudos_given
 *      wb_gam_bp_stream_challenge_completed
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\BuddyPress;

use WBGam\BuddyPress\Stream\ActivityCard;
use WBGam\BuddyPress\Stream\Backfiller;
use WBGam\BuddyPress\Stream\BadgeStream;
use WBGam\BuddyPress\Stream\ChallengeStream;
use WBGam\BuddyPress\Stream\KudosStream;
use WBGam\BuddyPress\Stream\LevelStream;

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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * BuddyPress activity-stream orchestrator.
 *
 * @package WB_Gamification
 */
final class ActivityIntegration {

	/**
	 * Register hooks when BuddyPress is active.
	 */
	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		// Register custom BP activity types so the activity directory filters list them.
		add_action( 'bp_register_activity_actions', array( __CLASS__, 'register_activity_types' ) );

		// The card survives BuddyPress's kses for our component only, and its stylesheet loads
		// on community pages; the Backfiller repairs older rows in the background.
		ActivityCard::init();
		Backfiller::init();

		// Delegate each event type to its dedicated stream poster.
		BadgeStream::init();
		LevelStream::init();
		KudosStream::init();
		ChallengeStream::init();
	}

	// ── Activity type registration ──────────────────────────────────────────────

	/**
	 * Register custom activity types with BuddyPress so they show up in directory filters.
	 */
	public static function register_activity_types(): void {
		$types = array(
			'badge_earned'        => __( 'Earned a badge', 'wb-gamification' ),
			'level_changed'       => __( 'Reached a new level', 'wb-gamification' ),
			'kudos_given'         => __( 'Gave kudos', 'wb-gamification' ),
			'challenge_completed' => __( 'Completed a challenge', 'wb-gamification' ),
		);

		foreach ( $types as $key => $label ) {
			/**
			 * Filter the context-group label for a gamification activity type.
			 *
			 * BP groups activity-filter dropdowns by this label. Returning the
			 * same string for every type collapses all gamification activities
			 * into a single "Gamification" filter row; per-type labels expose
			 * each as its own filter option.
			 *
			 * @param string $context Default per-type label.
			 * @param string $key     Activity action key (e.g. badge_earned).
			 */
			$context = apply_filters( 'wb_gam_activity_context_label', $label, $key );

			bp_activity_set_action(
				ActivityCard::COMPONENT,
				$key,
				$label,
				null,
				$context,
				array( 'activity', 'member' )
			);
		}
	}
}
