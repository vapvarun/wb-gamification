<?php
/**
 * Unit tests for ManualAwardPage.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\ManualAwardPage;

class ManualAwardPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_registers_hooks(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_menu', \Mockery::type( 'array' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_post_wb_gam_manual_award', \Mockery::type( 'array' ) );

		ManualAwardPage::init();
	}

	public function test_normalize_points_clamps_to_range(): void {
		$this->assertSame( 10000, ManualAwardPage::normalize_points( 99999 ) );
		$this->assertSame( -10000, ManualAwardPage::normalize_points( -99999 ) );
		$this->assertSame( 0, ManualAwardPage::normalize_points( 0 ) );
		$this->assertSame( 500, ManualAwardPage::normalize_points( 500 ) );
	}
}
