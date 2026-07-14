<?php
/**
 * Is an import running right now?
 *
 * A migration replays a member's HISTORY. It is not news.
 *
 * When an owner imports from GamiPress or myCred, every badge that lands calls
 * BadgeEngine::award_badge(), which fires `wb_gam_badge_awarded` -- and everything listening to that
 * hook treats it as something that JUST HAPPENED. So importing a member's three-year-old badge sent
 * them a "Congratulations! You earned the QA2 MC Badge badge!" email, queued a toast, posted to the
 * activity feed, and fired the webhook. QA proved it: a member who had not logged in for a year got a
 * congratulations email because an admin ran a migration.
 *
 * Multiply by the member count of a real community and an import becomes a mail-bomb, a webhook flood
 * and an activity-feed wipeout, all at once, on the owner's first five minutes with the plugin.
 *
 * There WAS a suppression mechanism -- `$event->metadata['_import']`, honoured by Engine::process()
 * -- and it was genuinely working for the POINTS path. It could never have worked for badges: the
 * importers call BadgeEngine::award_badge() directly and never pass through Engine::process(), so
 * there was no event to carry the flag. A per-event marker cannot suppress a path that has no event.
 *
 * Hence a run-scoped mode. The importer says "I am migrating" for the length of the run, and the
 * member-facing listeners -- email, notifications, webhooks, activity -- stand down. The DATA still
 * lands: points, badges, levels, streaks are all written exactly as before. What is suppressed is the
 * ANNOUNCEMENT, which is the only part that was ever wrong.
 *
 * Deliberately a static and not an option: it must not survive the request. A crashed import that
 * left "suppress notifications" persisted in the database would be a far worse bug than the one this
 * fixes -- the community would go quiet and nobody would know why. It dies with the process, and
 * run() releases it in a `finally` so an exception mid-import cannot leave it stuck on either.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Run-scoped flag: an import is replaying history, so do not announce it.
 *
 * @package WB_Gamification
 */
final class ImportMode {

	/**
	 * Whether an import is currently running in this request.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Run a callback with member-facing announcements suppressed.
	 *
	 * The `finally` is the point: an importer that throws must not leave the whole site silent for the
	 * rest of the request.
	 *
	 * @param callable $callback The import work.
	 * @return mixed Whatever the callback returns.
	 */
	public static function run( callable $callback ) {
		$previous     = self::$active;
		self::$active = true;

		try {
			return $callback();
		} finally {
			self::$active = $previous;
		}
	}

	/**
	 * Is an import replaying history right now?
	 *
	 * Every listener that would TELL A MEMBER something must consult this before it speaks.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		/**
		 * Filter whether announcements are suppressed.
		 *
		 * A site that genuinely wants its members emailed about migrated badges can return false --
		 * but that is a decision to mail everybody, and it should be one somebody makes on purpose.
		 *
		 * @since 1.6.4
		 *
		 * @param bool $active Whether an import is running and announcements are suppressed.
		 */
		return (bool) apply_filters( 'wb_gam_import_mode_active', self::$active );
	}
}
