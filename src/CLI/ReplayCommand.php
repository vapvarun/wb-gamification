<?php
/**
 * WP-CLI: Event replay for WB Gamification.
 *
 * Closes G4 from the integration gaps roadmap: re-evaluate active
 * badge rules against a user's cumulative state. When a site owner
 * adds a new badge or changes a threshold, this command grants
 * any badge the user already qualifies for.
 *
 * Mechanics:
 *   - The badge engine is already idempotent — `BadgeEngine::award_badge`
 *     returns false if the user already holds the badge.
 *   - `BadgeEngine::evaluate_on_award` walks all active rules and
 *     awards every qualifying one. We invoke it once per (user_id,
 *     action_id) pair derived from the user's stored events.
 *   - Iterating per-action is necessary because the `action_count`
 *     rule type has a fast path that only fires when
 *     `$event->action_id === $rule_config['action_id']`.
 *
 * Limitations:
 *   - `admin_awarded` badge rules are never auto-evaluated by design.
 *   - Custom rule types registered via `wb_gam_badge_condition`
 *     filter run if the listener doesn't depend on the synthetic event
 *     payload's metadata.
 *
 * @package WB_Gamification
 * @since   1.1.0
 */

namespace WBGam\CLI;

use WBGam\Engine\BadgeEngine;
use WBGam\Engine\Event;
use WBGam\Engine\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Replay stored events to retroactively grant badges.
 *
 * @package WB_Gamification
 */
class ReplayCommand {

	/**
	 * Re-evaluate active badge rules for ONE user.
	 *
	 * Idempotent — already-earned badges are skipped automatically.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address. Positional because --user= is
	 *   a reserved WP-CLI global flag.
	 *
	 * [--dry-run]
	 * : Print what WOULD be awarded, but don't grant anything.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification replay user 42
	 *   wp wb-gamification replay user jane@example.com --dry-run
	 *
	 * @param array $args       Positional args ([0] = user reference).
	 * @param array $assoc_args Named args.
	 */
	public function user( array $args, array $assoc_args ): void {
		$user = $this->resolve_user( (string) ( $args[0] ?? '' ) );
		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );

		$result = $this->replay_user( $user->ID, $dry_run );

		\WP_CLI::log(
			sprintf(
				'%s: %s',
				$user->display_name,
				$result['summary']
			)
		);

		if ( ! empty( $result['awarded'] ) ) {
			\WP_CLI::log( '  Newly-awarded badges:' );
			foreach ( $result['awarded'] as $badge_id ) {
				\WP_CLI::log( "    • {$badge_id}" );
			}
		}
	}

	/**
	 * Re-evaluate active badge rules for EVERY user with stored events.
	 *
	 * Idempotent — already-earned badges are skipped per-user.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max users to process. Default 100. Use 0 for unlimited.
	 *
	 * [--dry-run]
	 * : Print what WOULD be awarded, but don't grant anything.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification replay all --dry-run
	 *   wp wb-gamification replay all --limit=500
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function all( array $args, array $assoc_args ): void {
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 100;
		$dry_run = ! empty( $assoc_args['dry-run'] );

		global $wpdb;
		$query = "SELECT DISTINCT user_id
		            FROM {$wpdb->prefix}wb_gam_events
		           WHERE user_id > 0
		           ORDER BY user_id ASC";
		if ( $limit > 0 ) {
			$query .= ' LIMIT ' . $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- internal CLI scan, no user input in query.
		$user_ids = (array) $wpdb->get_col( $query );

		if ( empty( $user_ids ) ) {
			\WP_CLI::warning( 'No users have any stored events. Nothing to replay.' );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Replaying %d user(s)%s...',
				count( $user_ids ),
				$dry_run ? ' (dry-run)' : ''
			)
		);

		$totals = array(
			'users_scanned'    => 0,
			'users_with_award' => 0,
			'awards_total'     => 0,
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Users', count( $user_ids ) );
		foreach ( $user_ids as $user_id ) {
			$result = $this->replay_user( (int) $user_id, $dry_run );
			$totals['users_scanned']++;
			if ( ! empty( $result['awarded'] ) ) {
				$totals['users_with_award']++;
				$totals['awards_total'] += count( $result['awarded'] );
			}
			$progress->tick();
		}
		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'%d users scanned, %d gained badges, %d total %s awarded.',
				$totals['users_scanned'],
				$totals['users_with_award'],
				$totals['awards_total'],
				$dry_run ? '(simulated)' : ''
			)
		);
	}

	// ── Internals ──────────────────────────────────────────────────────────────

	/**
	 * Replay logic for a single user.
	 *
	 * @param int  $user_id Target user.
	 * @param bool $dry_run If true, no actual award; just return what would be awarded.
	 * @return array{summary: string, awarded: string[]}
	 */
	private function replay_user( int $user_id, bool $dry_run ): array {
		// 1. Snapshot the user's currently-earned badges so we can diff at the end.
		$earned_before = BadgeEngine::get_user_earned_badge_ids( $user_id );

		// 2. Determine the set of distinct action_ids this user has events for.
		global $wpdb;
		$action_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT action_id
				   FROM {$wpdb->prefix}wb_gam_events
				  WHERE user_id = %d AND action_id != ''",
				$user_id
			)
		);

		// 3. Always include a generic synthetic action so point_milestone rules
		//    that don't pin to a specific action_id get a chance to fire.
		$action_ids[] = '__replay__';

		if ( $dry_run ) {
			// In dry-run we don't call the engine — we run our own copy of the
			// rule walk, but never call award_badge. Easier: clone the engine's
			// behaviour by capturing what evaluate_on_award WOULD do via a
			// short-circuit filter. Cleaner approach: hook should_award_badge
			// to capture intent and short-circuit.
			$intended = array();
			$capturer = function ( bool $should_award, int $uid, string $badge_id ) use ( $user_id, &$intended ) {
				if ( $uid === $user_id && $should_award ) {
					$intended[ $badge_id ] = true;
				}
				return false; // Block the actual award in dry-run mode.
			};
			add_filter( 'wb_gam_should_award_badge', $capturer, 99, 3 );

			foreach ( $action_ids as $aid ) {
				$event = new Event( array(
					'action_id' => (string) $aid,
					'user_id'   => $user_id,
					'metadata'  => array( '__replay__' => true ),
				) );
				BadgeEngine::evaluate_on_award( $user_id, $event, 0 );
			}

			remove_filter( 'wb_gam_should_award_badge', $capturer, 99 );

			$awarded = array_keys( $intended );

			return array(
				'summary' => sprintf(
					'%d badge(s) would be awarded (dry-run, %d action_ids inspected).',
					count( $awarded ),
					count( $action_ids )
				),
				'awarded' => $awarded,
			);
		}

		// 4. Real run — call evaluate_on_award per action_id.
		foreach ( $action_ids as $aid ) {
			$event = new Event( array(
				'action_id' => (string) $aid,
				'user_id'   => $user_id,
				'metadata'  => array( '__replay__' => true ),
			) );
			BadgeEngine::evaluate_on_award( $user_id, $event, 0 );
		}

		// 5. Diff: which badges did the user gain?
		$earned_after = BadgeEngine::get_user_earned_badge_ids( $user_id );
		$awarded      = array_values( array_diff( $earned_after, $earned_before ) );

		if ( ! empty( $awarded ) ) {
			Log::warning(
				'ReplayCommand: granted badges via replay.',
				array(
					'user_id'  => $user_id,
					'badges'   => $awarded,
				)
			);
		}

		return array(
			'summary' => sprintf(
				'%d new badge(s) awarded (%d action_ids inspected).',
				count( $awarded ),
				count( $action_ids )
			),
			'awarded' => $awarded,
		);
	}

	/**
	 * Resolve a user from id, login, or email.
	 *
	 * @param string|int $needle Lookup needle.
	 * @return \WP_User|null
	 */
	private function resolve_user( $needle ): ?\WP_User {
		if ( '' === (string) $needle ) {
			return null;
		}
		$user = get_user_by( 'id', (int) $needle );
		if ( $user ) {
			return $user;
		}
		$user = get_user_by( 'login', (string) $needle );
		if ( $user ) {
			return $user;
		}
		$user = get_user_by( 'email', (string) $needle );
		return $user ?: null;
	}
}
