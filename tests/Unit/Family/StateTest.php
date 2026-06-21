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
		$reg = array(
			'members'  => array(
				'a' => array( 'slug_free' => 'a/a.php' ),
				'b' => array( 'slug_free' => 'b/b.php' ),
			),
			'outcomes' => array(
				'both'  => array( 'requires' => array( 'a', 'b' ) ),
				'empty' => array( 'requires' => array() ),
			),
		);

		Functions\when( 'get_plugins' )->justReturn( array( 'a/a.php' => array(), 'b/b.php' => array() ) );

		$active = array( 'a/a.php' );
		Functions\when( 'is_plugin_active' )->alias( static function ( $p ) use ( &$active ) {
			return in_array( $p, $active, true );
		} );

		// Only a active — both requires a+b, so false.
		$this->assertFalse( \Wbcom\Family\State::outcome_available( $reg, 'both' ) );

		// Now b active too — both satisfied, so true.
		$active[] = 'b/b.php';
		$this->assertTrue( \Wbcom\Family\State::outcome_available( $reg, 'both' ) );

		// Empty requires always returns false.
		$this->assertFalse( \Wbcom\Family\State::outcome_available( $reg, 'empty' ) );
	}
}
