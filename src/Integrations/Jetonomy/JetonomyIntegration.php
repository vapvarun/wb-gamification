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
		add_filter( 'wb_gam_action_label', array( __CLASS__, 'label' ), 10, 2 );
	}

	/**
	 * Name the mirrored reputation ids, which are awarded without being registered actions.
	 *
	 * Without this every forum award read "Points awarded" in its toast and a title-cased id in the
	 * history (card 10344406246). One label, shared by every surface through Registry::label_for().
	 *
	 * @param string $label     '' when nothing has named the id yet.
	 * @param string $action_id Action identifier.
	 * @return string
	 */
	public static function label( $label, $action_id ): string {
		$label     = (string) $label;
		$action_id = (string) $action_id;
		if ( '' !== $label || ! str_starts_with( $action_id, self::ACTION_PREFIX ) ) {
			return $label;
		}

		$reason   = substr( $action_id, strlen( self::ACTION_PREFIX ) );
		$reversed = str_ends_with( $reason, '_revoked' );
		if ( $reversed ) {
			$reason = substr( $reason, 0, -strlen( '_revoked' ) );
		}

		$labels = array(
			'reply_created'     => __( 'Replied in the forum', 'wb-gamification' ),
			'post_created'      => __( 'Started a forum topic', 'wb-gamification' ),
			'post_upvoted'      => __( 'Your forum post was upvoted', 'wb-gamification' ),
			'reply_upvoted'     => __( 'Your forum post was upvoted', 'wb-gamification' ),
			'post_downvoted'    => __( 'Your forum post was downvoted', 'wb-gamification' ),
			'reply_downvoted'   => __( 'Your forum post was downvoted', 'wb-gamification' ),
			'reply_accepted'    => __( 'Your answer was accepted', 'wb-gamification' ),
			'post_reported'     => __( 'Forum post reported', 'wb-gamification' ),
			'badge_earned'      => __( 'Earned a forum badge', 'wb-gamification' ),
			'cli_manual_adjust' => __( 'Manual forum adjustment', 'wb-gamification' ),
		);
		if ( ! isset( $labels[ $reason ] ) ) {
			return $label;
		}

		return $reversed
			/* translators: %s: what happened in the forum, e.g. "Your forum post was upvoted". */
			? sprintf( __( '%s (reversed)', 'wb-gamification' ), $labels[ $reason ] )
			: $labels[ $reason ];
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

		// BuddyNext mirrors a feed comment into a Jetonomy reply (and back); the reputation that
		// copy earns is for an action the member was already paid for as a feed comment.
		if ( \WBGam\Engine\HostActivity::is_copy() ) {
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
