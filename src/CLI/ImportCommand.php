<?php
/**
 * WB Gamification — competitor import CLI.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\CLI;

use WBGam\Integrations\Importers\BadgeOSImporter;
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
		'badgeos'   => BadgeOSImporter::class,
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

		// Same suppression as the REST path -- a migration must not announce itself to members.
		$result = \WBGam\Engine\ImportMode::run(
			static fn() => $importer::run( $dry_run )
		);
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

		// Achievements/badges reconciliation, when the importer reports it
		// (achievement_reconciliation for GamiPress, badge_reconciliation for
		// myCred). Rendered generically: imported count vs source count.
		foreach ( array(
			'achievement_reconciliation' => 'Achievements',
			'badge_reconciliation'       => 'Badges',
		) as $key => $label ) {
			if ( empty( $result[ $key ] ) ) {
				continue;
			}
			$rows_out = array();
			foreach ( $result[ $key ] as $uid => $rec ) {
				$imported  = 0;
				$src_count = 0;
				foreach ( $rec as $k => $v ) {
					if ( is_int( $v ) && str_starts_with( (string) $k, 'imported_' ) ) {
						$imported = $v;
					} elseif ( is_int( $v ) ) {
						$src_count = $v;
					}
				}
				$rows_out[] = array(
					'user_id'  => $uid,
					'imported' => $imported,
					'source'   => $src_count,
					'match'    => ! empty( $rec['match'] ) ? 'yes' : 'NO',
				);
				if ( empty( $rec['match'] ) ) {
					++$mismatch;
				}
			}
			\WP_CLI::log( $label . ':' );
			\WP_CLI\Utils\format_items( 'table', $rows_out, array( 'user_id', 'imported', 'source', 'match' ) );
		}

		// Rank reconciliation (source rank vs the WB level a member's imported
		// points map to). A mismatch here usually means the target site already
		// has its own levels that collide with the imported tiers — surfaced as
		// a separate warning, not a hard failure.
		$rank_mismatch = 0;
		if ( ! empty( $result['rank_reconciliation'] ) ) {
			$rank_table = array();
			foreach ( $result['rank_reconciliation'] as $uid => $rec ) {
				$src_rank = '';
				foreach ( $rec as $k => $v ) {
					if ( 'our_level' !== $k && 'match' !== $k && is_string( $v ) ) {
						$src_rank = $v;
					}
				}
				$rank_table[] = array(
					'user_id'     => $uid,
					'our_level'   => $rec['our_level'] ?? '',
					'source_rank' => $src_rank,
					'match'       => ! empty( $rec['match'] ) ? 'yes' : 'NO',
				);
				if ( empty( $rec['match'] ) ) {
					++$rank_mismatch;
				}
			}
			\WP_CLI::log( 'Ranks -> Levels:' );
			\WP_CLI\Utils\format_items( 'table', $rank_table, array( 'user_id', 'our_level', 'source_rank', 'match' ) );
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

		if ( $rank_mismatch > 0 ) {
			\WP_CLI::warning( "{$rank_mismatch} user(s) rank does not match their derived level — usually because this site already has levels that collide with the imported tiers. Review the target site's levels; a fresh migration reconciles cleanly." );
		}

		if ( $mismatch > 0 ) {
			\WP_CLI::warning( "{$mismatch} user(s) did not reconcile on points/achievements — investigate before trusting the import." );
		} else {
			\WP_CLI::success( "Points & achievements reconciled against {$source}." );
		}
	}
}
