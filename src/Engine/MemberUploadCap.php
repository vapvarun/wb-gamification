<?php
/**
 * WB Gamification — Member upload capability bridge.
 *
 * WordPress's default role matrix gives `upload_files` only to
 * Administrator / Editor / Author. Subscribers and Contributors —
 * the two roles most community sites use for members — cannot
 * upload media even when participating in gamification features
 * that legitimately need image evidence (achievement submissions,
 * profile cosmetics, kudos cards, etc.).
 *
 * This engine grants `upload_files` to every logged-in user via a
 * `user_has_cap` filter so the achievement-submission rich-text
 * editor exposes the Add Media button, the modal's AJAX upload
 * succeeds, and the resulting attachment is owned by the member.
 *
 * Opt out via the `wb_gam_grant_member_uploads` filter — site owners
 * who don't want subscribers to upload anything return `false` and
 * the grant becomes a no-op.
 *
 * @package WB_Gamification
 * @since   1.4.1
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges `upload_files` to member roles for gamification surfaces.
 */
final class MemberUploadCap {

	/**
	 * Hook the capability grant.
	 */
	public static function init(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_upload_to_members' ), 10, 4 );
	}

	/**
	 * Grant `upload_files` to logged-in members.
	 *
	 * Runs on every cap check; cheap (single in-memory key set). The
	 * grant only adds capabilities — it never revokes — so admins,
	 * editors, and authors keep theirs untouched.
	 *
	 * @param array      $allcaps All capabilities currently flagged for this user.
	 * @param string[]   $caps    The cap(s) being checked.
	 * @param array      $args    [0] = primitive cap, [1] = user id, [2+] = context.
	 * @param \WP_User   $user    User being checked.
	 * @return array              Filtered allcaps.
	 */
	public static function grant_upload_to_members( array $allcaps, array $caps, array $args, $user ): array {
		// Guests don't get uploads — the feature requires login.
		if ( ! ( $user instanceof \WP_User ) || ! $user->exists() ) {
			return $allcaps;
		}

		/**
		 * Filter — opt out of the member upload grant.
		 *
		 * Return false to disable the grant. Site owners who already use
		 * a role-manager plugin to control upload_files explicitly should
		 * disable this so the plugin doesn't shadow their settings.
		 *
		 * @since 1.4.1
		 * @param bool     $grant Default true.
		 * @param \WP_User $user  User being checked.
		 */
		$grant = apply_filters( 'wb_gam_grant_member_uploads', true, $user );
		if ( ! $grant ) {
			return $allcaps;
		}

		// Already has it (admin/editor/author) — leave untouched.
		if ( ! empty( $allcaps['upload_files'] ) ) {
			return $allcaps;
		}

		$allcaps['upload_files'] = true;
		return $allcaps;
	}
}
