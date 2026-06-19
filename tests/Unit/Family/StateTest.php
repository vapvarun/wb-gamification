<?php
namespace WBGam\Tests\Unit\Family;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/libs/wbcom-family/bootstrap.php';

class StateTest extends TestCase {
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function member( string $free ): array { return array( 'slug_free' => $free ); }

	/** @test */
	public function resolves_active_inactive_and_missing(): void {
		Functions\when( 'get_plugins' )->justReturn(
			array( 'wb-gamification/wb-gamification.php' => array(), 'learnomy/learnomy.php' => array() )
		);
		Functions\when( 'is_plugin_active' )->alias(
			static fn( $p ) => 'wb-gamification/wb-gamification.php' === $p
		);
		$s = '\Wbcom\Family\State';
		$this->assertSame( 'active', $s::member_state( $this->member( 'wb-gamification/wb-gamification.php' ) ) );
		$this->assertSame( 'installed_inactive', $s::member_state( $this->member( 'learnomy/learnomy.php' ) ) );
		$this->assertSame( 'not_installed', $s::member_state( $this->member( 'jetonomy/jetonomy.php' ) ) );
	}

	/** @test */
	public function outcome_available_only_when_all_requires_active(): void {
		Functions\when( 'get_plugins' )->justReturn( array( 'wb-gamification/wb-gamification.php' => array() ) );
		Functions\when( 'is_plugin_active' )->justReturn( true );
		$registry = \Wbcom\Family\registry();
		$this->assertTrue( \Wbcom\Family\State::outcome_available( $registry, 'reward_engagement' ) );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		$this->assertFalse( \Wbcom\Family\State::outcome_available( $registry, 'reward_engagement' ) );
	}
}
