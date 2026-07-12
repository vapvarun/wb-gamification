<?php
/**
 * The plugin's one lock.
 *
 * Before this class there were five "locks" and not one of them locked anything:
 *
 *   get_transient( $key )   // both racers see nothing here...
 *   set_transient( $key )   // ...and both set it here
 *
 * That is two operations. Two concurrent workers both pass. It is not a lock; it is a comment
 * that looks like one. `SiteFirstBadgeEngine` even said so in its own source -- "Race-safe: use
 * a transient lock" -- while two members were being awarded the same "first to reach Champion".
 *
 * The other shape, which reads as more sophisticated and is just as empty:
 *
 *   if ( ! wp_cache_add( $key, 1, '', 60 ) ) { return; }
 *
 * `wp_cache_add()` IS atomic -- when a persistent object cache is installed. On a default
 * WordPress install there is none, so it is a process-local array offering ZERO exclusion
 * between workers. KudosEngine shipped that with a comment asserting it was "atomic across
 * Redis/Memcached": true, and irrelevant on the most common configuration there is.
 *
 * A lock has to live somewhere every PHP worker can see. On a WordPress site exactly one such
 * place is guaranteed: **the database**. MySQL's named locks are atomic across connections,
 * need no schema, and cost one round trip.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic named locks, backed by MySQL.
 *
 * @package WB_Gamification
 */
final class Lock {

	/**
	 * MySQL truncates lock names at 64 characters and treats two names that collide as the same
	 * lock. A hashed suffix keeps a long key (a badge slug, a challenge id) from silently
	 * sharing a lock with a different one.
	 */
	private const MAX_NAME = 64;

	/**
	 * Run a callback while holding an exclusive lock, or don't run it at all.
	 *
	 * Timeout 0 is deliberate: if another worker holds this lock right now, that IS the race we
	 * are guarding against, so we decline rather than queue behind it and do the work twice in
	 * succession.
	 *
	 * @param string   $key      Lock name, unique to the invariant being protected.
	 * @param callable $callback Work to perform while holding the lock.
	 * @param mixed    $declined Value to return when the lock could not be acquired.
	 * @return mixed The callback's return value, or $declined.
	 */
	public static function run( string $key, callable $callback, $declined = false ) {
		global $wpdb;

		$name = self::name( $key );

		// GET_LOCK returns 1 on acquire, 0 on timeout, NULL on error. Only 1 means we hold it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 0 ) );

		if ( '1' !== (string) $acquired ) {
			return $declined;
		}

		try {
			return $callback();
		} finally {
			// Released on EVERY path -- normal return, early return, or an exception thrown by a
			// listener three layers down. A leaked named lock blocks this key until the database
			// connection closes, which on a persistent connection can be a very long time.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Namespace a key and keep it inside MySQL's 64-character limit.
	 *
	 * Two different keys must never resolve to the same lock name, so anything that would
	 * overflow is hashed rather than truncated -- truncation is exactly how two distinct badges
	 * would end up sharing one lock and one of them silently never awarding.
	 *
	 * @param string $key Caller's key.
	 * @return string Lock name safe for GET_LOCK.
	 */
	private static function name( string $key ): string {
		$name = 'wb_gam_' . $key;

		if ( strlen( $name ) <= self::MAX_NAME ) {
			return $name;
		}

		return 'wb_gam_' . md5( $key );
	}
}
