<?php
/**
 * WB Gamification — Cross-cutting log helper.
 *
 * Single helper used by every engine. No engine should call error_log()
 * directly after this lands — they all go through Log::warning() /
 * Log::error() / Log::debug().
 *
 * The log goes to PHP's error log via error_log(); the file location is
 * controlled by WordPress's WP_DEBUG_LOG constant (typically
 * wp-content/debug.log on a development install, or syslog on prod).
 *
 * Severity rules:
 *   - error()   — always logs. Use for genuine failures the site owner
 *                 needs to see (DB write failed, wp_mail failed, webhook
 *                 delivery permanently failed after retries).
 *   - warning() — logs only when WP_DEBUG is enabled. Use for soft
 *                 failures (badge already earned, event skipped by
 *                 rate limiter — useful while debugging, noise in prod).
 *   - debug()   — logs only when WP_DEBUG is enabled. Use for tracing
 *                 individual events through the pipeline.
 *
 * Every log line is prefixed with `[wb_gam] {LEVEL}` so the team can
 * grep for plugin output without false positives from other plugins.
 *
 * @package WB_Gamification
 * @since   1.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide log helper.
 *
 * @package WB_Gamification
 */
final class Log {

	/**
	 * Log an error. Always written, regardless of WP_DEBUG.
	 *
	 * Use for failures the site owner needs to see — DB writes that
	 * failed, async jobs that exhausted retries, wp_mail() returning
	 * false, webhook deliveries that gave up.
	 *
	 * @param string $message   Human-readable summary. Don't include
	 *                          sensitive data (passwords, full tokens) —
	 *                          this lands in the error log.
	 * @param array  $context   Structured context to append. Serialized
	 *                          JSON-style for grep-friendliness.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'ERROR', $message, $context );
	}

	/**
	 * Log a warning. Only written when WP_DEBUG is enabled.
	 *
	 * Use for soft failures — events skipped by rate limiter, badges
	 * already earned, attempts to award invalid actions.
	 *
	 * @param string $message Human-readable summary.
	 * @param array  $context Structured context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		if ( ! self::is_debug_on() ) {
			return;
		}
		self::write( 'WARN', $message, $context );
	}

	/**
	 * Log a debug trace. Only written when WP_DEBUG is enabled.
	 *
	 * Use for tracing events through the pipeline. Verbose; off in prod.
	 *
	 * @param string $message Human-readable summary.
	 * @param array  $context Structured context.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! self::is_debug_on() ) {
			return;
		}
		self::write( 'DEBUG', $message, $context );
	}

	/**
	 * Whether WP_DEBUG is currently enabled.
	 *
	 * @return bool
	 */
	private static function is_debug_on(): bool {
		return defined( 'WP_DEBUG' ) && (bool) WP_DEBUG;
	}

	/**
	 * Format and emit a single log line.
	 *
	 * @param string $level   ERROR / WARN / DEBUG.
	 * @param string $message Plain-text summary.
	 * @param array  $context Structured context (serialized as JSON).
	 */
	private static function write( string $level, string $message, array $context ): void {
		$line = sprintf( '[wb_gam] %s %s', $level, $message );

		if ( ! empty( $context ) ) {
			// JSON-encode for greppability; wp_json_encode handles escapes safely.
			$line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}
