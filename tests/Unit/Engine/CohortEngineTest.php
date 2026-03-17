<?php
/**
 * Unit tests for CohortEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\CohortEngine;

class CohortEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Tier constants ───────────────────────────────────────────────────────

	public function test_tiers_are_defined_in_order(): void {
		$tiers = CohortEngine::TIERS;
		$this->assertSame( [ 'Bronze', 'Silver', 'Gold', 'Diamond', 'Obsidian' ], $tiers );
	}

	public function test_cohort_size_is_30(): void {
		$this->assertSame( 30, CohortEngine::COHORT_SIZE );
	}

	public function test_promote_pct_and_demote_pct_sum_to_less_than_1(): void {
		$this->assertLessThan( 1.0, CohortEngine::PROMOTE_PCT + CohortEngine::DEMOTE_PCT );
	}

	// ── get_user_tier() ──────────────────────────────────────────────────────

	public function test_get_user_tier_returns_0_for_no_meta(): void {
		Functions\expect( 'get_user_meta' )
			->once()
			->with( 1, 'wb_gam_league_tier', true )
			->andReturn( '' );

		$tier = CohortEngine::get_user_tier( 1 );

		$this->assertSame( 0, $tier );
	}

	public function test_get_user_tier_clamps_to_valid_range(): void {
		Functions\expect( 'get_user_meta' )->once()->andReturn( 99 );
		$this->assertSame( 4, CohortEngine::get_user_tier( 1 ) );

		Functions\expect( 'get_user_meta' )->once()->andReturn( -5 );
		$this->assertSame( 0, CohortEngine::get_user_tier( 2 ) );
	}

	public function test_get_user_tier_returns_integer_from_meta(): void {
		Functions\expect( 'get_user_meta' )->once()->andReturn( '2' );
		$this->assertSame( 2, CohortEngine::get_user_tier( 3 ) );
	}

	// ── Promotion math (unit-level) ──────────────────────────────────────────

	public function test_promote_n_floors_correctly(): void {
		// 30 members × 0.33 = 9.9 → floor → 9
		$count     = 30;
		$promote_n = (int) floor( $count * CohortEngine::PROMOTE_PCT );
		$this->assertSame( 9, $promote_n );
	}

	public function test_demote_n_floors_correctly(): void {
		$count    = 30;
		$demote_n = (int) floor( $count * CohortEngine::DEMOTE_PCT );
		$this->assertSame( 9, $demote_n );
	}

	public function test_middle_band_members_stay(): void {
		// Members at index 9..20 (out of 30) should stay.
		$count     = 30;
		$promote_n = (int) floor( $count * CohortEngine::PROMOTE_PCT );
		$demote_n  = (int) floor( $count * CohortEngine::DEMOTE_PCT );

		$outcomes = [];
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $i < $promote_n ) {
				$outcomes[] = 'promoted';
			} elseif ( $i >= $count - $demote_n ) {
				$outcomes[] = 'demoted';
			} else {
				$outcomes[] = 'stayed';
			}
		}

		$this->assertSame( 9, count( array_filter( $outcomes, fn( $o ) => 'promoted' === $o ) ) );
		$this->assertSame( 9, count( array_filter( $outcomes, fn( $o ) => 'demoted' === $o ) ) );
		$this->assertSame( 12, count( array_filter( $outcomes, fn( $o ) => 'stayed' === $o ) ) );
	}
}
