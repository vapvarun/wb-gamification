<?php
/**
 * WB Gamification — competitor import CLI.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\CLI;

use WBGam\Integrations\Importers\GamiPressImporter;
use WBGam\Integrations\Importers\MyCredImporter;

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
	private const IMPORTERS = array(
		'gamipress' => GamiPressImporter::class,
		'mycred'    => MyCredImporter::class,
	);

	public function __invoke( array $args, array $assoc_args ): void {
		$source  = strtolower( (string) ( $args[0] ?? '' ) );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( ! isset( self::IMPORTERS[ $source ] ) ) {
			\WP_CLI::error( "Unsupported source: {$source}. Supported: " . implode( ', ', array_keys( self::IMPORTERS ) ) . '.' );
		}
		$importer = self::IMPORTERS[ $source ];
		if ( ! $importer::is_available() ) {
			\WP_CLI::error( "No {$source} data found to import." );
		}

		$result = $importer::run( $dry_run );
		\WP_CLI::log( sprintf( '%s %d source row(s).', $dry_run ? 'Previewed' : 'Imported', $result['rows'] ) );

		// The reconciliation carries a source-specific balance key
		// (gamipress_balance / mycred_balance); render it generically.
		$balance_key = '';
		foreach ( (array) reset( $result['reconciliation'] ) as $k => $v ) {
			if ( str_ends_with( $k, '_balance' ) ) {
				$balance_key = $k;
			}
		}
		$mismatch = 0;
		$table    = array();
		foreach ( $result['reconciliation'] as $uid => $rec ) {
			$table[] = array(
				'user_id'        => $uid,
				'imported_sum'   => $rec['imported_sum'],
				'source_balance' => '' !== $balance_key ? $rec[ $balance_key ] : '',
				'match'          => $rec['match'] ? 'yes' : 'NO',
			);
			if ( ! $rec['match'] ) {
				++$mismatch;
			}
		}
		if ( ! empty( $table ) ) {
			\WP_CLI::log( 'Points:' );
			\WP_CLI\Utils\format_items( 'table', $table, array( 'user_id', 'imported_sum', 'source_balance', 'match' ) );
		}

		// Achievement reconciliation, when the importer reports it.
		if ( ! empty( $result['achievement_reconciliation'] ) ) {
			$ach_table = array();
			foreach ( $result['achievement_reconciliation'] as $uid => $rec ) {
				$src = 0;
				foreach ( $rec as $k => $v ) {
					if ( str_ends_with( (string) $k, '_achievements' ) && 'imported_achievements' !== $k ) {
						$src = $v;
					}
				}
				$ach_table[] = array(
					'user_id'  => $uid,
					'imported' => $rec['imported_achievements'] ?? 0,
					'source'   => $src,
					'match'    => ! empty( $rec['match'] ) ? 'yes' : 'NO',
				);
				if ( empty( $rec['match'] ) ) {
					++$mismatch;
				}
			}
			\WP_CLI::log( 'Achievements:' );
			\WP_CLI\Utils\format_items( 'table', $ach_table, array( 'user_id', 'imported', 'source', 'match' ) );
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
			\WP_CLI::success( "All users reconciled against {$source} balances." );
		}
	}
}
