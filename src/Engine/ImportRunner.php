<?php
/**
 * WB Gamification: runs a historical import in bounded, resumable background jobs.
 *
 * An importer (ImportSource) only READS the source, one keyset page at a time. This class owns
 * everything else: which source exists, the ordered phases, one page per job, the checkpoint written
 * after each page, the per-source lock, import mode around every page, and the progress the admin
 * screen, REST and WP-CLI all read.
 *
 * Why chained jobs and not one job per page: a 1M-row source is 2,000 pages, and enqueueing them all
 * up front leaves 2,000 pending actions in a shared queue. Each job runs ONE page and enqueues its
 * own successor, so there is at most one pending job per import (the pattern BadgeEngine::backfill_page
 * uses). Action Scheduler is used when present, WP-Cron otherwise; either way a page is the unit of
 * work, so a crash loses at most one page and resume() picks up from the last checkpoint.
 *
 * Idempotency is the safety net under all of it: every imported event carries a source_key, and
 * ImportService skips a row whose key already exists, so re-running a page (a retry, a resume after a
 * crash) can never double-award.
 *
 * Phases, in order: levels (rank tiers become levels), points (the ledger), awards (badges),
 * recompute (badges and levels derived from the imported history, once per member), reconcile
 * (compare what landed with the source's own numbers).
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Engine;

use WBGam\Integrations\Importers\BadgeOSImporter;
use WBGam\Integrations\Importers\GamiPressImporter;
use WBGam\Integrations\Importers\ImportSource;
use WBGam\Integrations\Importers\MyCredImporter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates paged, resumable imports.
 *
 * @package WB_Gamification
 */
final class ImportRunner {

	/**
	 * The chained job. Ours by prefix, so ActionSchedulerCleaner's ownership fence covers it.
	 */
	public const HOOK = 'wb_gam_import_chunk';

	/**
	 * Action Scheduler group.
	 */
	private const GROUP = 'wb-gamification';

	/**
	 * A running import that has not written a checkpoint for this long, with no job pending, is stalled.
	 */
	private const STALL_SECONDS = 120;

	/**
	 * Reconciliation mismatches kept as examples. The count is always exact; the samples are bounded.
	 */
	private const SAMPLE_MAX = 50;

	/**
	 * Forward phases in order, and how much of the progress bar each one is worth.
	 *
	 * @var array<string, int>
	 */
	private const WEIGHTS = array(
		'levels'    => 2,
		'points'    => 70,
		'awards'    => 13,
		'recompute' => 10,
		'reconcile' => 5,
	);

	/**
	 * The same, for an undo: levels are quick, events are almost all of it, badges are the tail.
	 *
	 * @var array<string, int>
	 */
	private const UNDO_WEIGHTS = array(
		'undo_levels' => 2,
		'undo_events' => 85,
		'undo_badges' => 13,
	);

	/**
	 * Register the chained job's handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'tick' ), 10, 3 );
	}

	/**
	 * The one registry of import sources: slug => label and importer class.
	 *
	 * REST, WP-CLI and the admin screen all read this, so adding a source is one edit here.
	 *
	 * @return array<string, array{label: string, class: class-string<ImportSource>}>
	 */
	public static function sources(): array {
		return array(
			'gamipress' => array(
				'label' => 'GamiPress',
				'class' => GamiPressImporter::class,
			),
			'mycred'    => array(
				'label' => 'myCred',
				'class' => MyCredImporter::class,
			),
			'badgeos'   => array(
				'label' => 'BadgeOS',
				'class' => BadgeOSImporter::class,
			),
		);
	}

	/**
	 * The importer class for a source slug.
	 *
	 * @param string $slug Source slug.
	 * @return class-string<ImportSource>|null
	 */
	public static function source_class( string $slug ): ?string {
		return self::sources()[ $slug ]['class'] ?? null;
	}

	/**
	 * Rows read per page for a phase. Filterable, and clamped so a bad value cannot make a page unbounded.
	 *
	 * @param string $phase Phase name.
	 * @return int
	 */
	public static function page_size( string $phase ): int {
		$defaults = array(
			'points'    => 500,
			'awards'    => 200,
			'recompute' => 100,
			'reconcile' => 200,
			'undo'      => 500,
		);
		/**
		 * Filter how many rows an import job processes per page.
		 *
		 * @since 1.6.5
		 *
		 * @param int    $size  Rows per page (clamped to 10-2000).
		 * @param string $phase points | awards | recompute | reconcile | undo.
		 */
		$size = (int) apply_filters( 'wb_gam_import_page_size', $defaults[ $phase ] ?? 200, $phase );
		return max( 10, min( 2000, $size ) );
	}

	/**
	 * The stored state of a source's latest run.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, mixed>
	 */
	public static function state( string $slug ): array {
		$stored = get_option( self::option_name( $slug ), array() );
		return array_merge( self::blank_state(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * What a run would do, without doing it: counts, what a previous run already left, a small sample.
	 *
	 * Deliberately cheap. The full per-member reconciliation runs AFTER the import as its own phase,
	 * where it can be paged; previewing it here would mean reading the whole source in one request.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function preview( string $slug ) {
		$class = self::checked_class( $slug );
		if ( $class instanceof WP_Error ) {
			return $class;
		}

		$points = $class::count_points();
		$awards = $class::count_awards();

		return array(
			'source'           => $slug,
			'dry_run'          => true,
			'points_rows'      => $points,
			'awards'           => $awards,
			'ranks'            => count( $class::read_ranks() ),
			'jobs'             => (int) ceil( $points / self::page_size( 'points' ) ) + (int) ceil( $awards / self::page_size( 'awards' ) ) + 3,
			'already_imported' => array(
				'events' => ImportLedger::count_events( $class::KEY_PREFIX ),
				'badges' => ImportLedger::count_badges( $class::BADGE_PREFIX ),
			),
			'sample'           => $class::read_points( 0, 5 )['rows'],
		);
	}

	/**
	 * Start an import in the background.
	 *
	 * @param string $slug     Source slug.
	 * @param bool   $schedule False to create the run without enqueueing a job (WP-CLI --sync drives it).
	 * @return array<string, mixed>|WP_Error The new run's state.
	 */
	public static function start( string $slug, bool $schedule = true ) {
		$class = self::checked_class( $slug );
		if ( $class instanceof WP_Error ) {
			return $class;
		}

		// Under a lock, so two clicks cannot both pass the "is one running?" check.
		return Lock::run(
			'import_start_' . $slug,
			static function () use ( $slug, $class, $schedule ) {
				$current = self::state( $slug );
				if ( self::is_active( $current ) && ! self::is_stalled( $slug, $current ) ) {
					return new WP_Error(
						'wb_gam_import_running',
						__( 'An import from this source is already running.', 'wb-gamification' ),
						array( 'status' => 409 )
					);
				}

				$state                      = self::blank_state();
				$state['run_id']            = wp_generate_uuid4();
				$state['status']            = 'queued';
				$state['phase']             = 'levels';
				$state['phase_total']       = count( $class::read_ranks() );
				$state['created_level_ids'] = $current['created_level_ids']; // Levels a previous run made stay undoable.
				$state['started_at']        = self::now();
				$state['updated_at']        = $state['started_at'];
				self::save( $slug, $state );

				if ( $schedule ) {
					self::schedule( $slug, $state['run_id'], $state['seq'] );
				}
				return $state;
			},
			new WP_Error(
				'wb_gam_import_busy',
				__( 'Another request is starting this import. Try again in a moment.', 'wb-gamification' ),
				array( 'status' => 409 )
			)
		);
	}

	/**
	 * Take a source's imported data back out, in the background.
	 *
	 * Refused while any run for the source is live, and when there is nothing to remove. It needs
	 * neither the source plugin nor a previous run's state: imported data is found by its key
	 * prefixes, so an import can be undone after its source was deactivated or state was lost.
	 *
	 * @param string $slug     Source slug.
	 * @param bool   $schedule False to create the run without enqueueing a job (WP-CLI --sync drives it).
	 * @return array<string, mixed>|WP_Error The new run's state.
	 */
	public static function start_undo( string $slug, bool $schedule = true ) {
		$class = ImportUndo::prefix_class( $slug );
		if ( $class instanceof WP_Error ) {
			return $class;
		}

		return Lock::run(
			'import_start_' . $slug,
			static function () use ( $slug, $schedule ) {
				$current = self::state( $slug );
				if ( self::is_active( $current ) && ! self::is_stalled( $slug, $current ) ) {
					return new WP_Error(
						'wb_gam_import_running',
						__( 'An import from this source is running. Wait for it to finish before undoing.', 'wb-gamification' ),
						array( 'status' => 409 )
					);
				}

				$preview = ImportUndo::preview( $slug );
				if ( is_wp_error( $preview ) ) {
					return $preview;
				}
				if ( 0 === $preview['events'] && 0 === $preview['badges'] && ! $preview['levels'] ) {
					return new WP_Error(
						'wb_gam_import_nothing_to_undo',
						__( 'Nothing imported from this source is left to remove.', 'wb-gamification' ),
						array( 'status' => 409 )
					);
				}

				$state                      = self::blank_state();
				$state['run_id']            = wp_generate_uuid4();
				$state['mode']              = 'undo';
				$state['status']            = 'queued';
				$state['phase']             = 'undo_levels';
				$state['phase_total']       = count( $preview['levels'] );
				$state['created_level_ids'] = $current['created_level_ids'];
				$state['started_at']        = self::now();
				$state['updated_at']        = $state['started_at'];
				self::save( $slug, $state );

				if ( $schedule ) {
					self::schedule( $slug, $state['run_id'], $state['seq'] );
				}
				return $state;
			},
			new WP_Error(
				'wb_gam_import_busy',
				__( 'Another request is starting this import. Try again in a moment.', 'wb-gamification' ),
				array( 'status' => 409 )
			)
		);
	}

	/**
	 * Continue a stalled or failed run from its last checkpoint.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resume( string $slug ) {
		if ( null === self::source_class( $slug ) ) {
			return new WP_Error( 'wb_gam_unknown_source', __( 'Unknown import source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}

		$state = self::state( $slug );
		if ( '' === $state['run_id'] || in_array( $state['status'], array( 'complete', 'undone', 'idle' ), true ) ) {
			return new WP_Error( 'wb_gam_import_nothing_to_resume', __( 'There is no unfinished import to resume.', 'wb-gamification' ), array( 'status' => 409 ) );
		}
		if ( self::is_active( $state ) && ! self::is_stalled( $slug, $state ) ) {
			return new WP_Error( 'wb_gam_import_running', __( 'This import is still running.', 'wb-gamification' ), array( 'status' => 409 ) );
		}

		$state['status']     = 'queued';
		$state['error']      = '';
		$state['updated_at'] = self::now();
		self::save( $slug, $state );
		self::schedule( $slug, $state['run_id'], $state['seq'] );

		return $state;
	}

	/**
	 * Progress for the admin screen, REST and WP-CLI.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, mixed> The state plus `percent`, `stalled` and the phase label.
	 */
	public static function progress( string $slug ): array {
		$state = self::state( $slug );

		return array_merge(
			$state,
			array(
				'source'  => $slug,
				'percent' => self::percent( $state ),
				'stalled' => self::is_stalled( $slug, $state ),
			)
		);
	}

	/**
	 * The chained job: run one page, then enqueue the next.
	 *
	 * @param string $slug   Source slug.
	 * @param string $run_id The run this job belongs to; a job from a superseded run does nothing.
	 * @param int    $seq    This job's place in the chain; a job that is not the current one does nothing.
	 * @return void
	 */
	public static function tick( string $slug, string $run_id, int $seq = 0 ): void {
		$state = self::advance( $slug, $run_id, $seq );

		if ( null !== $state && 'running' === $state['status'] ) {
			self::schedule( $slug, $run_id, $state['seq'] );
		}
	}

	/**
	 * Run ONE page of a run, under the source's lock and in import mode, and checkpoint it.
	 *
	 * Split from tick() so WP-CLI --sync can drive the same code without a queue.
	 *
	 * @param string   $slug   Source slug.
	 * @param string   $run_id Run id.
	 * @param int|null $seq    The job's place in the chain, or null when driven directly (WP-CLI --sync).
	 * @return array<string, mixed>|null The new state, or null when the job is stale or another job holds the lock
	 *                                   (that job chains its own successor).
	 */
	public static function advance( string $slug, string $run_id, ?int $seq = null ): ?array {
		$class = self::source_class( $slug );
		if ( null === $class ) {
			return null;
		}

		$result = Lock::run(
			'import_' . $slug,
			static function () use ( $slug, $class, $run_id, $seq ) {
				// Read the state INSIDE the lock: it is the only copy that cannot be stale.
				$state = self::state( $slug );
				if ( $state['run_id'] !== $run_id || ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
					return null;
				}
				// A duplicate job for a page that has already run must not run it again.
				if ( null !== $seq && (int) $state['seq'] !== $seq ) {
					return null;
				}

				$state['status'] = 'running';

				try {
					// A migration replays history: nothing may announce itself to a member.
					ImportMode::run(
						static function () use ( $class, &$state ): void {
							self::step( $class, $state );
						}
					);
				} catch ( \Throwable $e ) {
					$state['status'] = 'failed';
					$state['error']  = $e->getMessage();
					Log::error(
						'ImportRunner: a page failed and the run is paused at its last checkpoint.',
						array(
							'source' => $slug,
							'phase'  => $state['phase'],
							'cursor' => $state['cursor'],
							'error'  => $e->getMessage(),
						)
					);
				}

				++$state['seq'];
				$state['updated_at'] = self::now();
				self::save( $slug, $state );
				return $state;
			},
			null
		);

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Process one page of the current phase and move the state forward.
	 *
	 * @param string               $class Importer.
	 * @param array<string, mixed> $state Run state (updated in place).
	 * @return void
	 */
	private static function step( string $class, array &$state ): void {
		if ( 'undo' === $state['mode'] ) {
			ImportUndo::step( $class, $state );
			return;
		}

		$phase = (string) $state['phase'];

		switch ( $phase ) {
			case 'levels':
				foreach ( $class::read_ranks() as $rank ) {
					$created = false;
					$id      = LevelEngine::upsert_level( $rank['name'], $rank['min_points'], $rank['order'], '', $created );
					if ( $created && $id > 0 ) {
						$state['created_level_ids'][] = $id;
						++$state['totals']['levels_created'];
					}
				}
				$state['created_level_ids'] = array_values( array_unique( array_map( 'intval', (array) $state['created_level_ids'] ) ) );
				self::enter( $state, 'points', $class::count_points() );
				return;

			case 'points':
				$limit  = self::page_size( 'points' );
				$page   = $class::read_points( (int) $state['cursor'], $limit );
				$result = ImportService::ingest( $page['rows'], false );

				$state['totals']['imported']          += $result['imported'];
				$state['totals']['skipped_duplicate'] += $result['skipped_duplicate'];
				$state['totals']['failed']            += $result['failed'];
				$state['phase_done']                  += count( $page['rows'] );

				if ( 0 === $page['next'] ) {
					self::enter( $state, 'awards', $class::count_awards() );
				} else {
					$state['cursor'] = $page['next'];
				}
				return;

			case 'awards':
				$limit = self::page_size( 'awards' );
				$page  = $class::read_awards( (int) $state['cursor'], $limit );
				$seen  = array();

				foreach ( $page['rows'] as $record ) {
					if ( ! isset( $seen[ $record['badge_id'] ] ) ) {
						BadgeEngine::upsert_def(
							array(
								'id'        => $record['badge_id'],
								'name'      => $record['name'],
								'image_url' => $record['image'],
								'category'  => 'imported',
							)
						);
						$seen[ $record['badge_id'] ] = true;
					}
					$earned_at = ! empty( $record['earned_at'] ) ? (string) $record['earned_at'] : current_time( 'mysql', true );
					if ( BadgeEngine::award_badge( (int) $record['user_id'], (string) $record['badge_id'], $earned_at ) ) {
						++$state['totals']['badges_awarded'];
					}
				}
				$state['phase_done'] += count( $page['rows'] );

				if ( 0 === $page['next'] ) {
					self::enter( $state, 'recompute', self::member_estimate() );
				} else {
					$state['cursor'] = $page['next'];
				}
				return;

			case 'recompute':
				$limit = self::page_size( 'recompute' );
				$ids   = ImportLedger::touched_users( $class::KEY_PREFIX, (int) $state['cursor'], $limit );

				// Badges and levels derived from the imported history, once per member. Each member's
				// events are read once here, however many pages of points they were spread over.
				$state['totals']['badges_awarded'] += Engine::recompute_users( $ids );
				$state['phase_done']               += count( $ids );

				if ( count( $ids ) < $limit ) {
					self::enter( $state, 'reconcile', self::member_estimate() );
				} else {
					$state['cursor'] = (int) end( $ids );
				}
				return;

			case 'reconcile':
				$limit = self::page_size( 'reconcile' );
				$ids   = ImportLedger::touched_users( $class::KEY_PREFIX, (int) $state['cursor'], $limit );

				foreach ( $ids as $user_id ) {
					self::reconcile_member( $class, $user_id, $state );
				}
				$state['phase_done'] += count( $ids );

				if ( count( $ids ) < $limit ) {
					$state['phase']       = 'done';
					$state['status']      = 'complete';
					$state['finished_at'] = self::now();
				} else {
					$state['cursor'] = (int) end( $ids );
				}
				return;
		}

		// A phase this build does not know (a state written by a newer version): stop, do not loop.
		$state['status'] = 'failed';
		$state['error']  = sprintf( 'Unknown import phase "%s".', $phase );
	}

	/**
	 * Compare one member's imported data with the source's own numbers.
	 *
	 * The counts are exact; only the examples are bounded.
	 *
	 * @param string               $class   Importer.
	 * @param int                  $user_id Member.
	 * @param array<string, mixed> $state   Run state (mismatches updated in place).
	 * @return void
	 */
	private static function reconcile_member( string $class, int $user_id, array &$state ): void {
		$ours   = ImportLedger::points( $user_id, $class::KEY_PREFIX );
		$source = $class::source_balance( $user_id );
		if ( $ours !== $source ) {
			self::mismatch( $state, 'points', $user_id, $ours, $source );
		}

		$our_badges    = ImportLedger::member_badges( $user_id, $class::BADGE_PREFIX );
		$source_badges = $class::source_badge_count( $user_id );
		if ( $our_badges !== $source_badges ) {
			self::mismatch( $state, 'badges', $user_id, $our_badges, $source_badges );
		}

		$source_rank = $class::source_rank_name( $user_id );
		if ( '' !== $source_rank ) {
			$level     = LevelEngine::get_level_for_points( $ours );
			$our_level = (string) ( $level['name'] ?? '' );
			if ( $our_level !== $source_rank ) {
				self::mismatch( $state, 'ranks', $user_id, $our_level, $source_rank );
			}
		}
	}

	/**
	 * Record a mismatch: always count it, keep a bounded number of examples.
	 *
	 * @param array<string, mixed> $state   Run state.
	 * @param string               $kind    points | badges | ranks.
	 * @param int                  $user_id Member.
	 * @param int|string           $ours    Our value.
	 * @param int|string           $source  The source's value.
	 * @return void
	 */
	private static function mismatch( array &$state, string $kind, int $user_id, $ours, $source ): void {
		++$state['mismatches'][ $kind ];
		if ( count( $state['sample'] ) < self::SAMPLE_MAX ) {
			$state['sample'][] = array(
				'user_id' => $user_id,
				'kind'    => $kind,
				'ours'    => $ours,
				'source'  => $source,
			);
		}
	}

	/**
	 * Enter the next phase with a fresh cursor and counter.
	 *
	 * @param array<string, mixed> $state Run state.
	 * @param string               $phase Phase name.
	 * @param int                  $total Rows the phase will cover (0 if unknown).
	 * @return void
	 */
	private static function enter( array &$state, string $phase, int $total ): void {
		$state['phase']       = $phase;
		$state['cursor']      = 0;
		$state['phase_total'] = $total;
		$state['phase_done']  = 0;
	}

	/**
	 * An upper bound for "members to check": one indexed count of the totals table's primary key.
	 *
	 * @return int
	 */
	private static function member_estimate(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wb_gam_user_totals" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Overall progress, weighted by how much of a run each phase is.
	 *
	 * @param array<string, mixed> $state Run state.
	 * @return int 0-100.
	 */
	private static function percent( array $state ): int {
		if ( in_array( $state['status'], array( 'complete', 'undone' ), true ) || 'done' === $state['phase'] ) {
			return 100;
		}
		$weights = 'undo' === $state['mode'] ? self::UNDO_WEIGHTS : self::WEIGHTS;
		if ( ! isset( $weights[ $state['phase'] ] ) ) {
			return 0;
		}

		$percent = 0.0;
		foreach ( $weights as $phase => $weight ) {
			if ( $phase === $state['phase'] ) {
				$fraction = $state['phase_total'] > 0 ? min( 1, $state['phase_done'] / $state['phase_total'] ) : 0;
				$percent += $weight * $fraction;
				break;
			}
			$percent += $weight;
		}
		return (int) floor( $percent );
	}

	/**
	 * Is a run in a state that owns a job (queued or running)?
	 *
	 * @param array<string, mixed> $state Run state.
	 * @return bool
	 */
	private static function is_active( array $state ): bool {
		return in_array( $state['status'], array( 'queued', 'running' ), true );
	}

	/**
	 * Has a live run stopped making progress with nothing scheduled to move it?
	 *
	 * The queue can stop (WP-Cron disabled, loopback blocked, a fatal mid-page). The owner has to be
	 * able to see that and act on it, so this is a fact the API reports rather than something inferred
	 * by staring at a bar that no longer moves.
	 *
	 * @param string               $slug  Source slug.
	 * @param array<string, mixed> $state Run state.
	 * @return bool
	 */
	private static function is_stalled( string $slug, array $state ): bool {
		if ( ! self::is_active( $state ) || '' === $state['updated_at'] ) {
			return false;
		}
		if ( time() - (int) strtotime( $state['updated_at'] . ' UTC' ) < self::STALL_SECONDS ) {
			return false;
		}
		return ! self::has_pending_job( $slug, (string) $state['run_id'], (int) $state['seq'] );
	}

	/**
	 * Is the job for this page of this run waiting in the queue (or in WP-Cron)?
	 *
	 * The sequence number is part of the arguments on purpose. Action Scheduler counts an IN-PROGRESS
	 * action as scheduled, so a job whose successor had identical arguments would find itself, decide
	 * the next job was already queued, and end the chain after one page.
	 *
	 * @param string $slug   Source slug.
	 * @param string $run_id Run id.
	 * @param int    $seq    The page's place in the chain.
	 * @return bool
	 */
	private static function has_pending_job( string $slug, string $run_id, int $seq ): bool {
		$args = array( $slug, $run_id, $seq );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return true;
		}
		return (bool) wp_next_scheduled( self::HOOK, $args );
	}

	/**
	 * Enqueue the job for the next page of a run.
	 *
	 * @param string $slug   Source slug.
	 * @param string $run_id Run id.
	 * @param int    $seq    The page's place in the chain.
	 * @return void
	 */
	private static function schedule( string $slug, string $run_id, int $seq ): void {
		$args = array( $slug, $run_id, $seq );

		// The handler schedules its own successor, so it is guarded against enqueueing twice: an
		// overlapping run would walk the same page twice and the progress counters would lie.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			}
			return;
		}

		// No Action Scheduler: WP-Cron runs the same job. A page is still the unit of work.
		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, $args );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Resolve a source and check it has data, as a class name or a REST-ready error.
	 *
	 * @param string $slug Source slug.
	 * @return class-string<ImportSource>|WP_Error
	 */
	private static function checked_class( string $slug ) {
		$class = self::source_class( $slug );
		if ( null === $class ) {
			return new WP_Error( 'wb_gam_unknown_source', __( 'Unknown import source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		if ( '' === $class::KEY_PREFIX || '' === $class::BADGE_PREFIX ) {
			// A programming error, not a user error: refuse rather than let an empty prefix match everything.
			return new WP_Error( 'wb_gam_source_misconfigured', __( 'This import source is missing its key prefix.', 'wb-gamification' ), array( 'status' => 500 ) );
		}
		if ( ! $class::is_available() ) {
			return new WP_Error( 'wb_gam_source_unavailable', __( 'No data found for this source.', 'wb-gamification' ), array( 'status' => 400 ) );
		}
		return $class;
	}

	/**
	 * The option that holds a source's latest run (one row per source, never autoloaded).
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function option_name( string $slug ): string {
		return 'wb_gam_import_run_' . $slug;
	}

	/**
	 * Persist a run's state.
	 *
	 * @param string               $slug  Source slug.
	 * @param array<string, mixed> $state Run state.
	 * @return void
	 */
	private static function save( string $slug, array $state ): void {
		update_option( self::option_name( $slug ), $state, false );
	}

	/**
	 * A run that has not started.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank_state(): array {
		return array(
			'run_id'            => '',
			'seq'               => 0, // Pages run so far: the chain's position, and each job's identity.
			'mode'              => 'import', // import | undo: the same chain runs both.
			'status'            => 'idle', // idle | queued | running | complete | failed | undone.
			'phase'             => '',
			'cursor'            => 0,
			'phase_total'       => 0,
			'phase_done'        => 0,
			'totals'            => array(
				'imported'          => 0,
				'skipped_duplicate' => 0,
				'failed'            => 0,
				'badges_awarded'    => 0,
				'levels_created'    => 0,
			),
			'created_level_ids' => array(),
			'mismatches'        => array(
				'points' => 0,
				'badges' => 0,
				'ranks'  => 0,
			),
			'sample'            => array(),
			'undone'            => array(
				'events'        => 0,
				'points_rows'   => 0,
				'badges'        => 0,
				'levels'        => 0,
				'unrecoverable' => 0,
				'negative'      => array(),
			),
			'error'             => '',
			'started_at'        => '',
			'updated_at'        => '',
			'finished_at'       => '',
		);
	}

	/**
	 * Now, in UTC, as the database stores it.
	 *
	 * @return string
	 */
	private static function now(): string {
		return current_time( 'mysql', true );
	}
}
