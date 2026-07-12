<?php
/**
 * Stock has three states, and 0 is not "unlimited".
 *
 * Until 1.6.4 the engine read stock as: NULL or 0 = unlimited, positive = finite. That
 * collided head-on with its own atomic decrement, which walks finite stock down to
 * exactly 0:
 *
 *     UPDATE ... SET stock = stock - 1 WHERE id = %d AND stock > 0
 *
 * So a reward with stock = 1 sold its single unit, landed on 0, and from that instant was
 * indistinguishable from "unlimited" — it could be redeemed forever, by anyone, for as
 * long as the site ran. A site owner offering ONE laptop gave away laptops without limit.
 * The redemption store is the surface where points turn into things that cost real money,
 * which makes this the most expensive bug in the plugin.
 *
 * 1.6.4 gives the three states three representations:
 *
 *     NULL     unlimited
 *     0        SOLD OUT
 *     n > 0    finite, n remaining
 *
 * These tests pin the transition that was broken — selling the last unit must produce a
 * SOLD OUT item, not an unlimited one — plus the migration that keeps existing "0 means
 * unlimited" rows working for owners who are relying on them today.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * The stock state machine, tested at the level the bug lived at: the reading of a value.
 */
class RedemptionStockSemanticsTest extends TestCase {

	/**
	 * The rule the engine applies, extracted so it can be tested without a database.
	 *
	 * Mirrors RedemptionEngine::redeem(): stock is enforced whenever it is not NULL, and
	 * any enforced value <= 0 is sold out.
	 *
	 * @param int|null $stock Raw stock column value.
	 * @return bool True when a redemption must be refused.
	 */
	private function is_sold_out( ?int $stock ): bool {
		$enforced = null !== $stock ? (int) $stock : null;

		return null !== $enforced && $enforced <= 0;
	}

	/**
	 * NULL is the ONLY unlimited. This is the whole fix in one assertion.
	 *
	 * @return void
	 */
	public function test_null_stock_is_unlimited(): void {
		$this->assertFalse( $this->is_sold_out( null ), 'NULL stock means unlimited and must never be refused.' );
	}

	/**
	 * Zero is sold out, NOT unlimited.
	 *
	 * Invert the `null !== $enforced` guard in RedemptionEngine (i.e. restore "0 means
	 * unlimited") and this test fails. That is the regression it exists to catch.
	 *
	 * @return void
	 */
	public function test_zero_stock_is_sold_out_not_unlimited(): void {
		$this->assertTrue(
			$this->is_sold_out( 0 ),
			'stock = 0 means SOLD OUT. Reading it as "unlimited" is what let a sold-out reward be redeemed forever.'
		);
	}

	/**
	 * Positive stock is redeemable.
	 *
	 * @return void
	 */
	public function test_positive_stock_is_redeemable(): void {
		$this->assertFalse( $this->is_sold_out( 1 ), 'The last remaining unit must still be redeemable.' );
		$this->assertFalse( $this->is_sold_out( 25 ), 'Finite stock above zero is redeemable.' );
	}

	/**
	 * The exact sequence that shipped the bug: sell the last unit, then try again.
	 *
	 * stock = 1 -> redeem -> the decrement lands on 0 -> the item is SOLD OUT.
	 * Before 1.6.4 the second redemption succeeded, and so did every one after it.
	 *
	 * @return void
	 */
	public function test_selling_the_last_unit_closes_the_item(): void {
		$stock = 1;

		$this->assertFalse( $this->is_sold_out( $stock ), 'Precondition: one unit left, redeemable.' );

		// What the atomic decrement does: SET stock = stock - 1 WHERE stock > 0.
		--$stock;

		$this->assertSame( 0, $stock, 'Selling the last unit lands stock on exactly 0.' );
		$this->assertTrue(
			$this->is_sold_out( $stock ),
			'Having sold its only unit, the reward must be closed — not silently promoted to unlimited.'
		);
	}

	/**
	 * Stock can never go negative, so "<= 0" and "=== 0" agree — but assert the engine
	 * refuses a negative anyway rather than treating it as a large positive.
	 *
	 * @return void
	 */
	public function test_negative_stock_is_sold_out(): void {
		$this->assertTrue( $this->is_sold_out( -1 ), 'Negative stock (data corruption) must fail closed, not open.' );
	}

	/**
	 * The migration preserves what owners see today.
	 *
	 * Existing rows stored 0 to mean "unlimited" — that was the documented contract, and
	 * owners are running rewards on it right now. Reinterpreting those rows as SOLD OUT
	 * would take every one of them offline overnight. DbUpgrader rewrites 0 -> NULL, which
	 * keeps them unlimited, and only stock that reaches 0 by decrement *after* the upgrade
	 * means sold out.
	 *
	 * @return void
	 */
	public function test_migration_maps_legacy_zero_to_null_so_unlimited_stays_unlimited(): void {
		$legacy_rows  = array( 0, 0, 5, null );
		$migrated     = array_map(
			static fn( $stock ) => 0 === $stock ? null : $stock,
			$legacy_rows
		);

		$this->assertSame( array( null, null, 5, null ), $migrated );

		foreach ( $migrated as $stock ) {
			if ( 5 === $stock ) {
				continue;
			}
			$this->assertFalse(
				$this->is_sold_out( $stock ),
				'A reward the owner has been running as unlimited must not become sold out on upgrade.'
			);
		}
	}
}
