<?php
/**
 * A point type's icon comes from the same row as its label, and never prints empty (1.6.8, card 10531056217).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Repository\PointTypeRepository;
use WBGam\Services\PointTypeService;

#[CoversClass( PointTypeService::class )]
class PointTypeIconTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_cache_get' )->justReturn( false );

		$rows = array(
			array( 'slug' => 'points', 'label' => 'Points', 'label_singular' => 'Point', 'icon' => 'flame', 'is_default' => 1, 'position' => 0 ),
			array( 'slug' => 'coins', 'label' => 'Coins', 'label_singular' => 'Coin', 'icon' => 'coins', 'is_default' => 0, 'position' => 1 ),
			// A name neither the Lucide font nor the SVG set has.
			array( 'slug' => 'gems', 'label' => 'Gems', 'label_singular' => 'Gem', 'icon' => 'zz-not-an-icon', 'is_default' => 0, 'position' => 2 ),
			array( 'slug' => 'bare', 'label' => 'Bare', 'label_singular' => 'Bare', 'icon' => null, 'is_default' => 0, 'position' => 3 ),
		);
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_all' )->setValue( null, $rows );
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_default' )->setValue( null, 'points' );
	}

	protected function tearDown(): void {
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_all' )->setValue( null, null );
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_default' )->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function each_type_gets_its_own_icon(): void {
		$service = new PointTypeService();
		$this->assertStringContainsString( 'icon-coins', $service->icon( 'coins' ) );
		$this->assertStringContainsString( 'icon-flame', $service->icon( 'points' ) );
		$this->assertStringContainsString( 'icon-flame', $service->icon( null ), 'No slug means the primary type.' );
	}

	#[Test]
	public function an_unknown_or_missing_icon_falls_back_to_a_glyph_instead_of_nothing(): void {
		$service = new PointTypeService();
		foreach ( array( 'gems', 'bare' ) as $slug ) {
			$html = $service->icon( $slug );
			$this->assertStringContainsString( '<svg', $html, $slug );
			$this->assertStringNotContainsString( 'icon-zz-not-an-icon', $html );
		}
	}

	#[Test]
	public function an_unknown_slug_shows_the_primary_types_icon(): void {
		$this->assertStringContainsString( 'icon-flame', ( new PointTypeService() )->icon( 'no-such-type' ) );
	}

	#[Test]
	public function the_icon_name_cannot_inject_markup(): void {
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_all' )->setValue(
			null,
			array( array( 'slug' => 'points', 'label' => 'Points', 'label_singular' => 'Point', 'icon' => 'x"><script>', 'is_default' => 1, 'position' => 0 ) )
		);
		$this->assertStringNotContainsString( '<script', ( new PointTypeService() )->icon( 'points' ) );
	}
}
