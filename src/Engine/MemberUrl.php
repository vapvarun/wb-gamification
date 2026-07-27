<?php
/**
 * WB Gamification — Member profile URL resolution.
 *
 * One question, asked in one place: "where does this member's name link to?"
 *
 * Before 1.6.4 there was no such place. Nine surfaces each called
 * {@see \WBGam\BuddyPress\UserUrl::resolve()} — which answers a narrower
 * question, "what is this member's BuddyPress URL?", and correctly returns
 * the empty string when BuddyPress is not active — and then each invented
 * its own fallback. On a site without classic BuddyPress (a BuddyNext
 * install, or the plugin running standalone, which is most of them) that
 * produced three different behaviours for the same member:
 *
 *   - the top-members block and the badge share page linked to
 *     `/author/{login}/`, the WordPress author archive, which is not the
 *     member's achievements page and on many sites is disabled outright;
 *   - the leaderboard, the kudos feed and the activity card rendered the
 *     name as dead text, with no link at all, even though the plugin's own
 *     public profile at `/u/{login}` existed and worked;
 *   - only the share page's redirect got it right.
 *
 * The author archive is dropped as a destination entirely. It was never the
 * right answer: it lists the member's *posts*, which is not what someone
 * clicking a name on a leaderboard is asking for, and SEO plugins routinely
 * turn it off.
 *
 * The resolution order, and it is deliberately short:
 *
 *   1. BuddyPress profile, when BuddyPress is active. Community sites already
 *      have a member profile and it is not ours to override — a BP site sees
 *      no change in behaviour from before 1.6.4.
 *   2. This plugin's public profile at `/u/{login}` — but only when
 *      {@see ProfilePage::is_publicly_visible()} says the page will actually
 *      render. Building that URL unconditionally is the trap: the member may
 *      have set their profile private, or the owner may have switched public
 *      profiles off site-wide, and then the link 404s. Trading a wrong link
 *      for a broken one is not a fix.
 *   3. Nothing. The empty string means "render no link" — a name with no
 *      destination is text, not a dead anchor.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

use WBGam\BuddyPress\UserUrl;

defined( 'ABSPATH' ) || exit;
// Silencing a convention-driven false positive so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header and
// doesn't share the .phpcs.xml prefix list, so `wb_gam_member_url` reads as
// unprefixed to it.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Resolves the canonical profile destination for a member.
 */
final class MemberUrl {

	/**
	 * Where should this member's name link to?
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Absolute URL, or the empty string when the member has no
	 *                publicly reachable profile and no link should be rendered.
	 */
	public static function resolve( int $user_id ): string {
		$url = '';

		if ( $user_id > 0 ) {
			// BuddyPress owns the member profile wherever it is active.
			$url = UserUrl::resolve( $user_id );

			if ( '' === $url ) {
				$url = self::own_profile_url( $user_id );
			}
		}

		/**
		 * Filter the destination a member's name links to.
		 *
		 * Sites running a member-profile system this plugin does not know
		 * about (a custom directory, a third-party community plugin) can point
		 * every leaderboard row, podium slot, kudos entry and share card at it
		 * from one place. Return the empty string to render no link.
		 *
		 * @since 1.6.4
		 * @param string $url     Resolved profile URL, or '' when there is none.
		 * @param int    $user_id Member the URL belongs to.
		 */
		return (string) apply_filters( 'wb_gam_member_url', $url, $user_id );
	}

	/**
	 * This plugin's own public profile URL, when it will actually render.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Profile URL, or '' when the profile is not publicly visible.
	 */
	private static function own_profile_url( int $user_id ): string {
		if ( ! ProfilePage::is_publicly_visible( $user_id ) ) {
			return '';
		}

		$user = get_userdata( $user_id );
		if ( ! $user || '' === (string) $user->user_login ) {
			return '';
		}

		return ProfilePage::profile_url( (string) $user->user_login );
	}

	/**
	 * Warm the user cache before resolving a list of members.
	 *
	 * `resolve()` reads user meta (the per-member privacy flag) and the user
	 * record (for the login slug). Called once per row on a leaderboard that
	 * is a query per row per lookup; `cache_users()` fetches both for the whole
	 * page in two queries. Every surface that renders a row set calls this with
	 * the ids it already has before it starts rendering.
	 *
	 * @param array<int,mixed> $user_ids User IDs about to be resolved.
	 */
	public static function prime( array $user_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

		if ( ! $ids ) {
			return;
		}

		cache_users( $ids );
	}

	/**
	 * Render member content as a link, or as plain markup when there is no link.
	 *
	 * Keeps the "what if there is no URL?" branch in one place instead of once
	 * per anchor across the blocks.
	 *
	 * With no link, the content still needs whatever CSS class the anchor was
	 * carrying, so it falls back to a `<span>` with the same class — and the
	 * stylesheet scopes the `:hover` underline to `a`, so a name that cannot be
	 * clicked does not pretend it can be. When there is no class to carry there
	 * is nothing for the span to do either, so the content is returned bare.
	 *
	 * @param string $url        Destination from {@see resolve()}; '' renders no anchor.
	 * @param string $inner_html Already-escaped inner markup (avatar img, escaped name).
	 * @param string $class      Optional CSS class for the wrapper element.
	 * @return string Wrapper element HTML.
	 */
	public static function wrap( string $url, string $inner_html, string $class = '' ): string {
		$attr = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';

		if ( '' === $url ) {
			return '' !== $class ? '<span' . $attr . '>' . $inner_html . '</span>' : $inner_html;
		}

		return '<a href="' . esc_url( $url ) . '"' . $attr . '>' . $inner_html . '</a>';
	}
}
