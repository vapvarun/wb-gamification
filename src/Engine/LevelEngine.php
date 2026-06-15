<?php
/**
 * WB Gamification Level Engine
 *
 * Level state is derived from the points ledger, not stored separately.
 * On each point award, Engine calls maybe_level_up() which compares the
 * user's current points against the wb_gam_levels thresholds. If the level
 * changed, user_meta is updated and the level_changed hook fires.
 *
 * Levels are admin-configurable via wb_gam_levels DB table.
 * Defaults seeded by Installer: Newcomer → Member → Contributor → Regular → Champion.
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
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Derives and updates member level state from the points ledger.
 *
 * @package WB_Gamification
 */
final class LevelEngine {

	/**
	 * Static cache of all level rows for the current request.
	 * Avoids repeated object-cache lookups within a single page load.
	 *
	 * @var array<int, array{id: int, name: string, min_points: int, sort_order: int, icon_url: string|null}>|null
	 */
	private static ?array $levels_cache = null;

	/**
	 * Load all level rows, using a static cache (per-request) backed by object cache (cross-request).
	 *
	 * Levels are admin-only data that almost never changes, so a 1-hour TTL is safe.
	 *
	 * Rows are ordered by `sort_order ASC` — the admin-defined level hierarchy —
	 * not by `min_points`. The two usually agree, but an admin can edit thresholds
	 * such that a numerically lower level ends up with a higher `min_points` than
	 * a level above it. `sort_order` is the single source of truth for "which level
	 * comes next"; `min_points` only decides "which level the user has reached".
	 * Sorting by `min_points` here was the cause of the nudge widget naming the
	 * wrong next level (Basecamp 9995220498). `min_points ASC, id ASC` are kept as
	 * deterministic tie-breakers so the order is stable when sort_order collides.
	 *
	 * @return array<int, array{id: int, name: string, min_points: int, sort_order: int, icon_url: string|null}>
	 */
	private static function get_all_levels(): array {
		if ( null !== self::$levels_cache ) {
			return self::$levels_cache;
		}

		// Cache key carries a structure version. v2 added the `sort_order` field
		// to every row; bumping the key guarantees a post-deploy site never
		// serves a stale v1 array (no sort_order) from a persistent object cache.
		$cached = wp_cache_get( 'wb_gam_levels_all_v2', 'wb_gamification' );
		if ( false !== $cached ) {
			self::$levels_cache = (array) $cached;
			return self::$levels_cache;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, name, min_points, sort_order, icon_url FROM {$wpdb->prefix}wb_gam_levels ORDER BY sort_order ASC, min_points ASC, id ASC",
			ARRAY_A
		) ?: array();

		self::$levels_cache = array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'name'       => $row['name'],
					'min_points' => (int) $row['min_points'],
					'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
					'icon_url'   => $row['icon_url'] ?: null,
				);
			},
			$rows
		);

		wp_cache_set( 'wb_gam_levels_all_v2', self::$levels_cache, 'wb_gamification', 3600 ); // 1 hr TTL.

		return self::$levels_cache;
	}

	/**
	 * Check whether a user's points put them in a new level, and promote if so.
	 *
	 * Called by Engine::process() after every successful point award.
	 *
	 * @param int $user_id User to check.
	 */
	public static function maybe_level_up( int $user_id ): void {
		$new_level = self::get_level_for_user( $user_id );
		if ( ! $new_level ) {
			return;
		}

		$current_level_id = (int) get_user_meta( $user_id, 'wb_gam_level_id', true );

		if ( $new_level['id'] === $current_level_id ) {
			return; // No change.
		}

		// Update user meta so profile/directory integrations can read it cheaply.
		update_user_meta( $user_id, 'wb_gam_level_id', $new_level['id'] );
		update_user_meta( $user_id, 'wb_gam_level_name', $new_level['name'] );

		// Resolve old level data once for either fire-path below.
		$old_level_data = null;
		if ( $current_level_id > 0 ) {
			foreach ( self::get_all_levels() as $lvl ) {
				if ( $lvl['id'] === $current_level_id ) {
					$old_level_data = $lvl;
					break;
				}
			}
		}

		if ( $current_level_id > 0 ) {
			/**
			 * Fires after a user's level changes.
			 *
			 * Pre-1.0.0 this hook also fired immediately above with an
			 * int-only signature `(user_id, old_level_id, new_level_id)`.
			 * That broke listeners typed for the array signature
			 * (WebhookDispatcher::on_level_changed, TransactionalEmailEngine,
			 * NotificationBridge) — every level-up triggered a
			 * `TypeError: Argument #2 must be of type ?array, int given`
			 * fatal that aborted the award request mid-pipeline. The
			 * legacy fire was removed in 1.0.0; all listeners now receive
			 * the typed array signature reliably.
			 *
			 * @since 1.0.0
			 *
			 * @param int        $user_id   User whose level changed.
			 * @param array|null $new_level New level data (id, name, min_points) or null.
			 * @param array|null $old_level Previous level data or null.
			 */
			do_action( 'wb_gam_level_changed', $user_id, $new_level, $old_level_data );
		} else {
			/**
			 * Fires when a member is assigned their very first level — usually
			 * Newcomer (or whatever level has min_points = 0). Pre-1.4.0
			 * `wb_gam_level_changed` deliberately skipped this case, which
			 * meant RankAutomation rules with a "trigger_level_id = Newcomer"
			 * never fired (Basecamp 9925298656 issue 3). Now a dedicated
			 * `wb_gam_level_assigned` hook always fires for the first
			 * assignment so listeners can act on it (rank automation,
			 * notification bridge, welcome email). Notification toasts and
			 * "you levelled up" overlays continue to listen only to the
			 * upgrade path via `wb_gam_level_changed` so a brand-new user
			 * isn't bombarded with congratulations for being a Newcomer.
			 *
			 * @since 1.4.0
			 *
			 * @param int   $user_id   User who was assigned a starter level.
			 * @param array $new_level Level data (id, name, min_points).
			 */
			do_action( 'wb_gam_level_assigned', $user_id, $new_level );
		}
	}

	/**
	 * Return the current level for a user based on their total points.
	 *
	 * @param int $user_id User to look up.
	 * @return array{ id: int, name: string, min_points: int, sort_order: int, icon_url: string|null }|null
	 *         Null only if no levels are configured (fresh install before seeding).
	 */
	public static function get_level_for_user( int $user_id ): ?array {
		$level = self::get_level_for_points( PointsEngine::get_total( $user_id ) );

		// Self-heal stale user_meta. Pre-1.4.0 the level_id / level_name cache
		// in user_meta only updated when PointsEngine::award fired through the
		// engine (which calls maybe_level_up at the end of the pipeline). Any
		// points written via a non-engine code path — manual DB insert, an
		// older Privacy export round-trip, the QA seed CLI, a migration from
		// a sister product — left the cache pointing at whatever level the
		// user was at the last time the engine ran. Reading the level became
		// stable (always derived from the points ledger) but the directory
		// rank line + leaderboard rank meta + REST `members` response keep
		// reading user_meta because that's what listeners typed against.
		// Detecting the mismatch here and patching the meta inline keeps the
		// engine the single source of truth without forcing a points-replay
		// migration; every getter call self-corrects on the way out.
		if ( $level && $user_id > 0 ) {
			$cached_id   = (int) get_user_meta( $user_id, 'wb_gam_level_id', true );
			$cached_name = (string) get_user_meta( $user_id, 'wb_gam_level_name', true );
			if ( $cached_id !== $level['id'] || $cached_name !== $level['name'] ) {
				update_user_meta( $user_id, 'wb_gam_level_id', $level['id'] );
				update_user_meta( $user_id, 'wb_gam_level_name', $level['name'] );
			}
		}

		return $level;
	}

	/**
	 * Return the level that corresponds to a given points total.
	 *
	 * @param int $points Points total.
	 * @return array{ id: int, name: string, min_points: int, sort_order: int, icon_url: string|null }|null
	 */
	public static function get_level_for_points( int $points ): ?array {
		$levels = self::get_all_levels();
		$match  = null;

		// Levels are now ordered by sort_order (not min_points), so we cannot
		// break early — the highest reachable threshold may sit anywhere in the
		// list. Walk every level and keep the one with the greatest min_points
		// the user has actually reached. `>=` keeps the later (higher sort_order)
		// level when two share a threshold.
		foreach ( $levels as $level ) {
			if ( $level['min_points'] <= $points
				&& ( null === $match || $level['min_points'] >= $match['min_points'] ) ) {
				$match = $level;
			}
		}

		return $match;
	}

	/**
	 * Return the next level above a user's current level, or null if max level.
	 *
	 * The next level is the one immediately above the user's CURRENT level in the
	 * admin-defined hierarchy (`sort_order`), not the first level whose `min_points`
	 * exceeds the user's total. Those two only diverge when an admin edits
	 * thresholds so they no longer line up with the level order, but when they do
	 * the threshold-based answer is wrong: it would name a numerically-higher level
	 * that actually sits below the user in the ladder (Basecamp 9995220498 — a
	 * Contributor was told they were "15 points from Member").
	 *
	 * @param int $user_id User to look up.
	 * @return array{ id: int, name: string, min_points: int, sort_order: int, icon_url: string|null }|null
	 */
	public static function get_next_level( int $user_id ): ?array {
		return self::get_next_level_for_points( PointsEngine::get_total( $user_id ) );
	}

	/**
	 * Return the next level above a given points total, or null if at the top.
	 *
	 * Pure counterpart to {@see get_next_level()} — takes a raw points total so
	 * it can be reasoned about (and tested) without resolving a user's ledger.
	 *
	 * @param int $points Points total.
	 * @return array{ id: int, name: string, min_points: int, sort_order: int, icon_url: string|null }|null
	 */
	public static function get_next_level_for_points( int $points ): ?array {
		$levels  = self::get_all_levels();
		$current = self::get_level_for_points( $points );

		// No level reached yet (no zero-threshold starter level): the next target
		// is the very first rung of the ladder. Otherwise it's the first rung
		// whose sort_order is strictly above the current level's. Rows are already
		// ordered by sort_order ASC, so the first qualifying row is the answer.
		$current_sort = ( null !== $current ) ? $current['sort_order'] : null;

		foreach ( $levels as $level ) {
			if ( null === $current_sort || $level['sort_order'] > $current_sort ) {
				return $level;
			}
		}

		return null; // Already at the top of the ladder.
	}

	/**
	 * Return all levels ordered by threshold, with the user's current one flagged.
	 *
	 * @param int $user_id User to look up.
	 * @return array<int, array{ id: int, name: string, min_points: int, is_current: bool }>
	 */
	public static function get_all_levels_for_user( int $user_id ): array {
		$levels = self::get_all_levels();

		if ( empty( $levels ) ) {
			return array();
		}

		$current = self::get_level_for_user( $user_id );

		return array_map(
			static function ( array $row ) use ( $current ): array {
				return array(
					'id'         => $row['id'],
					'name'       => $row['name'],
					'min_points' => $row['min_points'],
					'is_current' => $current && ( $row['id'] === $current['id'] ),
				);
			},
			$levels
		);
	}

	/**
	 * Calculate progress percentage toward the next level.
	 *
	 * @param int $user_id User to calculate for.
	 * @return int  0–100 (100 = max level reached).
	 */
	public static function get_progress_percent( int $user_id ): int {
		$points  = PointsEngine::get_total( $user_id );
		$current = self::get_level_for_points( $points );
		$next    = self::get_next_level_for_points( $points );

		if ( ! $current ) {
			return 0;
		}

		if ( ! $next ) {
			return 100; // Max level.
		}

		$span = $next['min_points'] - $current['min_points'];
		if ( $span <= 0 ) {
			return 100;
		}

		return min( 100, (int) round( ( ( $points - $current['min_points'] ) / $span ) * 100 ) );
	}
}
