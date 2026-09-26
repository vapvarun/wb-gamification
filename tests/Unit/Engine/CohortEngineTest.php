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

	public function test_promotion_shares_default_to_what_the_settings_form_shows(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertSame( array( 'promote' => 0.2, 'demote' => 0.2 ), CohortEngine::promotion_shares() );
	}

	public function test_promotion_shares_follow_the_owner_settings_and_clamp(): void {
		Functions\when( 'get_option' )->justReturn( array( 'promote_pct' => 30, 'demote_pct' => 90 ) );
		$this->assertSame( array( 'promote' => 0.3, 'demote' => 0.5 ), CohortEngine::promotion_shares() );
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

	public function test_default_shares_split_a_cohort_of_30(): void {
		// 30 members x 20% = 6 promoted, 6 demoted, 18 stay.
		Functions\when( 'get_option' )->justReturn( false );
		$shares    = CohortEngine::promotion_shares();
		$count     = 30;
		$promote_n = (int) floor( $count * $shares['promote'] );
		$demote_n  = (int) floor( $count * $shares['demote'] );

		$this->assertSame( 6, $promote_n );
		$this->assertSame( 6, $demote_n );
		$this->assertSame( 18, $count - $promote_n - $demote_n );
	}
}
