<?php
/**
 * WB Gamification - settings import / export.
 *
 * Serialises the plugin's CONFIGURATION options (points values, enabled flags,
 * per-action currencies, levels-config, kudos, automation rules, realtime,
 * access exclusions, hub page mapping, ...) to a portable JSON document, and
 * applies one back. Lets owners move a tuned config from staging to production
 * without hand-copying dozens of options.
 *
 * Deliberately excludes runtime / derived / schema state (db version, feature
 * schema gates, caches, snapshots, flush markers, wizard flags) so an import
 * never corrupts the target site's migration state.
 *
 * @package WB_Gamification
 * @since   1.5.3
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// This class reads/writes wp_options directly for a one-shot admin export;
// the option API is used for writes on import.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Exports and imports the plugin's settings options.
 *
 * @package WB_Gamification
 */
final class SettingsIO {

	private const MARKER = 'wb-gamification';

	/**
	 * Option-name patterns that are runtime / derived / schema state, NOT
	 * configuration. These are never exported and never applied on import.
	 *
	 * @var string[]
	 */
	private const EXCLUDE_PATTERNS = array(
		'/_db_version$/',
		'/^wb_gam_feature_/',
		'/cache/',
		'/snapshot/',
		'/last_changed/',
		'/incrementor/',
		'/_lock$/',
		'/_v\d+$/',
		'/wizard_complete$/',
		'/_endpoint_v/',
	);

	/**
	 * Whether an option name is excluded (runtime/derived/schema, not a setting).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function is_excluded( string $name ): bool {
		foreach ( self::EXCLUDE_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the export document: every `wb_gam_*` configuration option.
	 *
	 * @return array{plugin:string,version:string,exported_at:string,options:array<string,mixed>}
	 */
	public static function export(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wb\_gam\_%'",
			ARRAY_A
		);

		$options = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['option_name'];
			if ( self::is_excluded( $name ) ) {
				continue;
			}
			$options[ $name ] = maybe_unserialize( $row['option_value'] );
		}
		ksort( $options );

		return array(
			'plugin'      => self::MARKER,
			'version'     => defined( 'WB_GAM_VERSION' ) ? WB_GAM_VERSION : '',
			'exported_at' => gmdate( 'c' ),
			'options'     => $options,
		);
	}

	/**
	 * Apply an export document to this site.
	 *
	 * Only keys that start with `wb_gam_` and are not excluded are written;
	 * anything else in the payload is skipped (defensive against a tampered or
	 * mismatched file).
	 *
	 * @param array $data Decoded export document.
	 * @return array{ok:bool,applied:int,skipped:int,error?:string}
	 */
	public static function import( array $data ): array {
		if ( self::MARKER !== ( $data['plugin'] ?? '' ) || ! isset( $data['options'] ) || ! is_array( $data['options'] ) ) {
			return array(
				'ok'      => false,
				'applied' => 0,
				'skipped' => 0,
				'error'   => 'invalid_document',
			);
		}

		$applied = 0;
		$skipped = 0;
		foreach ( $data['options'] as $name => $value ) {
			$name = (string) $name;
			if ( 0 !== strpos( $name, 'wb_gam_' ) || self::is_excluded( $name ) ) {
				++$skipped;
				continue;
			}
			update_option( $name, $value );
			++$applied;
		}

		// Access exclusions may have changed; drop the per-request resolve cache.
		PointsEngine::flush_exclusion_cache();

		return array(
			'ok'      => true,
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}
}
