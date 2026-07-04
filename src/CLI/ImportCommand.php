<?php
/**
 * WB Gamification — competitor import CLI.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\CLI;

use WBGam\Integrations\Importers\GamiPressImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Import points/history from another gamification plugin.
 *
 * @package WB_Gamification
 */
class ImportCommand {

	/**
	 * Import from a supported source plugin.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Which plugin to import from. Currently: gamipress.
	 *
	 * [--dry-run]
	 * : Build + reconcile against the source's own balances, but write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification import gamipress --dry-run
	 *     wp wb-gamification import gamipress
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$source  = strtolower( (string) ( $args[0] ?? '' ) );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( 'gamipress' !== $source ) {
			\WP_CLI::error( "Unsupported source: {$source}. Supported: gamipress." );
		}
		if ( ! GamiPressImporter::is_available() ) {
			\WP_CLI::error( 'GamiPress data not found (no wp_gamipress_logs table).' );
		}

		$result = GamiPressImporter::run( $dry_run );
		\WP_CLI::log( sprintf( '%s %d source row(s).', $dry_run ? 'Previewed' : 'Imported', $result['rows'] ) );

		$mismatch = 0;
		$table    = array();
		foreach ( $result['reconciliation'] as $uid => $rec ) {
			$table[] = array(
				'user_id'           => $uid,
				'imported_sum'      => $rec['imported_sum'],
				'gamipress_balance' => $rec['gamipress_balance'],
				'match'             => $rec['match'] ? 'yes' : 'NO',
			);
			if ( ! $rec['match'] ) {
				++$mismatch;
			}
		}
		if ( ! empty( $table ) ) {
			\WP_CLI\Utils\format_items( 'table', $table, array( 'user_id', 'imported_sum', 'gamipress_balance', 'match' ) );
		}

		if ( ! $dry_run && isset( $result['ingest'] ) ) {
			$i = $result['ingest'];
			\WP_CLI::log(
				sprintf(
					'Ingest: imported=%d skipped_duplicate=%d failed=%d badges_awarded=%d',
					$i['imported'],
					$i['skipped_duplicate'],
					$i['failed'],
					$i['badges_awarded']
				)
			);
		}

		if ( $mismatch > 0 ) {
			\WP_CLI::warning( "{$mismatch} user(s) did not reconcile — investigate before trusting the import." );
		} else {
			\WP_CLI::success( 'All users reconciled against GamiPress balances.' );
		}
	}
}
