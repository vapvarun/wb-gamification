<?php
/**
 * An amount reads with the site's names for points, singular for exactly one (1.6.5).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Services\PointTypeService;
use WBGam\Tests\Unit\Support\ResetsPointTypeCache;

#[CoversClass( \WBGam\Services\PointTypeService::class )]
#[CoversMethod( \WBGam\Services\PointTypeService::class, 'format' )]
#[CoversMethod( \WBGam\Services\PointTypeService::class, 'name_for' )]
class PointTypeFormatTest extends TestCase {

	use ResetsPointTypeCache;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->resetPointTypeCache();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => number_format( (float) $n ) );
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key ) => array(
				'point_types_default' => 'points',
				'point_types_all'     => array(
					array( 'slug' => 'points', 'label' => 'Points', 'label_singular' => 'Point', 'is_default' => 1 ),
					array( 'slug' => 'karma', 'label' => 'Karma', 'label_singular' => '', 'is_default' => 0 ),
				),
			)[ $key ] ?? false
		);
	}

	protected function tearDown(): void {
		$this->resetPointTypeCache();
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function one_is_singular_everything_else_plural(): void {
		$types = new PointTypeService();

		$this->assertSame( '+1 Point', $types->format( 1, null, true ) );
		$this->assertSame( '+5 Points', $types->format( 5, null, true ) );
		$this->assertSame( '0 Points', $types->format( 0 ) );
		$this->assertSame( '-1 Point', $types->format( -1, null, true ), 'A debit of one is still one; no "+" on a negative.' );
		$this->assertSame( '1,200 Points', $types->format( 1200 ) );
	}

	#[Test]
	public function a_blank_singular_uses_the_name_for_every_amount(): void {
		$types = new PointTypeService();

		$this->assertSame( 'Karma', $types->name_for( 1, 'karma' ), 'Names like Karma or XP read the same for one.' );
		$this->assertSame( '1 Karma', $types->format( 1, 'karma' ) );
	}
}
