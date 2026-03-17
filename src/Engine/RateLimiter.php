<?php
/**
 * REST API Rate Limiter — Token Bucket per User
 *
 * Protects the WB Gamification REST API write endpoints against abuse.
 * Uses a token bucket algorithm implemented via WordPress transients.
 *
 * Defaults:
 *   Capacity  : 60 tokens  (max burst)
 *   Refill    : 1 token per 5 seconds (= 720/hour sustained throughput)
 *   Scope     : per authenticated user_id (unauthenticated requests denied by route permissions)
 *
 * The bucket state is stored in a transient keyed by user_id, containing:
 *   tokens    : float  — current token count
 *   last_refill: int   — Unix timestamp of last refill calculation
 *
 * Applied via:
 *   - EventsController::create_item() before processing external event triggers
 *   - Any route that processes user-facing write actions at high volume
 *
 * Not applied to admin-only routes (manage_options) since those have
 * WordPress capability gating that is a stronger guard.
 *
 * Usage:
 *   if ( ! RateLimiter::consume( get_current_user_id() ) ) {
 *       return new WP_Error( 'rate_limited', 'Too many requests.', [ 'status' => 429 ] );
 *   }
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/** Maximum tokens in the bucket (burst capacity). */
	private const CAPACITY   = 60;

	/** Tokens added per second. */
	private const REFILL_RATE = 0.2; // 1 token per 5 seconds.

	/** Transient TTL — slightly longer than full-bucket drain time. */
	private const TTL = 600; // 10 minutes.

	/**
	 * Attempt to consume one token from the user's bucket.
	 *
	 * @param int $user_id Authenticated user ID.
	 * @return bool         True if the request is allowed; false if rate-limited.
	 */
	public static function consume( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$key    = 'wb_gam_rl_' . $user_id;
		$bucket = get_transient( $key );
		$now    = time();

		if ( false === $bucket ) {
			// First request — full bucket.
			$bucket = [
				'tokens'      => (float) self::CAPACITY,
				'last_refill' => $now,
			];
		} else {
			// Refill tokens since last request.
			$elapsed         = $now - (int) $bucket['last_refill'];
			$refilled        = $elapsed * self::REFILL_RATE;
			$bucket['tokens'] = min( self::CAPACITY, (float) $bucket['tokens'] + $refilled );
			$bucket['last_refill'] = $now;
		}

		if ( $bucket['tokens'] < 1.0 ) {
			// Persist updated state (so refill keeps running) but deny.
			set_transient( $key, $bucket, self::TTL );
			return false;
		}

		$bucket['tokens'] -= 1.0;
		set_transient( $key, $bucket, self::TTL );

		return true;
	}

	/**
	 * How many tokens does the user currently have?
	 *
	 * Useful for returning Retry-After or X-RateLimit-Remaining headers.
	 *
	 * @param int $user_id User ID.
	 * @return int         Remaining tokens (floored), or CAPACITY if no bucket.
	 */
	public static function remaining( int $user_id ): int {
		$key    = 'wb_gam_rl_' . $user_id;
		$bucket = get_transient( $key );

		if ( false === $bucket ) {
			return self::CAPACITY;
		}

		$elapsed = time() - (int) $bucket['last_refill'];
		$tokens  = min( self::CAPACITY, (float) $bucket['tokens'] + $elapsed * self::REFILL_RATE );

		return (int) floor( $tokens );
	}

	/**
	 * Reset a user's bucket (admin utility).
	 *
	 * @param int $user_id User ID.
	 */
	public static function reset( int $user_id ): void {
		delete_transient( 'wb_gam_rl_' . $user_id );
	}
}
