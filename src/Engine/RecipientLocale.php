<?php
/**
 * Build member-facing text in the reader's language, not the actor's.
 *
 * A hook handler runs in the request that FIRED the event: an admin in English
 * awarding a German member, a REST call, a cron run in the site language. Any
 * sentence it builds with __() there is stored (toast queue, bell row, activity
 * post) or sent (email) in that request's language, and the recipient reads it
 * in a language that is not theirs.
 *
 * Every handler that stores or sends human text is registered through one of
 * the two wrappers below, so a new handler cannot forget it and no handler
 * body has to change:
 *
 *   - user(): text only ONE member reads (toast, bell row, email) is built in
 *     that member's locale.
 *   - site(): text EVERYONE reads (a BuddyPress activity post) is built in the
 *     site locale, so the feed is not a mix of whoever's language fired the event.
 *
 * switch_to_locale() returns false without touching its stack when the target
 * equals the current locale, so the common case (same language) costs one
 * cached user-meta read and loads no text domain. restore_previous_locale() is
 * called only when a switch really happened, otherwise it would pop someone
 * else's entry.
 *
 * @package WB_Gamification
 * @since   1.7.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Locale-scoped hook callbacks.
 */
final class RecipientLocale {

	/**
	 * Wrap a hook callback so it runs in one member's locale.
	 *
	 * @param callable $callback Hook callback.
	 * @param int      $arg      Zero-based position of the recipient user id in the hook's arguments.
	 * @return \Closure
	 */
	public static function user( callable $callback, int $arg = 0 ): \Closure {
		return static function ( ...$args ) use ( $callback, $arg ) {
			return self::run_as_user( (int) ( $args[ $arg ] ?? 0 ), static fn() => $callback( ...$args ) );
		};
	}

	/**
	 * Wrap a hook callback so it runs in the site locale.
	 *
	 * @param callable $callback Hook callback.
	 * @return \Closure
	 */
	public static function site( callable $callback ): \Closure {
		return static function ( ...$args ) use ( $callback ) {
			return self::run_in_site( static fn() => $callback( ...$args ) );
		};
	}

	/**
	 * Run a callable in a member's locale, then put the previous locale back.
	 *
	 * @param int      $user_id Recipient. Zero or less runs the callable unchanged.
	 * @param callable $run     Work to do.
	 * @return mixed What $run returns.
	 */
	public static function run_as_user( int $user_id, callable $run ) {
		$switched = $user_id > 0 && switch_to_user_locale( $user_id );
		try {
			return $run();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Run a callable in the site locale, then put the previous locale back.
	 *
	 * @param callable $run Work to do.
	 * @return mixed What $run returns.
	 */
	public static function run_in_site( callable $run ) {
		$switched = switch_to_locale( get_locale() );
		try {
			return $run();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
