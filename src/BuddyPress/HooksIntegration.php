<?php
/**
 * BuddyPress Hooks Integration (DEPRECATED)
 *
 * Superseded by integrations/buddypress.php manifest which provides
 * proper callback signatures, metadata callbacks, and async flags.
 *
 * This class is kept for backward compatibility but no longer registers
 * any actions. All BuddyPress triggers are now handled by the manifest.
 *
 * @package WB_Gamification
 * @deprecated 1.0.0 Use integrations/buddypress.php manifest instead.
 */

namespace WBGam\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy BuddyPress hooks — no longer registers actions (manifest handles them).
 *
 * @package WB_Gamification
 * @deprecated 1.0.0
 */
final class HooksIntegration {

	/**
	 * No-op. Manifest-based registration in integrations/buddypress.php
	 * handles all BuddyPress action triggers since v1.0.0.
	 */
	public static function init(): void {
		// Intentionally empty — actions are registered by integrations/buddypress.php manifest.
	}
}
