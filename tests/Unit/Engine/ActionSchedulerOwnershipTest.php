<?php
/**
 * Regression guard: the AS cleaner may only ever delete THIS plugin's rows.
 *
 * Action Scheduler is SHARED infrastructure. Its tables hold WooCommerce orders,
 * Subscriptions renewals, Jetpack sync jobs and every other plugin's queued work
 * alongside ours.
 *
 * Until 1.6.4 `ActionSchedulerCleaner::prune_status()` deleted by `status` +
 * `scheduled_date_gmt` with NO ownership filter, and `cleanup()` pruned `pending`
 * every single day. So a plugin whose job is to award points was, daily, deleting
 * WooCommerce's queue. On a busy store "pending and past-due" is exactly what a
 * backed-up queue looks like, and our answer to a backed-up queue was to destroy
 * the backlog.
 *
 * Two invariants, both enforced here against the source:
 *   1. Every DELETE-selection is fenced to `hook LIKE 'wb_gam_%'`.
 *   2. `pending` is never pruned as routine housekeeping -- only when the circuit
 *      breaker has tripped AND the runaway hook is ours. Retention horizons apply
 *      to records of what happened, never to instructions for what is still to
 *      happen. A pending job is an email not yet sent, a webhook not yet delivered.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \WBGam\Engine\ActionSchedulerCleaner
 */
class ActionSchedulerOwnershipTest extends TestCase {

	/**
	 * Read the cleaner's source once.
	 */
	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ActionSchedulerCleaner.php' );
	}

	/**
	 * The row-selection query MUST carry the ownership fence.
	 *
	 * @test
	 */
	public function delete_selection_is_fenced_to_our_own_hooks(): void {
		$src = $this->source();

		$this->assertMatchesRegularExpression(
			'/SELECT action_id FROM.*?hook LIKE %s/s',
			$src,
			'The AS cleaner must select only rows whose hook matches its own prefix. '
			. 'Without this fence it deletes WooCommerce\'s queue.'
		);

		$this->assertStringContainsString(
			"private const HOOK_PREFIX = 'wb_gam_';",
			$src,
			'The ownership fence constant must exist.'
		);
	}

	/**
	 * `pending` must NOT be pruned unconditionally. It is queued work, not history.
	 *
	 * @test
	 */
	public function pending_is_not_pruned_as_routine_housekeeping(): void {
		$src = $this->source();

		// The pre-1.6.4 shape: an unconditional pending prune in the results array.
		$this->assertDoesNotMatchRegularExpression(
			"/'pending'\s*=>\s*self::prune_status\(\s*'pending'/",
			$src,
			'`pending` must never be pruned unconditionally -- deleting a queued job '
			. 'destroys work that has not happened yet (an unsent email, an undelivered webhook).'
		);

		// It may only be reached behind the circuit breaker AND an ownership check.
		$this->assertMatchesRegularExpression(
			'/panic_mode\s*&&\s*self::runaway_hook_is_ours\(\)/',
			$src,
			'`pending` may only be pruned when the circuit breaker has tripped AND the '
			. 'runaway hook is ours. Another plugin\'s runaway is not ours to recover from.'
		);
	}

	/**
	 * A runaway caused by someone else must not license us to delete their queue.
	 *
	 * @test
	 */
	public function a_foreign_runaway_does_not_authorise_deleting_foreign_work(): void {
		$src = $this->source();

		$this->assertMatchesRegularExpression(
			'/function runaway_hook_is_ours\(\).*?strpos\(.*?self::HOOK_PREFIX/s',
			$src,
			'runaway_hook_is_ours() must test the dominant hook against our own prefix.'
		);
	}
}
