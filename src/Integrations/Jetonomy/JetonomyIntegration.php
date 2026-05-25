<?php
/**
 * WB Gamification — Jetonomy integration.
 *
 * Mirrors Jetonomy reputation deltas into the WB Gam ledger via the
 * `jetonomy_reputation_changed` action. Sandboxed users (truthy
 * `wb_gam_sandboxed` user meta) are skipped at the wb-gam mirror —
 * Jetonomy's own reputation row is unaffected.
 *
 * History: 1.0.1 also registered listeners for three filters
 * (`jetonomy_reputation_points_map`, `jetonomy_reputation_pre_change`,
 * `jetonomy_leaderboard_items`). Audit of Jetonomy 1.4.4 confirmed none
 * of those filters fire upstream, so the listeners were dead wiring.
 * Removed in 1.4.0 (Basecamp card pending on the Jetonomy board to
 * land those filter emissions; once they exist, mirror logic can move
 * back here).
 *
 * Requires Jetonomy 1.4.3+. No-op when Jetonomy is not active.
 *
 * @package WB_Gamification
 * @since   1.0.1
 */

namespace WBGam\Integrations\Jetonomy;

use WBGam\Engine\PointsEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges Jetonomy reputation into the WB Gamification ledger.
 *
 * @package WB_Gamification
 */
final class JetonomyIntegration {

	private const META_SANDBOXED = 'wb_gam_sandboxed';
	private const ACTION_PREFIX  = 'jetonomy_';

	/**
	 * Wire integration hooks. No-op unless Jetonomy 1.4.3+ is active.
	 */
	public static function init(): void {
		if ( ! class_exists( '\\Jetonomy\\Trust\\Reputation' ) ) {
			return;
		}

		add_action( 'jetonomy_reputation_changed', array( __CLASS__, 'on_reputation_changed' ), 20, 4 );
	}

	/**
	 * Mirror Jetonomy reputation deltas into the WB Gam points ledger.
	 *
	 * Positive deltas award; negative deltas debit (only if the user has
	 * the balance — never tip a fresh ledger into the negatives). Action
	 * key in the WB Gam ledger is namespaced (`jetonomy_post_upvoted`,
	 * `jetonomy_reply_accepted_revoked`, …) so forum-sourced points stay
	 * distinct from native WB Gam awards in reports.
	 *
	 * Sandboxed users (truthy `wb_gam_sandboxed` user meta) are skipped at
	 * the wb-gam mirror — used to neutralise trial / abusive / shadow-
	 * banned accounts without removing them from Jetonomy's permission
	 * system. The `wb_gam_award_skipped` action fires so adapters can
	 * surface "no points this time" if needed.
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

		// Sandboxed users get no wb-gam mirror in either direction.
		// Jetonomy's own reputation row is unaffected — only the gamification
		// ledger ignores them.
		if ( get_user_meta( $user_id, self::META_SANDBOXED, true ) ) {
			/** This filter is documented in src/Engine/PointsEngine.php — see wb_gam_award_skipped. */
			do_action(
				'wb_gam_award_skipped',
				$user_id,
				$action_id,
				'sandboxed',
				array(
					'delta'   => $delta,
					'adapter' => 'jetonomy',
				)
			);
			return;
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
}
