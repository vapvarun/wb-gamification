<?php
/**
 * Saving a reward keeps what the owner chose (1.6.8, cards 10483129430, 10483238276).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WBGam\API\RedemptionController;

#[CoversNothing]
class RedemptionItemWriteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once __DIR__ . '/Stubs/RestControllerStub.php';
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Call a private method of the controller.
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		$m = new ReflectionMethod( RedemptionController::class, $method );
		return $m->invoke( ( new \ReflectionClass( RedemptionController::class ) )->newInstanceWithoutConstructor(), ...$args );
	}

	/**
	 * A request carrying only these params.
	 *
	 * @param array $params Params.
	 * @return \ArrayAccess
	 */
	private function request( array $params ): \ArrayAccess {
		return new class( $params ) implements \ArrayAccess {
			public function __construct( private array $p ) {}
			public function offsetExists( $k ): bool {
				return isset( $this->p[ $k ] );
			}
			public function offsetGet( $k ): mixed {
				return $this->p[ $k ] ?? null;
			}
			public function offsetSet( $k, $v ): void {
				$this->p[ $k ] = $v;
			}
			public function offsetUnset( $k ): void {
				unset( $this->p[ $k ] );
			}
		};
	}

	#[Test]
	public function unlimited_clears_the_count_even_when_the_old_count_is_sent(): void {
		$this->assertNull( $this->call( 'stock_from', $this->request( array( 'stock_mode' => 'unlimited', 'stock' => 15 ) ) ) );
		$this->assertNull( $this->call( 'stock_from', $this->request( array( 'stock_mode' => 'unlimited' ) ) ) );
		$this->assertSame( 15, $this->call( 'stock_from', $this->request( array( 'stock_mode' => 'limited', 'stock' => 15 ) ) ) );
		$this->assertSame( 0, $this->call( 'stock_from', $this->request( array( 'stock' => 0 ) ) ), '0 stays sold out.' );
		$this->assertNull( $this->call( 'stock_from', $this->request( array( 'stock' => null ) ) ) );
	}

	#[Test]
	public function an_update_fills_no_field_the_caller_left_out(): void {
		$update = $this->call( 'item_args', false );
		foreach ( $update as $key => $arg ) {
			$this->assertArrayNotHasKey( 'default', $arg, "$key must not default on update." );
		}
		$this->assertSame( array( 'unlimited', 'limited' ), $update['stock_mode']['enum'] );

		$create = $this->call( 'item_args', true );
		$this->assertTrue( $create['is_active']['default'] );
	}
}
