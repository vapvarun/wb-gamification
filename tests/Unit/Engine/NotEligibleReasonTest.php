<?php
/**
 * An action that pays nobody says so plainly in diagnostics (1.6.8).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WBGam\Engine\Registry;

#[CoversNothing]
class NotEligibleReasonTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function a_trigger_that_pays_nobody_reports_not_eligible(): void {
		Actions\expectDone( 'wb_gam_award_skipped' )->once()->with( 0, 'evnm_rsvp_going', 'not_eligible', array() );

		( new ReflectionMethod( Registry::class, 'fire' ) )->invoke(
			null,
			array(
				'id'            => 'evnm_rsvp_going',
				'user_callback' => static fn(): int => 0, // A waitlisted RSVP.
			),
			array()
		);
		$this->addToAssertionCount( 1 );
	}
}
