<?php
/**
 * A toast / email number and point name read in the RECIPIENT's locale: the seeded default
 * "Points" / "Point" is translated, a name the owner typed is shown as typed, and the number is
 * grouped by the locale in force (RecipientLocale switches it) - 1.7.0 F3.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Services\PointTypeService;
use WBGam\Tests\Unit\Support\ResetsPointTypeCache;

#[CoversMethod( \WBGam\Services\PointTypeService::class, 'name_for' )]
#[CoversMethod( \WBGam\Services\PointTypeService::class, 'format' )]
class PointTypeLocaleTest extends TestCase {

	use ResetsPointTypeCache;

	/** @var string Locale "in force", the way switch_to_user_locale() would set it. */
	private string $locale = 'en_US';

	private const DE = array(
		'Points' => 'Punkte',
		'Point'  => 'Punkt',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->resetPointTypeCache();
		$this->locale = 'en_US';
		Functions\when( '__' )->alias( static fn( $s ) => $s );
		Functions\when( '_n' )->alias(
			function ( $one, $many, $n ) {
				$word = 1 === (int) $n ? $one : $many;
				return 'de_DE' === $this->locale ? ( self::DE[ $word ] ?? $word ) : $word;
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			fn( $n ) => 'de_DE' === $this->locale ? number_format( (float) $n, 0, ',', '.' ) : number_format( (float) $n )
		);
	}

	protected function tearDown(): void {
		$this->resetPointTypeCache();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function store( string $label, string $singular ): void {
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key ) => array(
				'point_types_default' => 'points',
				'point_types_all'     => array( array( 'slug' => 'points', 'label' => $label, 'label_singular' => $singular, 'is_default' => 1 ) ),
			)[ $key ] ?? false
		);
	}

	#[Test]
	public function the_untouched_seeded_default_is_read_in_the_recipients_locale(): void {
		$this->store( 'Points', 'Point' );
		$types = new PointTypeService();

		$this->locale = 'de_DE';
		$this->assertSame( '+6.000 Punkte', $types->format( 6000, null, true ) );
		$this->assertSame( '+1 Punkt', $types->format( 1, null, true ) );

		$this->locale = 'en_US';
		$this->assertSame( '+6,000 Points', $types->format( 6000, null, true ) );
		$this->assertSame( '+1 Point', $types->format( 1, null, true ) );
	}

	#[Test]
	#[DataProvider( 'ownerWording' )]
	public function a_name_the_owner_typed_is_never_translated( string $label, string $singular ): void {
		$this->store( $label, $singular );
		$this->locale = 'de_DE';

		$this->assertSame( $label, ( new PointTypeService() )->name_for( 5 ) );
	}

	public static function ownerWording(): array {
		return array(
			'renamed'               => array( 'Karma', '' ),
			'renamed with singular' => array( 'Coins', 'Coin' ),
			'plural kept, singular edited' => array( 'Points', 'Pt' ),
		);
	}
}
