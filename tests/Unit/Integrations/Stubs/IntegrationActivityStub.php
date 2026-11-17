<?php
/**
 * Stand-in for BuddyNext's copy flag, read by WBGam\Engine\HostActivity::is_copy().
 *
 * @package WB_Gamification
 */

namespace BuddyNext\Feed;

/**
 * Test double: is_mirror() returns whatever the test sets.
 */
final class IntegrationActivity {

	/**
	 * Whether a copy is being written.
	 *
	 * @var bool
	 */
	public static bool $mirror = false;

	/**
	 * Mirrors BuddyNext's flag.
	 *
	 * @return bool
	 */
	public static function is_mirror(): bool {
		return self::$mirror;
	}
}
