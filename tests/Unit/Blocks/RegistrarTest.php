<?php
/**
 * Unit tests for the build/blocks/ auto-registrar.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Blocks\Registrar;

require_once __DIR__ . '/Fixtures/WPBlockTypeRegistryStub.php';

/**
 * @coversDefaultClass \WBGam\Blocks\Registrar
 */
class RegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Temporary build directory created per-test.
	 *
	 * @var string
	 */
	private $tmp;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Registrar::reset();

		$this->tmp = sys_get_temp_dir() . '/wb-gam-registrar-' . uniqid();
		mkdir( $this->tmp . '/Blocks', 0755, true );

		Functions\stubs(
			array(
				'trailingslashit' => static fn ( $value ) => rtrim( (string) $value, '/' ) . '/',
			)
		);

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( true );

		\WP_Block_Type_Registry::get_instance()->_reset();
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->tmp );
		Registrar::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_build_dir_is_a_noop(): void {
		Functions\expect( 'register_block_type' )->never();

		( new Registrar( $this->tmp . '/missing' ) )->register_blocks();

		$this->assertTrue( true );
	}

	public function test_empty_build_dir_is_a_noop(): void {
		Functions\expect( 'register_block_type' )->never();

		( new Registrar( $this->tmp ) )->register_blocks();

		$this->assertTrue( true );
	}

	public function test_registers_each_block_once(): void {
		$this->seed_block( 'redemption-store' );
		$this->seed_block( 'leaderboard' );

		Functions\expect( 'register_block_type' )
			->twice()
			->with( \Mockery::on( static fn ( $arg ) => is_string( $arg ) ) );

		( new Registrar( $this->tmp ) )->register_blocks();
	}

	public function test_skips_blocks_already_in_wp_registry(): void {
		$this->seed_block( 'redemption-store' );
		$this->seed_block( 'leaderboard' );

		\WP_Block_Type_Registry::get_instance()->_add_for_test( 'wb-gamification/leaderboard' );

		Functions\expect( 'register_block_type' )->once();

		( new Registrar( $this->tmp ) )->register_blocks();
	}

	public function test_skips_re_registration_within_same_request(): void {
		$this->seed_block( 'redemption-store' );

		Functions\expect( 'register_block_type' )->once();

		$registrar = new Registrar( $this->tmp );
		$registrar->register_blocks();
		$registrar->register_blocks(); // second pass.
	}

	private function seed_block( string $slug ): void {
		$dir = $this->tmp . '/Blocks/' . $slug;
		mkdir( $dir, 0755, true );
		file_put_contents(
			$dir . '/block.json',
			(string) json_encode(
				array(
					'name'       => 'wb-gamification/' . $slug,
					'apiVersion' => 3,
				)
			)
		);
	}

	private function rrmdir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . '/' . $entry;
			is_dir( $full ) ? $this->rrmdir( $full ) : unlink( $full );
		}
		rmdir( $path );
	}
}
