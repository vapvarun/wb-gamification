<?php
/**
 * WB Gamification — Jetonomy integration.
 *
 * Consumes the four Tier-1 hooks Jetonomy shipped in 1.4.3 for first-class
 * WB Gam support:
 *   - `jetonomy_reputation_points_map`  (filter)  — re-tune Jetonomy scoring per community.
 *   - `jetonomy_reputation_pre_change`  (filter)  — campaign multipliers + sandbox veto.
 *   - `jetonomy_leaderboard_items`      (filter)  — enrich each row with WB Gam currency / level / badges.
 *   - `jetonomy_reputation_changed`     (action)  — mirror reputation deltas into the WB Gam ledger.
 *
 * Requires Jetonomy 1.4.3+. No-op when Jetonomy is not active.
 *
 * @package WB_Gamification
 * @since   1.0.1
 */

namespace WBGam\Integrations\Jetonomy;

use WBGam\Engine\BadgeEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\PointsEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges Jetonomy reputation + leaderboard into WB Gamification.
 *
 * @package WB_Gamification
 */
final class JetonomyIntegration {

	private const OPT_POINTS_OVERRIDES = 'wb_gam_jetonomy_points_overrides';
	private const OPT_CAMPAIGN         = 'wb_gam_jetonomy_campaign';
	private const META_SANDBOXED       = 'wb_gam_sandboxed';
	private const ACTION_PREFIX        = 'jetonomy_';

	/**
	 * Wire integration hooks. No-op unless Jetonomy 1.4.3+ is active.
	 */
	public static function init(): void {
		if ( ! class_exists( '\\Jetonomy\\Trust\\Reputation' ) ) {
			return;
		}

		add_filter( 'jetonomy_reputation_points_map', array( __CLASS__, 'filter_points_map' ), 20, 1 );
		add_filter( 'jetonomy_reputation_pre_change', array( __CLASS__, 'filter_pre_change' ), 20, 4 );
		add_action( 'jetonomy_reputation_changed', array( __CLASS__, 'on_reputation_changed' ), 20, 4 );
		add_filter( 'jetonomy_leaderboard_items', array( __CLASS__, 'filter_leaderboard_items' ), 20, 2 );
	}

	/**
	 * Merge WB Gam-managed per-action point overrides into Jetonomy's POINTS_MAP.
	 *
	 * Surfaces in option `wb_gam_jetonomy_points_overrides`: array<string,int>
	 * where the key is the Jetonomy action (`post_upvoted`, `reply_accepted`,
	 * etc. — or a brand-new key such as `quest_completed` that an adapter
	 * fires through `Reputation::award_custom()`).
	 *
	 * @param array<string,int> $map Current points map.
	 * @return array<string,int>
	 */
	public static function filter_points_map( $map ): array {
		$map       = is_array( $map ) ? $map : array();
		$overrides = get_option( self::OPT_POINTS_OVERRIDES, array() );

		if ( ! is_array( $overrides ) || empty( $overrides ) ) {
			return $map;
		}

		foreach ( $overrides as $action => $value ) {
			if ( ! is_string( $action ) || '' === $action ) {
				continue;
			}
			$map[ $action ] = (int) $value;
		}

		return $map;
	}

	/**
	 * Apply WB Gam campaign multipliers and sandbox veto before Jetonomy writes a reputation delta.
	 *
	 * Reads option `wb_gam_jetonomy_campaign`:
	 *   array{
	 *     multiplier?: float,    // 1.0 = no change; 2.0 = double points; 0 = veto everyone.
	 *     starts_at?:  string,   // ISO-8601 / strtotime-parseable. Omit for "always on".
	 *     ends_at?:    string,
	 *     actions?:    string[], // Whitelist; empty / missing = all actions.
	 *   }
	 *
	 * Sandbox veto: users with truthy `wb_gam_sandboxed` user meta get $delta = 0
	 * (no rep write, no `jetonomy_reputation_changed` action). Used to neutralise
	 * trial / abusive / shadow-banned accounts without removing them from
	 * Jetonomy's permission system.
	 *
	 * @param int    $delta   Signed delta about to apply.
	 * @param int    $user_id User receiving the delta.
	 * @param string $action  Action key (e.g. `post_upvoted`).
	 * @param array  $context Caller-supplied context.
	 * @return int Final delta. Returning 0 short-circuits the write.
	 */
	public static function filter_pre_change( $delta, $user_id, $action, $context ): int {
		$delta   = (int) $delta;
		$user_id = (int) $user_id;
		unset( $context ); // Reserved for future per-context rules.

		if ( 0 === $delta || $user_id <= 0 ) {
			return $delta;
		}

		// Sandboxed users get zero rep impact in either direction.
		if ( get_user_meta( $user_id, self::META_SANDBOXED, true ) ) {
			/** This filter is documented in src/Engine/PointsEngine.php — see wb_gam_award_skipped. */
			do_action(
				'wb_gam_award_skipped',
				$user_id,
				(string) $action,
				'sandboxed',
				array(
					'delta'    => $delta,
					'adapter'  => 'jetonomy',
				)
			);
			return 0;
		}

		$campaign = get_option( self::OPT_CAMPAIGN, array() );
		if ( ! is_array( $campaign ) || empty( $campaign ) ) {
			return $delta;
		}

		if ( ! self::campaign_is_active( $campaign ) ) {
			return $delta;
		}

		if ( ! self::campaign_covers_action( $campaign, (string) $action ) ) {
			return $delta;
		}

		$multiplier = isset( $campaign['multiplier'] ) ? (float) $campaign['multiplier'] : 1.0;
		if ( 1.0 === $multiplier ) {
			return $delta;
		}

		// Round away from zero so a 1.5x of a +1 / -1 delta still moves the needle.
		$scaled = (int) ( $delta < 0 ? floor( $delta * $multiplier ) : ceil( $delta * $multiplier ) );

		return $scaled;
	}

	/**
	 * Mirror Jetonomy reputation deltas into the WB Gam points ledger.
	 *
	 * Positive deltas award; negative deltas debit (only if the user has the
	 * balance — never tip a fresh ledger into the negatives). Action key in
	 * the WB Gam ledger is namespaced (`jetonomy_post_upvoted`,
	 * `jetonomy_reply_accepted_revoked`, …) so forum-sourced points stay
	 * distinct from native WB Gam awards in reports.
	 *
	 * @param int    $user_id User whose reputation changed.
	 * @param string $action  Action key (suffixed `_revoked` on undo).
	 * @param int    $delta   Signed delta that was applied.
	 * @param array  $context Optional payload from `award_custom()`.
	 */
	public static function on_reputation_changed( $user_id, $action, $delta, $context ): void {
		$user_id = (int) $user_id;
		$delta   = (int) $delta;
		unset( $context );

		if ( $user_id <= 0 || 0 === $delta ) {
			return;
		}

		$action_id = self::ACTION_PREFIX . preg_replace( '/[^a-z0-9_]/i', '', (string) $action );
		if ( self::ACTION_PREFIX === $action_id ) {
			return; // Malformed action key — refuse to ledger junk rows.
		}

		if ( $delta > 0 ) {
			PointsEngine::award( $user_id, $action_id, $delta );
			return;
		}

		$amount = abs( $delta );
		if ( PointsEngine::get_total( $user_id ) < $amount ) {
			return;
		}

		PointsEngine::debit( $user_id, $amount, $action_id );
	}

	/**
	 * Enrich each Jetonomy leaderboard row with WB Gam totals, level, and badge count.
	 *
	 * Filter contract: order is final at this point — only add fields, never
	 * re-sort. Keep payload small; this list ships in the REST body.
	 *
	 * Added keys per row:
	 *   - `wb_gam_points`        (int)            — primary currency total.
	 *   - `wb_gam_level_id`      (int|null)       — current level id, or null if no levels seeded.
	 *   - `wb_gam_level_name`    (string|null)    — current level name, or null.
	 *   - `wb_gam_badges_count`  (int)            — number of currently-valid badges.
	 *
	 * @param array<int,array<string,mixed>> $items   Leaderboard rows.
	 * @param mixed                          $request REST request (unused).
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_leaderboard_items( $items, $request ): array {
		unset( $request );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return is_array( $items ) ? $items : array();
		}

		foreach ( $items as $index => $row ) {
			if ( ! is_array( $row ) || empty( $row['user_id'] ) ) {
				continue;
			}

			$user_id = (int) $row['user_id'];
			$level   = LevelEngine::get_level_for_user( $user_id );

			$items[ $index ]['wb_gam_points']       = (int) PointsEngine::get_total( $user_id );
			$items[ $index ]['wb_gam_level_id']     = is_array( $level ) ? (int) $level['id'] : null;
			$items[ $index ]['wb_gam_level_name']   = is_array( $level ) ? (string) $level['name'] : null;
			$items[ $index ]['wb_gam_badges_count'] = count( BadgeEngine::get_user_badges( $user_id ) );
		}

		return $items;
	}

	/**
	 * Is a campaign window currently open?
	 *
	 * @param array<string,mixed> $campaign Campaign config.
	 */
	private static function campaign_is_active( array $campaign ): bool {
		$now = time();

		if ( ! empty( $campaign['starts_at'] ) ) {
			$start = strtotime( (string) $campaign['starts_at'] );
			if ( false !== $start && $now < $start ) {
				return false;
			}
		}

		if ( ! empty( $campaign['ends_at'] ) ) {
			$end = strtotime( (string) $campaign['ends_at'] );
			if ( false !== $end && $now > $end ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Does the campaign apply to this action key?
	 *
	 * Missing or empty `actions` array means "every action".
	 *
	 * @param array<string,mixed> $campaign Campaign config.
	 * @param string              $action   Action key.
	 */
	private static function campaign_covers_action( array $campaign, string $action ): bool {
		if ( empty( $campaign['actions'] ) || ! is_array( $campaign['actions'] ) ) {
			return true;
		}

		return in_array( $action, array_map( 'strval', $campaign['actions'] ), true );
	}
}
