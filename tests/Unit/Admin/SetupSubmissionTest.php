<?php
/**
 * Setup's submit: a rejected amount is shown, not saved (cards 10551804393, 10551804386), and an
 * answered Setup spends the activation redirect so Overview does not bounce back (card 10552140818).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use WBGam\Admin\SetupWizard;
use WBGam\Engine\Registry;

#[CoversNothing]
class SetupSubmissionTest extends TestCase {

	/** @var array */
	private array $saved_actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( fn( $args, $defaults = array() ) => array_merge( $defaults, $args ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );

		$actions             = ( new ReflectionClass( Registry::class ) )->getProperty( 'actions' );
		$this->saved_actions = $actions->getValue();
		$actions->setValue( null, array() );
		foreach ( array(
			'wp_publish_post'       => 'WordPress',
			'jetonomy_post_created' => 'Jetonomy',
		) as $id => $plugin ) {
			Registry::register_action(
				array(
					'id'             => $id,
					'label'          => $id,
					'plugin'         => $plugin,
					'hook'           => 'test_hook',
					'user_callback'  => static fn( $uid ) => (int) $uid,
					'default_points' => 5,
				)
			);
		}
		$this->reset_state();
	}

	protected function tearDown(): void {
		$_POST = array();
		$this->reset_state();
		( new ReflectionClass( Registry::class ) )->getProperty( 'actions' )->setValue( null, $this->saved_actions );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_state(): void {
		$ref = new ReflectionClass( SetupWizard::class );
		$ref->getProperty( 'invalid' )->setValue( null, array() );
		$ref->getProperty( 'posted' )->setValue( null, array() );
	}

	private function invalid( array $post ): array {
		return SetupWizard::invalid_points( Registry::get_actions(), $post );
	}

	#[Test]
	public function blank_and_out_of_range_amounts_are_invalid(): void {
		foreach ( array( '', '   ', '-5', '10000', '1.5', 'abc', '1e3' ) as $bad ) {
			$this->assertSame( array( 'wp_publish_post' ), $this->invalid( array( 'plugins' => array( 'WordPress' ), 'points' => array( 'wp_publish_post' => $bad ) ) ), var_export( $bad, true ) );
		}
		foreach ( array( '0', '25', '9999' ) as $ok ) {
			$this->assertSame( array(), $this->invalid( array( 'plugins' => array( 'WordPress' ), 'points' => array( 'wp_publish_post' => $ok ) ) ), $ok );
		}
	}

	#[Test]
	public function an_amount_in_a_plugin_switched_off_is_not_the_owners_problem(): void {
		$post = array( 'plugins' => array( 'WordPress' ), 'points' => array( 'wp_publish_post' => '3', 'jetonomy_post_created' => '' ) );
		$this->assertSame( array(), $this->invalid( $post ) );
		$post['plugins'][] = 'Jetonomy';
		$this->assertSame( array( 'jetonomy_post_created' ), $this->invalid( $post ) );
	}

	#[Test]
	public function a_rejected_launch_saves_nothing_and_leaves_setup_open(): void {
		$_POST = array( 'wbgam_setup' => 'launch', 'plugins' => array( 'WordPress' ), 'points' => array( 'wp_publish_post' => '' ) );
		Functions\when( 'update_option' )->alias( fn( $k ) => $this->fail( 'wrote ' . $k ) );
		Functions\when( 'delete_option' )->alias( fn( $k ) => $this->fail( 'deleted ' . $k ) );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => $this->fail( 'redirected' ) );

		SetupWizard::handle_submission();

		$this->assertSame( array( 'wp_publish_post' ), ( new ReflectionClass( SetupWizard::class ) )->getProperty( 'invalid' )->getValue() );
	}

	/** @return array<string, array{string}> */
	public static function answers(): array {
		return array(
			'launch' => array( 'launch' ),
			'skip'   => array( 'skip' ),
		);
	}

	#[Test]
	#[\PHPUnit\Framework\Attributes\DataProvider( 'answers' )]
	public function answering_setup_spends_the_activation_redirect( string $choice ): void {
		$_POST = array( 'wbgam_setup' => $choice, 'plugins' => array( 'WordPress' ), 'points' => array( 'wp_publish_post' => '12' ) );
		$deleted = array();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->alias(
			function ( $k ) use ( &$deleted ) {
				$deleted[] = $k;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new RuntimeException( 'redirected' );
			}
		);

		try {
			SetupWizard::handle_submission();
			$this->fail( 'expected a redirect' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertContains( SetupWizard::PENDING_REDIRECT_OPTION, $deleted, 'a left-behind flag sends Overview straight back to Setup' );
	}
}
