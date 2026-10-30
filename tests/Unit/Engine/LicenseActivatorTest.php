<?php
/**
 * Regression tests for preset licence activation backoff (1.6.8, Basecamp 10375331641).
 *
 * Until 1.6.8 a failed activation retried on every admin_init, Heartbeat
 * included, with no backoff: one site sent 933 POSTs to the store in a day.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LicenseActivator;

defined( 'WB_GAM_LICENSE_PRESET_KEY' ) || define( 'WB_GAM_LICENSE_PRESET_KEY', 'preset' );
defined( 'WB_GAM_EDD_ITEM_ID' ) || define( 'WB_GAM_EDD_ITEM_ID', 1 );
defined( 'WB_GAM_EDD_STORE_URL' ) || define( 'WB_GAM_EDD_STORE_URL', 'https://store.test' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

#[CoversClass( LicenseActivator::class )]
class LicenseActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Options and transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	/**
	 * POSTs sent to the store.
	 */
	private int $posts = 0;

	/**
	 * What the store answers: a WP_Error stand-in (null) or a JSON body.
	 */
	private ?string $reply = null;

	private bool $ajax = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array();
		$this->posts = 0;
		$this->reply = null;
		$this->ajax  = false;

		$get = fn( $name ) => $this->store[ $name ] ?? false;
		$set = function ( $name, $value ) {
			$this->store[ $name ] = $value;
			return true;
		};
		Functions\when( 'get_option' )->alias( $get );
		Functions\when( 'update_option' )->alias( $set );
		Functions\when( 'get_transient' )->alias( $get );
		Functions\when( 'set_transient' )->alias( $set );
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_doing_ajax' )->alias( fn() => $this->ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://site.test' );
		Functions\when( 'wp_remote_post' )->alias(
			function () {
				++$this->posts;
				return null === $this->reply ? 'error' : array( 'body' => $this->reply );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $r ) => 'error' === $r );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function a_failed_attempt_waits_a_day_before_retrying(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			LicenseActivator::activate();
		}
		$this->assertSame( 1, $this->posts );
		$this->assertSame( 'preset', $this->store['wb_gam_license_key'], 'The key is stored even when the POST fails.' );
	}

	#[Test]
	public function heartbeat_and_other_ajax_never_post(): void {
		$this->ajax = true;
		LicenseActivator::activate();
		$this->assertSame( 0, $this->posts );
	}

	#[Test]
	public function a_final_reply_stops_retries_for_good_and_does_not_enable_tracking(): void {
		$this->reply = '{"license":"invalid"}';
		LicenseActivator::activate();
		unset( $this->store['wb_gam_preset_activation_lock'] ); // Even with the day lock gone.
		LicenseActivator::activate();

		$this->assertSame( 1, $this->posts );
		$this->assertSame( 'invalid', $this->store['wb_gam_preset_activated'] );
		$this->assertArrayNotHasKey( 'wb_gam_license_key_allow_tracking', $this->store );
	}
}
