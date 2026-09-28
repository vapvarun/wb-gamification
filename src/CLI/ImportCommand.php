<?php
/**
 * WB Gamification: competitor import CLI.
 *
 * Thin over ImportRunner: the same paged, resumable run the admin screen and REST start, driven from a
 * terminal. The default queues the run in the background exactly as REST does; --sync runs every page
 * in this process (for hosts where WP-Cron or the queue never fires, and for scripts that must wait).
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\CLI;

use WBGam\Engine\ImportRunner;

defined( 'ABSPATH' ) || exit;

/**
 * Import points, badges and ranks from another gamification plugin.
 *
 * @package WB_Gamification
 */
class ImportCommand {

	/**
	 * Import from a supported source plugin.
	 *
	 * Runs in the background by default and returns at once; watch it with `import-status`. Points are
	 * imported first, then badges, then the badges and levels that follow from the imported history are
	 * derived once per member, then every member is reconciled against the source's own balance. A
	 * re-run is safe: rows already imported are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Which plugin to import from: gamipress, mycred or badgeos.
	 *
	 * [--dry-run]
	 * : Count what would be imported and what a previous run already left. Writes nothing.
	 *
	 * [--sync]
	 * : Run every page in this process instead of queueing (no Action Scheduler or WP-Cron needed).
	 *
	 * [--resume]
	 * : Continue an unfinished or stalled run from its last checkpoint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification import mycred --dry-run
	 *     wp wb-gamification import mycred
	 *     wp wb-gamification import mycred --sync
	 *     wp wb-gamification import mycred --resume
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$source = strtolower( (string) ( $args[0] ?? '' ) );
		if ( null === ImportRunner::source_class( $source ) ) {
			\WP_CLI::error( "Unsupported source: {$source}. Supported: " . implode( ', ', array_keys( ImportRunner::sources() ) ) . '.' );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->preview( $source );
			return;
		}

		$sync = isset( $assoc_args['sync'] );

		if ( isset( $assoc_args['resume'] ) ) {
			$run = ImportRunner::resume( $source );
			if ( is_wp_error( $run ) ) {
				\WP_CLI::error( $run->get_error_message() );
			}
			\WP_CLI::log( 'Resuming from the last checkpoint.' );
		} else {
			$run = ImportRunner::start( $source, ! $sync );
			if ( is_wp_error( $run ) ) {
				\WP_CLI::error( $run->get_error_message() );
			}
		}

		if ( ! $sync ) {
			\WP_CLI::success( "Import queued. Check it with: wp wb-gamification import-status {$source}" );
			return;
		}

		$this->drive( $source, (string) $run['run_id'] );
	}

	/**
	 * Show the latest import run for a source.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : gamipress, mycred or badgeos.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification import-status mycred
	 *
	 * @param array $args Positional args.
	 * @return void
	 */
	public static function status( array $args ): void {
		$source = strtolower( (string) ( $args[0] ?? '' ) );
		if ( null === ImportRunner::source_class( $source ) ) {
			\WP_CLI::error( "Unsupported source: {$source}." );
		}

		$run = ImportRunner::progress( $source );
		if ( 'idle' === $run['status'] ) {
			\WP_CLI::log( "No import has run for {$source}." );
			return;
		}

		\WP_CLI::log( sprintf( '%s: %s, %d%%%s', $source, $run['status'], $run['percent'], $run['stalled'] ? ' (STALLED: run with --resume, or add --sync)' : '' ) );
		\WP_CLI::log( sprintf( 'Phase: %s (%d of %d)', $run['phase'], $run['phase_done'], $run['phase_total'] ) );
		\WP_CLI::log(
			sprintf(
				'Imported %d, skipped %d already imported, failed %d, badges awarded %d, levels created %d.',
				$run['totals']['imported'],
				$run['totals']['skipped_duplicate'],
				$run['totals']['failed'],
				$run['totals']['badges_awarded'],
				$run['totals']['levels_created']
			)
		);
		if ( '' !== $run['error'] ) {
			\WP_CLI::warning( 'Last error: ' . $run['error'] );
		}
		self::report_mismatches( $run );
	}

	/**
	 * Print a dry-run preview.
	 *
	 * @param string $source Source slug.
	 * @return void
	 */
	private function preview( string $source ): void {
		$preview = ImportRunner::preview( $source );
		if ( is_wp_error( $preview ) ) {
			\WP_CLI::error( $preview->get_error_message() );
		}

		\WP_CLI::log(
			sprintf(
				'%s would import %d point row(s), %d badge award(s) and %d rank tier(s) in about %d background job(s).',
				$source,
				$preview['points_rows'],
				$preview['awards'],
				$preview['ranks'],
				$preview['jobs']
			)
		);
		if ( $preview['already_imported']['events'] > 0 || $preview['already_imported']['badges'] > 0 ) {
			\WP_CLI::log(
				sprintf(
					'Already imported by an earlier run: %d event(s), %d badge award(s). Those rows are skipped.',
					$preview['already_imported']['events'],
					$preview['already_imported']['badges']
				)
			);
		}
		if ( ! empty( $preview['sample'] ) ) {
			\WP_CLI::log( 'Sample rows:' );
			\WP_CLI\Utils\format_items( 'table', $preview['sample'], array( 'user_id', 'action_id', 'points', 'point_type', 'occurred_at', 'source_key' ) );
		}
		\WP_CLI::success( 'Preview only: nothing was written. Reconciliation against the source runs after the import.' );
	}

	/**
	 * Run every page in this process until the run finishes or fails.
	 *
	 * @param string $source Source slug.
	 * @param string $run_id Run id.
	 * @return void
	 */
	private function drive( string $source, string $run_id ): void {
		$last = '';

		do {
			$run = ImportRunner::advance( $source, $run_id );
			if ( null === $run ) {
				\WP_CLI::error( 'Another import job for this source is running. Wait for it, or check import-status.' );
			}

			if ( $run['phase'] !== $last ) {
				$last = (string) $run['phase'];
				\WP_CLI::log( sprintf( 'Phase: %s', $last ) );
			}
		} while ( in_array( $run['status'], array( 'queued', 'running' ), true ) );

		if ( 'failed' === $run['status'] ) {
			\WP_CLI::error( 'Import paused at its last checkpoint: ' . $run['error'] . ' Fix the cause, then run with --resume.' );
		}

		self::status( array( $source ) );
		$mismatch = array_sum( $run['mismatches'] );
		if ( $mismatch > 0 ) {
			\WP_CLI::warning( "{$mismatch} reconciliation mismatch(es). Investigate before trusting the import." );
		} else {
			\WP_CLI::success( "Points, badges and ranks reconciled against {$source}." );
		}
	}

	/**
	 * Print reconciliation counts and the sampled mismatches.
	 *
	 * @param array<string, mixed> $run Progress.
	 * @return void
	 */
	private static function report_mismatches( array $run ): void {
		$total = array_sum( $run['mismatches'] );
		if ( 'complete' !== $run['status'] ) {
			return;
		}
		\WP_CLI::log(
			sprintf(
				'Reconciliation: %d points, %d badge and %d rank mismatch(es).',
				$run['mismatches']['points'],
				$run['mismatches']['badges'],
				$run['mismatches']['ranks']
			)
		);
		if ( $total > 0 && ! empty( $run['sample'] ) ) {
			\WP_CLI::log( sprintf( 'First %d mismatch(es):', count( $run['sample'] ) ) );
			\WP_CLI\Utils\format_items( 'table', $run['sample'], array( 'user_id', 'kind', 'ours', 'source' ) );
		}
	}
}
