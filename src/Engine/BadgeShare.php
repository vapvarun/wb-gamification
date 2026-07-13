<?php
/**
 * Who may see a member's badge — the one answer, in one place.
 *
 * Three public surfaces render a specific member's specific badge to anybody who asks:
 *
 *   GET  /wp-json/wb-gamification/v1/badges/{badge_id}/share/{user_id}       (the OG share card)
 *   GET  /wp-json/wb-gamification/v1/badges/{badge_id}/credential/{user_id}  (the OpenBadges credential)
 *   GET  /badge/{badge_id}/{user_id}                                          (the share PAGE, rewrite rule)
 *
 * None of them asked the member. `badge_id` and `user_id` are both guessable, so a stranger could walk
 * them and learn which badges any member holds and when they earned them -- including a member whose
 * profile is private. The plugin's answer to "is this public?" was "is the feature switched on?",
 * which is the site owner's decision about a FEATURE, not the member's decision about THEMSELVES.
 *
 * The decision belongs to the person the data is about. A member shares a badge; sharing is what makes
 * it public; nothing else does.
 *
 * Why this is not solved with `Privacy::can_view_public_profile()` (the fix used for the challenges
 * leak): a public profile means "you may look at my profile page". It does not mean "you may enumerate
 * my achievements from outside it, and mint a verifiable credential asserting them". And a share card
 * MUST resolve for a caller with no cookie -- LinkedIn's crawler, a Slack unfurl -- so gating on the
 * viewer is useless here. What is needed is consent from the member, recorded once, at the moment they
 * decide to publish. That is what `shared_at` is.
 *
 * NOT GRANDFATHERED, and that is deliberate. On upgrade, every existing earned badge starts private.
 * A member who had already posted a credential link to LinkedIn will find it 404s until they press
 * Share again. That is a real cost, and it is the smaller one: the alternative is to keep publishing,
 * without consent, the achievements of every member who never asked for it. An owner who knowingly
 * relied on the old open behaviour can restore it in one command --
 * `wp wb-gamification share grandfather` -- which is a lever, not a default.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.

/**
 * Records and enforces a member's decision to publish one of their badges.
 *
 * @package WB_Gamification
 */
final class BadgeShare {

	/**
	 * Object-cache group.
	 */
	private const CACHE_GROUP = 'wb_gamification';

	/**
	 * Has this member published this badge?
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Badge.
	 * @return bool True when the member has shared it.
	 */
	public static function is_shared( int $user_id, string $badge_id ): bool {
		if ( $user_id <= 0 || '' === $badge_id ) {
			return false;
		}

		$cache_key = 'wb_gam_share_' . $user_id . '_' . md5( $badge_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;

		$shared_at = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"SELECT shared_at FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d AND badge_id = %s",
				$user_id,
				$badge_id
			)
		);

		$shared = null !== $shared_at && '' !== (string) $shared_at;

		wp_cache_set( $cache_key, $shared ? 1 : 0, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $shared;
	}

	/**
	 * May this viewer see the member's badge on a PUBLIC surface?
	 *
	 * The gate every public share/credential surface consults. Self and admins always pass -- a member
	 * previewing their own share card before they publish it is the normal first step, and refusing
	 * them a look at their own badge would be absurd.
	 *
	 * @param int      $user_id   Member the badge belongs to.
	 * @param string   $badge_id  Badge.
	 * @param int|null $viewer_id Defaults to the current user (0 when logged out).
	 * @return bool
	 */
	public static function can_view_public( int $user_id, string $badge_id, ?int $viewer_id = null ): bool {
		if ( $user_id <= 0 || '' === $badge_id ) {
			return false;
		}

		$viewer_id = $viewer_id ?? get_current_user_id();

		if ( $viewer_id > 0 && ( $viewer_id === $user_id || user_can( $viewer_id, 'manage_options' ) ) ) {
			return true;
		}

		return self::is_shared( $user_id, $badge_id );
	}

	/**
	 * Publish a member's badge.
	 *
	 * Only ever called for a badge the member actually holds -- publishing a badge you have not earned
	 * would mint a credential asserting something untrue.
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Badge.
	 * @return bool True when the badge is now shared.
	 */
	public static function share( int $user_id, string $badge_id ): bool {
		if ( ! BadgeEngine::has_badge( $user_id, $badge_id ) ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'wb_gam_user_badges',
			array( 'shared_at' => current_time( 'mysql' ) ),
			array(
				'user_id'  => $user_id,
				'badge_id' => $badge_id,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		self::flush( $user_id, $badge_id );

		/**
		 * Fires when a member publishes a badge.
		 *
		 * @since 1.6.4
		 *
		 * @param int    $user_id  Member.
		 * @param string $badge_id Badge now public.
		 */
		do_action( 'wb_gam_badge_shared', $user_id, $badge_id );

		return false !== $updated;
	}

	/**
	 * Unpublish a member's badge.
	 *
	 * Withdrawing consent has to work as reliably as giving it, or the consent was never real. After
	 * this, the share card, the credential and the share page all 404 for strangers again.
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Badge.
	 * @return bool
	 */
	public static function unshare( int $user_id, string $badge_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'wb_gam_user_badges',
			array( 'shared_at' => null ),
			array(
				'user_id'  => $user_id,
				'badge_id' => $badge_id,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		self::flush( $user_id, $badge_id );

		/**
		 * Fires when a member unpublishes a badge.
		 *
		 * @since 1.6.4
		 *
		 * @param int    $user_id  Member.
		 * @param string $badge_id Badge no longer public.
		 */
		do_action( 'wb_gam_badge_unshared', $user_id, $badge_id );

		return false !== $updated;
	}

	/**
	 * Every badge this member has published.
	 *
	 * @param int $user_id Member.
	 * @return string[] Badge ids.
	 */
	public static function shared_badges( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
					"SELECT badge_id FROM {$wpdb->prefix}wb_gam_user_badges
					  WHERE user_id = %d AND shared_at IS NOT NULL",
					$user_id
				)
			)
		);
	}

	/**
	 * Drop the cached answer for one member+badge.
	 *
	 * @param int    $user_id  Member.
	 * @param string $badge_id Badge.
	 * @return void
	 */
	private static function flush( int $user_id, string $badge_id ): void {
		wp_cache_delete( 'wb_gam_share_' . $user_id . '_' . md5( $badge_id ), self::CACHE_GROUP );
	}
}
