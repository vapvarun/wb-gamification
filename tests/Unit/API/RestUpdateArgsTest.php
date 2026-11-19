<?php
/**
 * Update routes must not carry create defaults (smoke finding, 1.6.8).
 *
 * WordPress fills every unsent argument with its default. On an update route that turned a
 * title-only community challenge update into "reward 100, any action", and a URL-only webhook
 * update into "active again".
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\API;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\API\CommunityChallengesController;
use WBGam\API\WebhooksController;

class RestUpdateArgsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__ ) . '/Engine/Stubs/RestControllerStub.php';
		Monkey\Functions\stubs( array( 'sanitize_key' => static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function args( object $controller, string $method, bool $create ): array {
		$m = new \ReflectionMethod( $controller, $method );
		return $m->invoke( $controller, $create );
	}

	public function test_community_challenge_update_args_have_no_defaults(): void {
		$c = new CommunityChallengesController();

		$create = $this->args( $c, 'save_args', true );
		$this->assertSame( '*', $create['target_action']['default'] );
		$this->assertSame( 100, $create['bonus_points']['default'] );

		foreach ( $this->args( $c, 'save_args', false ) as $name => $arg ) {
			$this->assertArrayNotHasKey( 'default', $arg, "update arg {$name} must not default" );
		}
	}

	public function test_empty_or_star_action_means_any_action(): void {
		$sanitize = $this->args( new CommunityChallengesController(), 'save_args', true )['target_action']['sanitize_callback'];

		$this->assertSame( '*', $sanitize( '*' ) );
		$this->assertSame( '*', $sanitize( '' ) );
		$this->assertSame( 'wp_publish_post', $sanitize( 'wp_publish_post' ) );
	}

	public function test_webhook_update_args_do_not_reactivate(): void {
		$w = new WebhooksController();

		$this->assertTrue( $this->args( $w, 'webhook_args', true )['is_active']['default'] );
		$this->assertArrayNotHasKey( 'default', $this->args( $w, 'webhook_args', false )['is_active'] );
	}
}
