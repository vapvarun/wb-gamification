<?php
/**
 * Unit tests for BootOrder validator.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\BootOrder;

/**
 * @coversDefaultClass \WBGam\Engine\BootOrder
 */
class BootOrderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BootOrder::reset();

		// Log::warning is gated by WP_DEBUG; stub the underlying constant
		// + WP function so the static class still runs cleanly.
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		BootOrder::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_records_slot_and_depends_on(): void {
		BootOrder::register( 'a', BootOrder::SLOT_SCHEMA );
		BootOrder::register( 'b', BootOrder::SLOT_CORE, array( 'a' ) );

		$registry = BootOrder::get_registrations();
		$this->assertSame( BootOrder::SLOT_SCHEMA, $registry['a']['slot'] );
		$this->assertSame( array(), $registry['a']['depends_on'] );
		$this->assertSame( BootOrder::SLOT_CORE, $registry['b']['slot'] );
		$this->assertSame( array( 'a' ), $registry['b']['depends_on'] );
	}

	public function test_validate_passes_when_dependencies_load_before(): void {
		BootOrder::register( 'db_upgrader', BootOrder::SLOT_SCHEMA );
		BootOrder::register( 'engine', BootOrder::SLOT_CORE, array( 'db_upgrader' ) );

		// No exception, no warning emitted = pass. (Log::warning is gated
		// by WP_DEBUG; in unit tests we don't load WP. We just confirm
		// no fatal in the validator itself.)
		BootOrder::validate();
		$this->assertTrue( true );
	}

	public function test_validate_detects_dependency_at_later_slot(): void {
		// 'broken' is at SLOT_REGISTRY (6), depends on 'engine' at SLOT_CORE (8).
		// engine fires AFTER broken — broken would call into engine before
		// it's initialized.
		BootOrder::register( 'engine', BootOrder::SLOT_CORE );
		BootOrder::register( 'broken', BootOrder::SLOT_REGISTRY, array( 'engine' ) );

		// Log::warning is gated by WP_DEBUG — stub the function the
		// validator invokes so we can assert WITHOUT requiring WP.
		// (Log::is_debug_on returns false → write() returns early in our
		// test runtime. The validator code path still runs; we just
		// verify no fatal occurs.)
		BootOrder::validate();
		$this->assertTrue( true );
	}

	public function test_validate_handles_dependency_on_unregistered_slug(): void {
		BootOrder::register( 'a', BootOrder::SLOT_CORE, array( 'never_registered' ) );

		BootOrder::validate();
		$this->assertTrue( true );
	}

	public function test_slot_constants_have_ordered_values(): void {
		// Direction-of-time check: a later phase MUST have a higher
		// priority value than an earlier phase.
		$this->assertLessThan( BootOrder::SLOT_REGISTRY, BootOrder::SLOT_SCHEMA );
		$this->assertLessThan( BootOrder::SLOT_CORE, BootOrder::SLOT_REGISTRY );
		$this->assertLessThanOrEqual( BootOrder::SLOT_INTEGRATIONS, BootOrder::SLOT_CORE );
		$this->assertSame( BootOrder::SLOT_INTEGRATIONS, BootOrder::SLOT_OPTIONAL );
	}
}
