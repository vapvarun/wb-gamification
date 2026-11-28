<?php
/**
 * Unregistered events (kudos, streak and challenge bonuses) must respect the earning exclusion.
 *
 * Before 1.7.1 Engine::process asked user_can_earn only for registered actions, so a suspended
 * or excluded member still earned from receive_kudos. Admin grants and spends stay exempt.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Engine;
use WBGam\Engine\Event;

class UnregisteredEventExclusionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value ) => 'wb_gam_user_can_earn' === $tag ? false : $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function earning_events(): array {
		return array(
			'kudos received'   => array( 'receive_kudos' ),
			'kudos given'      => array( 'give_kudos' ),
			'streak milestone' => array( 'streak_milestone' ),
		);
	}

	#[Test]
	#[\PHPUnit\Framework\Attributes\DataProvider( 'earning_events' )]
	public function an_excluded_member_earns_nothing_from_an_unregistered_event( string $action_id ): void {
		$skipped = array();
		Monkey\Actions\expectDone( 'wb_gam_award_skipped' )->once()->whenHappen(
			static function ( $user, $action, $reason ) use ( &$skipped ) {
				$skipped = array( $user, $action, $reason );
			}
		);

		$result = Engine::process(
			new Event(
				array(
					'action_id' => $action_id,
					'user_id'   => 42,
					'object_id' => 7,
					'metadata'  => array( 'points' => 5 ),
				)
			)
		);

		$this->assertFalse( $result );
		$this->assertSame( array( 42, $action_id, 'excluded' ), $skipped );
	}
}
