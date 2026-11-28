<?php
/**
 * The legacy REST toasts route words a points toast with the one signed formatter.
 *
 * A points payload with no message used to be worded '+%d' + name, so a take-back
 * of -2 read "+-2 Points" (and one point read "+1 Points").
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\API;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\API\MembersController;

#[CoversMethod( MembersController::class, 'get_toasts' )]
class ToastsFallbackFormatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__ ) . '/Engine/Stubs/RestControllerStub.php';
		Functions\when( 'get_option' )->justReturn( '1' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		if ( ! class_exists( 'WP_REST_Response' ) ) {
			eval( 'class WP_REST_Response { public function __construct( public $data = null ) {} public function get_data() { return $this->data; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- minimal test double for core's class.
		}
		Functions\when( 'rest_ensure_response' )->alias( static fn( $d ) => new \WP_REST_Response( $d ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:int,1:string}>
	 */
	public static function amounts(): array {
		return array(
			'award'       => array( 5, '+5 Points' ),
			'single'      => array( 1, '+1 Point' ),
			'take-back'   => array( -2, '-2 Points' ),
		);
	}

	#[Test]
	#[DataProvider( 'amounts' )]
	public function an_unworded_points_payload_uses_the_signed_formatter( int $points, string $expected ): void {
		$GLOBALS['wpdb'] = new class( $points ) {
			public string $prefix = 'wp_';
			public function __construct( private int $points ) {}
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
			public function get_row( $sql = null, $output = null ) {
				return null;
			}
			public function get_results( $sql, $output = null ) {
				if ( ! str_contains( (string) $sql, 'notifications_queue' ) ) {
					return array(); // Point-type lookups: none configured.
				}
				return array(
					array(
						'id'           => 3,
						'event_type'   => 'points',
						'payload_json' => json_encode( array( 'type' => 'points', 'points' => $this->points, 'message' => '' ) ),
					),
				);
			}
		};

		$controller = ( new \ReflectionClass( MembersController::class ) )->newInstanceWithoutConstructor();
		$response   = $controller->get_toasts( new \stdClass() )->get_data();

		$this->assertSame( $expected, $response[0]['message'] );
		$this->assertSame( 3, $response[0]['_id'], 'The queue row id is kept so the page can dedupe and acknowledge.' );
		$this->assertSame( 3, $response[0]['_ack'] );
	}
}
