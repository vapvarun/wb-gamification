<?php
/**
 * Setup's Launch (SetupWizard::plan()): what the four steps save. Card 10549519268.
 *
 * Nothing is saved before Launch, and Launch writes only options that already exist. These
 * tests drive the pure planner with registered actions and a submitted form.
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
use WBGam\Admin\SetupWizard;
use WBGam\Engine\Registry;

#[CoversNothing]
class SetupPlanTest extends TestCase {

	/** @var array */
	private array $saved_actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( fn( $args, $defaults = array() ) => array_merge( $defaults, $args ) );

		$actions             = ( new ReflectionClass( Registry::class ) )->getProperty( 'actions' );
		$this->saved_actions = $actions->getValue();
		$actions->setValue( null, array() );

		$this->action( 'wp_publish_post', 'WordPress', 25 );
		$this->action( 'jetonomy_post_created', 'Jetonomy', 2, array( 'daily_cap' => 5 ) );
		$this->action( 'jetonomy_reply_created', 'Jetonomy', 1, array( 'daily_cap' => 20, 'weekly_cap' => 0, 'cooldown' => 30 ) );
		$this->action( 'mv/Upload.Photo', 'WPMediaVerse', 10, array( 'weekly_cap' => 3 ) );
	}

	protected function tearDown(): void {
		( new ReflectionClass( Registry::class ) )->getProperty( 'actions' )->setValue( null, $this->saved_actions );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function action( string $id, string $plugin, int $points, array $extra = array() ): void {
		Registry::register_action(
			$extra + array(
				'id'             => $id,
				'label'          => $id,
				'plugin'         => $plugin,
				'hook'           => 'test_hook',
				'user_callback'  => static fn( $uid ) => (int) $uid,
				'default_points' => $points,
			)
		);
	}

	private function plan( array $post, array $overrides = array(), bool $jetonomy = false ): array {
		$all = array( 'wp_publish_post', 'jetonomy_post_created', 'jetonomy_reply_created', 'mv/Upload.Photo' );
		$post += array( 'on' => array_fill_keys( $all, '1' ) );
		return SetupWizard::plan( Registry::get_actions(), $post, $overrides, array( 'jetonomy' => $jetonomy, 'host_privacy' => false ) );
	}

	#[Test]
	public function a_plugin_switched_off_earns_nothing_and_wordpress_basics_stay(): void {
		$out = $this->plan( array( 'plugins' => array( 'WordPress', 'MediaVerse' ) ) );

		$this->assertSame( 0, $out['wb_gam_enabled_jetonomy_post_created'], 'Jetonomy was switched off in step 1.' );
		$this->assertSame( 0, $out['wb_gam_enabled_jetonomy_reply_created'] );
		$this->assertSame( 1, $out['wb_gam_enabled_mv/Upload.Photo'], 'Ids with "/", "." and capitals keep their own option.' );
		$this->assertSame( 1, $out['wb_gam_enabled_wp_publish_post'] );
	}

	#[Test]
	public function amounts_are_whole_numbers_up_to_the_maximum(): void {
		$out = $this->plan(
			array(
				'plugins' => array( 'Jetonomy' ),
				'points'  => array( 'wp_publish_post' => '40', 'jetonomy_post_created' => '99999', 'jetonomy_reply_created' => '-5' ),
			)
		);
		$this->assertSame( 40, $out['wb_gam_points_wp_publish_post'] );
		$this->assertSame( Registry::POINTS_MAX, $out['wb_gam_points_jetonomy_post_created'] );
		$this->assertArrayNotHasKey( 'wb_gam_points_jetonomy_reply_created', $out, 'Not a whole number: left as it was.' );
	}

	#[Test]
	public function pace_scales_each_plugins_own_limits_and_keeps_other_overrides(): void {
		$current = array( 'jetonomy_reply_created' => array( 'cooldown' => 60, 'submittable' => true, 'daily_cap' => 7 ) );

		$relaxed = $this->plan( array( 'plugins' => array( 'Jetonomy', 'MediaVerse' ), 'pace' => 'relaxed' ), $current )['wb_gam_action_overrides'];
		$this->assertSame( 3, $relaxed['jetonomy_post_created']['daily_cap'], 'Half of 5, rounded up.' );
		$this->assertSame( 10, $relaxed['jetonomy_reply_created']['daily_cap'] );
		$this->assertSame( 60, $relaxed['jetonomy_reply_created']['cooldown'], 'Cooldown is not part of the pace.' );
		$this->assertTrue( $relaxed['jetonomy_reply_created']['submittable'] );
		$this->assertSame( 2, $relaxed['mv/Upload.Photo']['weekly_cap'] );
		$this->assertArrayNotHasKey( 'wp_publish_post', $relaxed, 'No limit stays no limit.' );

		$competitive = $this->plan( array( 'plugins' => array( 'Jetonomy' ), 'pace' => 'competitive' ) )['wb_gam_action_overrides'];
		$this->assertSame( 10, $competitive['jetonomy_post_created']['daily_cap'] );

		$balanced = $this->plan( array( 'plugins' => array( 'Jetonomy' ), 'pace' => 'balanced' ), $current )['wb_gam_action_overrides'];
		$this->assertArrayNotHasKey( 'daily_cap', $balanced['jetonomy_reply_created'], 'Balanced restores the plugin\'s own limit.' );
		$this->assertSame( 60, $balanced['jetonomy_reply_created']['cooldown'] );
	}

	#[Test]
	public function running_setup_again_can_keep_the_owners_limits(): void {
		$out = $this->plan( array( 'plugins' => array( 'Jetonomy' ), 'pace' => 'keep' ), array( 'jetonomy_post_created' => array( 'daily_cap' => 9 ) ) );
		$this->assertArrayNotHasKey( 'wb_gam_action_overrides', $out );
	}

	#[Test]
	public function emails_and_leaderboard_follow_the_form(): void {
		$out = $this->plan( array( 'plugins' => array(), 'email_weekly' => '1', 'leaderboard' => 'jetonomy' ) );
		$this->assertSame( '0', $out['wb_gam_email_level_up'] );
		$this->assertSame( '0', $out['wb_gam_email_badge_earned'] );
		$this->assertSame( 1, $out['wb_gam_weekly_email_enabled'] );
		$this->assertArrayNotHasKey( 'wb_gam_leaderboard_authority', $out, 'Only offered when Jetonomy is active.' );

		$with_jt = $this->plan( array( 'plugins' => array(), 'leaderboard' => 'jetonomy' ), array(), true );
		$this->assertSame( 'jetonomy', $with_jt['wb_gam_leaderboard_authority'] );
	}
}
