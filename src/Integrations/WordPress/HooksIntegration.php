<?php
/**
 * WordPress Native Hooks Integration (DEPRECATED)
 *
 * Superseded by integrations/wordpress.php manifest which provides
 * the same triggers with proper standalone_only flags.
 *
 * This class is kept for backward compatibility but no longer registers
 * any actions. All WordPress triggers are now handled by the manifest.
 *
 * @package WB_Gamification
 * @deprecated 1.0.1 Use integrations/wordpress.php manifest instead.
 */

namespace WBGam\Integrations\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy WordPress hooks — no longer registers actions (manifest handles them).
 *
 * @package WB_Gamification
 * @deprecated 1.0.1
 */
final class HooksIntegration {

	/**
	 * No-op. Manifest-based registration in integrations/wordpress.php
	 * handles all WordPress action triggers since v1.0.1.
	 */
	public static function init(): void {
		// Intentionally empty — actions are registered by integrations/wordpress.php manifest.
	}
}
