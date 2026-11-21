<?php
/**
 * WB Gamification — Jetonomy integration.
 *
 * Forum events that are registered actions (topic, reply, votes, acceptance, penalties; see
 * integrations/jetonomy.php) are paid and taken back by the engine like every other action, under
 * the owner's settings. This class mirrors the rest of Jetonomy's reputation deltas (badges, manual
 * adjustments) into the WB Gam ledger via `jetonomy_reputation_changed`, and stands aside for any
 * event a registered action owns. Sandboxed users (truthy `wb_gam_sandboxed` user meta) are skipped
 * at the wb-gam mirror: Jetonomy's own reputation row is unaffected.
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

	/** Background job that settles a removed topic's replies, one batch at a time. */
	public const REPLIES_HOOK = 'wb_gam_jetonomy_topic_replies';

	/** Replies settled per batch: one indexed query and one ledger transaction each. */
	private const REPLY_BATCH = 200;

	/**
	 * Wire integration hooks. No-op unless Jetonomy 1.4.3+ is active.
	 */
	public static function init(): void {
		if ( ! class_exists( '\\Jetonomy\\Trust\\Reputation' ) ) {
			return;
		}

		add_action( 'jetonomy_reputation_changed', array( __CLASS__, 'on_reputation_changed' ), 20, 4 );
		// A topic that goes takes its replies' points with it; one that returns gives them back.
		add_action( 'jetonomy_post_deleted', array( __CLASS__, 'on_topic_deleted' ), 10, 1 );
		add_action( 'jetonomy_content_moderated', array( __CLASS__, 'on_content_moderated' ), 10, 3 );
		add_action( self::REPLIES_HOOK, array( __CLASS__, 'settle_replies' ), 10, 3 );
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

		// Named here as well as on the registered actions: history stays readable on a site where
		// Jetonomy is switched off and its actions are not registered.
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
			// History rows from before 1.6.5, when the Pro badge hook paid a second award.
			'pro_badge_earned'  => __( 'Earned a forum badge', 'wb-gamification' ),
			'badge_revoked'     => __( 'Forum badge removed', 'wb-gamification' ),
			'cli_manual_adjust' => __( 'Manual forum adjustment', 'wb-gamification' ),
		);

		// "_revoked" marks the mirror's reversal of an award, unless Jetonomy named the reason
		// that way itself (badge_revoked is a reason of its own).
		$reason   = substr( $action_id, strlen( self::ACTION_PREFIX ) );
		$reversed = ! isset( $labels[ $reason ] ) && str_ends_with( $reason, '_revoked' );
		if ( $reversed ) {
			$reason = substr( $reason, 0, -strlen( '_revoked' ) );
		}
		if ( ! isset( $labels[ $reason ] ) ) {
			return $label;
		}

		return $reversed
			/* translators: %s: what happened in the forum, e.g. "Your forum post was upvoted". */
			? sprintf( __( '%s (reversed)', 'wb-gamification' ), $labels[ $reason ] )
			: $labels[ $reason ];
	}

	/**
	 * A topic was deleted: take back its replies' points.
	 *
	 * @param int $post_id Topic id.
	 */
	public static function on_topic_deleted( $post_id ): void {
		self::queue_replies( (int) $post_id, 'reverse' );
	}

	/**
	 * A moderator removed or approved content: when it is a topic, settle its replies the same way.
	 *
	 * @param string $action Moderation action: approve, spam, trash or hold.
	 * @param string $type   'post' or 'reply'.
	 * @param int    $id     Row id.
	 */
	public static function on_content_moderated( $action, $type, $id ): void {
		if ( 'post' !== (string) $type ) {
			return;
		}

		if ( in_array( (string) $action, array( 'spam', 'trash' ), true ) ) {
			self::queue_replies( (int) $id, 'reverse' );
		} elseif ( 'approve' === (string) $action ) {
			self::queue_replies( (int) $id, 'restore' );
		}
	}

	/**
	 * Settle a topic's replies in the background: a topic can hold hundreds, so nothing loops in the request.
	 *
	 * @param int    $post_id  Topic id.
	 * @param string $mode     'reverse' or 'restore'.
	 * @param int    $after_id Last reply id already settled (0 for the first batch).
	 */
	private static function queue_replies( int $post_id, string $mode, int $after_id = 0 ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::REPLIES_HOOK, array( $post_id, $mode, $after_id ), 'wb-gamification' );
			return;
		}

		self::settle_replies( $post_id, $mode, $after_id );
	}

	/**
	 * Take back (or give back) the points of the next batch of a topic's replies, then queue the next batch.
	 *
	 * A reply earns for being published and, when chosen, for being accepted; both leave and return
	 * with the topic. A restore skips replies that were removed on their own, which stay removed.
	 *
	 * @param int    $post_id  Topic id.
	 * @param string $mode     'reverse' or 'restore'.
	 * @param int    $after_id Last reply id of the previous batch (keyset page).
	 */
	public static function settle_replies( $post_id, $mode, $after_id = 0 ): void {
		global $wpdb;

		$post_id  = (int) $post_id;
		$after_id = (int) $after_id;
		$reverse  = 'reverse' === (string) $mode;
		if ( $post_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Jetonomy's reply table, a keyset page by id.
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}jt_replies WHERE post_id = %d AND id > %d" . ( $reverse ? '' : " AND status = 'publish'" ) . ' ORDER BY id ASC LIMIT %d',
					$post_id,
					$after_id,
					self::REPLY_BATCH
				)
			)
		);
		// phpcs:enable

		if ( ! $ids ) {
			return;
		}

		$keys = array_map( static fn( int $id ): int => \WBGam\Engine\Registry::object_key( 'jetonomy', 'reply', $id, 0 ), $ids );
		$targets = array(
			self::ACTION_PREFIX . 'reply_created'  => $keys,
			self::ACTION_PREFIX . 'reply_accepted' => $keys,
		);
		if ( $reverse ) {
			PointsEngine::reverse_object_awards( $targets );
		} else {
			PointsEngine::restore_object_awards( $targets );
		}

		if ( count( $ids ) === self::REPLY_BATCH ) {
			self::queue_replies( $post_id, (string) $mode, (int) end( $ids ) );
		}
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

		$reason = preg_replace( '/[^a-z0-9_]/i', '', (string) $action );
		if ( '' === $reason ) {
			return; // Malformed action key — refuse to ledger junk rows.
		}
		$action_id = self::ACTION_PREFIX . $reason;

		// A registered action owns this event, and its withdrawal ("{event}_revoked"): the engine pays
		// it under the owner's settings and takes it back through the action's own exit. Paying it
		// here as well would double it, and ignore the owner's points, caps and on/off.
		$event = str_ends_with( $reason, '_revoked' ) ? substr( $reason, 0, -strlen( '_revoked' ) ) : $reason;
		if ( null !== \WBGam\Engine\Registry::get_action( self::ACTION_PREFIX . $event ) ) {
			return;
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
