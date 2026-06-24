<?php
/**
 * WB Gamification — Public Extension API
 *
 * These functions are the developer-facing API for registering custom
 * gamification triggers, badge conditions, and challenge types.
 *
 * Usage:
 *   wb_gam_register_action( [ 'id' => '...', 'hook' => '...', ... ] );
 *
 * @package WB_Gamification
 */

use WBGam\Engine\Registry;
use WBGam\Engine\Engine;
use WBGam\Engine\Event;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\ChallengeEngine;

// Direct-web-access guard. At runtime this file is required directly by the
// main plugin bootstrap; under CLI tooling (PHPStan, PHPUnit, phpcs) it is
// pulled in via Composer's `files` autoload, which bootstraps WITHOUT
// defining ABSPATH. A bare `defined( 'ABSPATH' ) || exit;` therefore
// silently terminated every CLI run, turning the static-analysis and
// test gates into no-ops. Allow CLI (incl. WP-CLI) so the gates run;
// still block direct web access where SAPI is web and ABSPATH is unset.
defined( 'ABSPATH' ) || 'cli' === PHP_SAPI || defined( 'WP_CLI' ) || exit;
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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

/**
 * Register a custom action that awards points.
 *
 * @param array $args {
 *   Action definition array.
 *
 *   @type string   $id             Unique action identifier.
 *   @type string   $label          Human-readable label.
 *   @type string   $description    Optional description.
 *   @type string   $hook           WordPress hook name to listen on.
 *   @type callable $user_callback  Callback that returns the user ID from hook args.
 *   @type int      $default_points Default points awarded.
 *   @type string   $category       Optional category slug.
 *   @type string   $icon           Optional dashicon class.
 *   @type bool     $repeatable     Whether the action can be awarded multiple times.
 *   @type int      $cooldown       Seconds between repeated awards. 0 = no cooldown.
 *   @type int      $daily_cap      Max awards per day. 0 = unlimited.
 *   @type int      $weekly_cap     Max awards per week. 0 = unlimited.
 * }
 *
 * @since 1.0.0
 */
function wb_gam_register_action( array $args ): void {
	Registry::register_action( $args );
}

/**
 * Register a custom badge trigger.
 *
 * @param array $args {
 *   Badge trigger definition array.
 *
 *   @type string   $id          Unique trigger identifier.
 *   @type string   $label       Human-readable label.
 *   @type string   $description Optional description.
 *   @type string   $hook        WordPress hook name to listen on.
 *   @type callable $condition   Callback returning true when the badge should be awarded.
 * }
 *
 * @since 1.0.0
 */
function wb_gam_register_badge_trigger( array $args ): void {
	Registry::register_badge_trigger( $args );
}

/**
 * Register a custom challenge type.
 *
 * @param array $args {
 *   Challenge type definition array.
 *
 *   @type string $id          Unique challenge type identifier.
 *   @type string $label       Human-readable label.
 *   @type string $description Optional description.
 *   @type string $action_id   Action ID that this challenge tracks.
 *   @type bool   $countable   Whether progress is tracked by count.
 * }
 *
 * @since 1.0.0
 */
function wb_gam_register_challenge_type( array $args ): void {
	Registry::register_challenge_type( $args );
}

/**
 * Get total points for a user.
 *
 * @since 1.0.0
 *
 * @param int $user_id WordPress user ID.
 * @return int Total accumulated points.
 */
function wb_gam_get_user_points( int $user_id ): int {
	return PointsEngine::get_total( $user_id );
}

/**
 * Get how many times a user has performed a specific action.
 *
 * @since 1.0.0
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $action_id Action identifier to query.
 * @return int Number of times the action has been awarded to the user.
 */
function wb_gam_get_user_action_count( int $user_id, string $action_id ): int {
	return PointsEngine::get_action_count( $user_id, $action_id );
}

/**
 * Get current level for a user.
 *
 * @since 1.0.0
 *
 * @param int $user_id WordPress user ID.
 * @return array{id: int, name: string, min_points: int}|null Level data or null if no level matched.
 */
function wb_gam_get_user_level( int $user_id ): ?array {
	return LevelEngine::get_level_for_user( $user_id );
}

/**
 * Award points to a user manually.
 *
 * Bypasses cooldown/cap checks. Routes through Engine::process() so the event
 * is persisted to wb_gam_events and all hooks/webhooks fire normally.
 *
 * @since 1.0.0
 *
 * @param int    $user_id   WordPress user ID to award points to.
 * @param int    $points    Number of points to award. Must be greater than zero.
 * @param string $action_id Action identifier to log against. Defaults to 'manual'.
 * @param int    $object_id Optional related object ID (e.g. post ID). Defaults to 0.
 * @return bool True on success, false if validation fails.
 */
function wb_gam_award_points( int $user_id, int $points, string $action_id = 'manual', int $object_id = 0 ): bool {
	if ( $points <= 0 || $user_id <= 0 ) {
		return false;
	}

	return Engine::process(
		new Event(
			array(
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => $object_id,
				'metadata'  => array(
					'points' => $points,
					'manual' => true,
				),
			)
		)
	);
}

/**
 * Whether a user can afford a points cost.
 *
 * A cheap pre-check before {@see wb_gam_spend_points()} so callers can show a
 * "not enough points" state without attempting (and rolling back) a debit.
 *
 * @since 1.6.1
 *
 * @param int         $user_id WordPress user ID.
 * @param int         $amount  Points cost (positive integer).
 * @param string|null $type    Optional point-type slug. Null = primary type.
 * @return bool True when the user's balance covers the cost.
 */
function wb_gam_can_afford( int $user_id, int $amount, ?string $type = null ): bool {
	if ( $user_id <= 0 || $amount <= 0 ) {
		return false;
	}

	return PointsEngine::get_total( $user_id, $type ) >= $amount;
}

/**
 * Spend (debit) points from a user's balance for an external redemption — e.g.
 * redeeming a BuddyNext membership tier or a marketplace purchase.
 *
 * A stable public seam over the audited, atomic {@see PointsEngine::debit()} so
 * consumers (BuddyNext, etc.) depend on this signature rather than the engine
 * internals. Every spend writes a matching wb_gam_events + wb_gam_points row
 * inside one locked transaction (SELECT … FOR UPDATE), so concurrent spends can
 * never overdraw the balance. Mirrors the debit composition that
 * RedemptionEngine::redeem() uses for store items.
 *
 * On success, fires `wb_gam_points_spent` so other features can react.
 *
 * @since 1.6.1
 *
 * @param int                 $user_id WordPress user ID.
 * @param int                 $amount  Points to spend (positive integer).
 * @param string              $context Short action label for the audit log
 *                                     (e.g. 'bn_membership'). Defaults to 'redemption'.
 * @param array<string,mixed> $meta    Optional metadata stored on the event
 *                                     (e.g. ['item_id' => 12, 'item_label' => 'Gold']).
 * @param string|null         $type    Optional point-type slug. Null = primary type.
 * @return array{success: bool, reason?: string, event_id?: string, new_balance?: int}
 *               On failure, `reason` is one of: 'invalid_args' | 'insufficient_balance'
 *               | 'event_persist_failed' | 'ledger_write_failed'.
 */
function wb_gam_spend_points( int $user_id, int $amount, string $context = 'redemption', array $meta = array(), ?string $type = null ): array {
	if ( $user_id <= 0 || $amount <= 0 ) {
		return array(
			'success' => false,
			'reason'  => 'invalid_args',
		);
	}

	$context       = '' !== $context ? sanitize_key( $context ) : 'redemption';
	$resolved_type = PointsEngine::resolve_type( $type );

	// Build the canonical spend event so debit() audit-logs it (every
	// wb_gam_points row gets a matching wb_gam_events row), mirroring
	// RedemptionEngine::redeem().
	$event = new Event(
		array(
			'action_id' => $context,
			'user_id'   => $user_id,
			'metadata'  => array_merge(
				$meta,
				array(
					'points_cost' => -abs( $amount ),
					'point_type'  => $resolved_type,
				)
			),
		)
	);

	$result = PointsEngine::debit( $user_id, $amount, $context, $event, $resolved_type );

	if ( ! empty( $result['success'] ) ) {
		/**
		 * Fires after points are successfully spent via wb_gam_spend_points().
		 *
		 * @since 1.6.1
		 *
		 * @param int                 $user_id WordPress user ID.
		 * @param int                 $amount  Points spent (positive integer).
		 * @param string              $context Action label passed by the caller.
		 * @param array<string,mixed> $result  Debit result (event_id, new_balance).
		 */
		do_action( 'wb_gam_points_spent', $user_id, $amount, $context, $result );
	}

	return $result;
}

/**
 * Check if a user has earned a specific badge.
 *
 * @since 1.0.0
 *
 * @param int    $user_id  WordPress user ID.
 * @param string $badge_id Badge identifier.
 * @return bool True if the user currently holds the badge.
 */
function wb_gam_has_badge( int $user_id, string $badge_id ): bool {
	return \WBGam\Engine\BadgeEngine::has_badge( $user_id, $badge_id );
}

/**
 * Get all badges earned by a user.
 *
 * @since 1.0.0
 *
 * @param int $user_id WordPress user ID.
 * @return array List of earned badge data.
 */
function wb_gam_get_user_badges( int $user_id ): array {
	return \WBGam\Engine\BadgeEngine::get_user_badges( $user_id );
}

/**
 * Get a user's current streak data.
 *
 * @since 1.0.0
 *
 * @param int $user_id WordPress user ID.
 * @return array{current_streak: int, longest_streak: int, last_active: string}
 */
function wb_gam_get_user_streak( int $user_id ): array {
	return \WBGam\Engine\StreakEngine::get_streak( $user_id );
}

/**
 * Get the leaderboard for a given period.
 *
 * @since 1.0.0
 *
 * @param string $period 'all'|'week'|'month'|'day'.
 * @param int    $limit  Number of entries to return.
 * @return array List of leaderboard entries.
 */
function wb_gam_get_leaderboard( string $period = 'all', int $limit = 10 ): array {
	return \WBGam\Engine\LeaderboardEngine::get_leaderboard( $period, $limit );
}

/**
 * Check if a feature flag is enabled.
 *
 * @since 1.0.0
 *
 * @param string $feature Feature flag key (e.g. 'cohort_leagues').
 * @return bool True if the feature is enabled.
 */
function wb_gam_is_feature_enabled( string $feature ): bool {
	return \WBGam\Engine\FeatureFlags::is_enabled( $feature );
}

/**
 * Get active challenges for a user.
 *
 * Returns all challenges that are currently active and available
 * for the given user, including progress data.
 *
 * @since 1.0.0
 *
 * @param int $user_id WordPress user ID.
 * @return array List of active challenge data for the user.
 */
function wb_gam_get_user_challenges( int $user_id ): array {
	return ChallengeEngine::get_active_challenges( $user_id );
}

/**
 * Submit a gamification event for processing.
 *
 * Creates an Event and routes it through Engine::process() so the full
 * pipeline runs (points, badges, streaks, webhooks, etc.).
 *
 * @since 1.0.0
 *
 * @param int    $user_id   WordPress user ID who triggered the event.
 * @param string $action_id Registered action identifier to fire.
 * @param array  $meta      Optional metadata to attach to the event.
 * @return bool True on success, false if validation fails.
 */
function wb_gam_submit_event( int $user_id, string $action_id, array $meta = array() ): bool {
	if ( $user_id <= 0 || '' === $action_id ) {
		return false;
	}

	return Engine::process(
		new Event(
			array(
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => (int) ( $meta['object_id'] ?? 0 ),
				'metadata'  => $meta,
			)
		)
	);
}

/**
 * Get all registered gamification actions.
 *
 * Returns the full list of actions that have been registered
 * via manifests or the wb_gam_register_action() API.
 *
 * @since 1.0.0
 *
 * @return array Associative array of action definitions keyed by action ID.
 */
function wb_gam_get_actions(): array {
	return Registry::get_actions();
}
