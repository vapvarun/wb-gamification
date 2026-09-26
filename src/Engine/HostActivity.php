<?php
/**
 * Whether the current write is a host community's copy of an action another plugin already recorded.
 *
 * BuddyNext copies content and actions between family plugins: a feed card for a blog post,
 * listing or job (IntegrationActivity::is_system_publish()), and mirrors of follows, MediaVerse
 * lightbox comments and Jetonomy replies in both directions (IntegrationActivity::is_mirror()).
 * Each copy fires the other plugin's hook, so a trigger that paid for it would pay the member
 * twice for one action. Triggers that can fire for a copy return no member while this is true;
 * the original action still pays once.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the host community's "this write is a copy" flags.
 */
final class HostActivity {

	/**
	 * True while BuddyNext is writing a copy (mirror or system feed card).
	 *
	 * @return bool
	 */
	public static function is_copy(): bool {
		$host = '\\BuddyNext\\Feed\\IntegrationActivity';

		foreach ( array( 'is_mirror', 'is_system_publish' ) as $flag ) {
			if ( is_callable( array( $host, $flag ) ) && call_user_func( array( $host, $flag ) ) ) {
				return true;
			}
		}

		return false;
	}
}
