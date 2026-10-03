<?php
/**
 * Where recurring Action Scheduler jobs are checked and armed.
 *
 * @package WB_Gamification
 */

declare( strict_types=1 );

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Checking whether a recurring job is armed (as_has_scheduled_action()) is a
 * database query. Run on `init`, it was paid by every page, REST call, image and
 * heartbeat. A recurring job only needs checking where that is cheap and still
 * reliable: the cron runner (it re-arms a lost job within one tick), wp-admin
 * page loads and WP-CLI.
 */
final class SchedulingContext {

	/**
	 * Is this a request where recurring schedules should be checked and armed?
	 *
	 * @return bool
	 */
	public static function is_scheduling_request(): bool {
		return wp_doing_cron()
			|| ( is_admin() && ! wp_doing_ajax() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}
}
