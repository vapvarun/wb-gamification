<?php
/**
 * Members submit only what the owner opened to submissions (1.6.8, card 10459347341).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\Registry;
use WBGam\Repository\SubmissionRepository;
use WBGam\Services\SubmissionService;

#[CoversNothing]
class SubmittableTest extends TestCase {

	/**
	 * Registry actions before the test.
	 *
	 * @var array
	 */
	private array $saved = array();

	/**
	 * Owner overrides the test serves.
	 *
	 * @var array
	 */
	private array $overrides = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once __DIR__ . '/Stubs/WPErrorStub.php';
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->alias( fn( $name, $default_value = false ) => 'wb_gam_action_overrides' === $name ? $this->overrides : $default_value );

		$prop        = ( new ReflectionClass( Registry::class ) )->getProperty( 'actions' );
		$this->saved = $prop->getValue();
		$prop->setValue(
			null,
			array(
				'wp_publish_post'  => array( 'id' => 'wp_publish_post' ),
				'event_checked_in' => array(
					'id'          => 'event_checked_in',
					'submittable' => true,
				),
			)
		);
	}

	protected function tearDown(): void {
		( new ReflectionClass( Registry::class ) )->getProperty( 'actions' )->setValue( null, $this->saved );
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function nothing_is_submittable_until_opened(): void {
		$this->assertFalse( Registry::is_submittable( 'wp_publish_post' ) );
		$this->assertFalse( Registry::is_submittable( 'not_registered' ) );
		$this->assertTrue( Registry::is_submittable( 'event_checked_in' ), 'A manifest may open an action.' );
	}

	#[Test]
	public function the_owners_tick_wins_both_ways(): void {
		$this->overrides = array(
			'wp_publish_post'  => array( 'submittable' => true ),
			'event_checked_in' => array( 'submittable' => false ),
		);
		$this->assertTrue( Registry::is_submittable( 'wp_publish_post' ) );
		$this->assertFalse( Registry::is_submittable( 'event_checked_in' ) );
	}

	#[Test]
	public function submitting_a_closed_action_is_refused_with_403(): void {
		$repo    = ( new ReflectionClass( SubmissionRepository::class ) )->newInstanceWithoutConstructor();
		$service = new SubmissionService( $repo );

		$result = $service->submit( 7, 'wp_publish_post', 'I published a post.' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wb_gam_not_submittable', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
