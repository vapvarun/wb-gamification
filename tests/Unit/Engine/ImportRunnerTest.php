<?php
/**
 * The import runner's chain identity and progress arithmetic.
 *
 * The chain bug this pins: every job enqueues its successor behind an "is one already queued?" guard,
 * and Action Scheduler counts an IN-PROGRESS action as scheduled. When every job in a run had the same
 * arguments, each job found ITSELF, decided its successor was already queued, and ended the chain after
 * one page. The whole import stopped at 2% and nothing reported an error. The arguments now carry the
 * page's sequence number, so a job can never be mistaken for its successor.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ImportRunner;

#[CoversClass( ImportRunner::class )]
class ImportRunnerTest extends TestCase {

	/**
	 * Call a private static method.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		return ( new \ReflectionMethod( ImportRunner::class, $method ) )->invoke( null, ...$args );
	}

	/**
	 * A run state with the given phase and counters.
	 *
	 * @param string $phase  Phase.
	 * @param int    $done   Rows done in the phase.
	 * @param int    $total  Rows in the phase.
	 * @param string $status Status.
	 * @return array<string, mixed>
	 */
	private function state( string $phase, int $done = 0, int $total = 0, string $status = 'running' ): array {
		$blank = ( new \ReflectionMethod( ImportRunner::class, 'blank_state' ) )->invoke( null );
		return array_merge(
			$blank,
			array(
				'phase'       => $phase,
				'phase_done'  => $done,
				'phase_total' => $total,
				'status'      => $status,
			)
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function progress_cases(): array {
		$runner = new self( 'progress' );
		return array(
			'not started'              => array( $runner->state( 'levels', 0, 2, 'queued' ), 0 ),
			'points half way'          => array( $runner->state( 'points', 500, 1000 ), 37 ),
			'points finished'          => array( $runner->state( 'points', 1000, 1000 ), 72 ),
			'awards not begun'         => array( $runner->state( 'awards', 0, 40 ), 72 ),
			'reconcile half way'       => array( $runner->state( 'reconcile', 5, 10 ), 97 ),
			'an unknown total is zero' => array( $runner->state( 'points', 50, 0 ), 2 ),
			'overshoot is capped'      => array( $runner->state( 'points', 1500, 1000 ), 72 ),
			'complete'                 => array( $runner->state( 'done', 0, 0, 'complete' ), 100 ),
			'unknown phase'            => array( $runner->state( 'from-the-future', 3, 9 ), 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $state    Run state.
	 * @param int                  $expected Expected percent.
	 * @return void
	 */
	#[DataProvider( 'progress_cases' )]
	public function test_progress_is_weighted_by_phase_and_never_exceeds_the_bounds( array $state, int $expected ): void {
		$this->assertSame( $expected, $this->call( 'percent', $state ) );
	}

	/**
	 * Both the guard and the enqueue use the same three arguments, and the handler is registered to
	 * receive all three.
	 *
	 * @return void
	 */
	public function test_every_job_carries_its_sequence_number(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ImportRunner.php' );

		$this->assertSame( 2, substr_count( $src, '$args = array( $slug, $run_id, $seq );' ), 'The pending check and the enqueue must build identical arguments.' );
		$this->assertStringNotContainsString( '$args = array( $slug, $run_id );', $src, 'A two-argument job cannot be told from its successor.' );
		$this->assertStringContainsString( "array( __CLASS__, 'tick' ), 10, 3 )", $src, 'The handler must receive the sequence number.' );
		$this->assertStringContainsString( '++$state[\'seq\'];', $src, 'Each page must advance the sequence.' );
	}

	/**
	 * A duplicate job for a page that already ran must not run it again.
	 *
	 * @return void
	 */
	public function test_a_job_that_is_not_the_current_page_is_ignored(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ImportRunner.php' );
		$this->assertStringContainsString( '(int) $state[\'seq\'] !== $seq', $src );
	}

	/**
	 * The job's hook is ours by prefix, so the queue cleaner's ownership fence covers it and never
	 * touches another plugin's actions.
	 *
	 * @return void
	 */
	public function test_the_job_hook_is_owned_by_this_plugin(): void {
		$this->assertStringStartsWith( 'wb_gam_', ImportRunner::HOOK );
	}
}
