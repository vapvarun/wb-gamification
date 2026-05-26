<?php
/**
 * BuddyPress user-URL resolution helper.
 *
 * BP 12.0.0 deprecated `bp_core_get_user_domain()` in favour of
 * `bp_members_get_user_url()`. Every wb-gamification block + REST
 * controller that linked a member out to their profile was emitting a
 * `PHP Deprecated` notice on each render under BP 12+. With a leaderboard
 * showing 10 rows and a top-members podium showing 3, that's tens of
 * notices per page, hundreds across a smoke walk — enough to bury real
 * errors in `debug.log` and break the smoke gate.
 *
 * Use {@see resolve()} everywhere we previously called
 * `bp_core_get_user_domain( $user_id )`. Returns the empty string when
 * BuddyPress is not active or the user is invalid — callers that want a
 * non-link fallback should branch on `'' === $url`.
 *
 * @package WBGam\BuddyPress
 */

namespace WBGam\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a BuddyPress member profile URL for a user ID.
 *
 * Tries `bp_members_get_user_url()` first (BP 12.0.0+), falls back to
 * `bp_core_get_user_domain()` for older BP installs. Returns an empty
 * string if neither function is available — i.e. BuddyPress is not active
 * or has been removed mid-request.
 */
final class UserUrl {

	/**
	 * Resolve a user's BuddyPress profile URL.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Profile URL, or an empty string when BP is unavailable
	 *                or the user is invalid.
	 */
	public static function resolve( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'bp_members_get_user_url' ) ) {
			return (string) bp_members_get_user_url( $user_id );
		}
		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			// BP <12.0.0 path — legacy installs still use this.
			return (string) bp_core_get_user_domain( $user_id );
		}
		return '';
	}
}
