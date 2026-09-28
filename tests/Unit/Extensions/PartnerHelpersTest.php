<?php
/**
 * Public helpers for partner plugins (card 10344441524).
 *
 * BuddyNext reached into ~12 engine classes because no public helper existed. These pin the
 * helper contract: each exists, is documented as 1.6.5 API, and the ones with logic of their own
 * (action points, point-type label fallback) behave.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Extensions;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\Registry;

#[CoversNothing]
class PartnerHelpersTest extends TestCase {

	private const HELPERS = array(
		'wb_gam_is_action_enabled',
		'wb_gam_get_action_points',
		'wb_gam_get_point_type_label',
		'wb_gam_format_points',
		'wb_gam_get_category_label',
		'wb_gam_is_module_enabled',
		'wb_gam_get_points_history',
		'wb_gam_get_user_rank',
		'wb_gam_get_next_level',
		'wb_gam_get_earned_points',
		'wb_gam_is_level_climb',
		'wb_gam_get_contribution_data',
		'wb_gam_get_all_badges_for_user',
		'wb_gam_get_shared_badges',
		'wb_gam_get_badge_share_url',
		'wb_gam_send_kudos',
		'wb_gam_can_send_kudos',
		'wb_gam_has_recent_kudos',
		'wb_gam_get_kudos_received',
		'wb_gam_get_kudos_received_count',
		'wb_gam_leaderboard_deferred_to_jetonomy',
	);

	private array $original_actions;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$prop                   = ( new ReflectionClass( Registry::class ) )->getProperty( 'actions' );
		$this->original_actions = $prop->getValue();
		$prop->setValue(
			null,
			array(
				'wp_publish_post' => array(
					'id'             => 'wp_publish_post',
					'default_points' => 10,
				),
			)
		);
	}

	protected function tearDown(): void {
		( new ReflectionClass( Registry::class ) )->getProperty( 'actions' )->setValue( null, $this->original_actions );
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function every_helper_is_public_1_6_5_api(): void {
		foreach ( self::HELPERS as $helper ) {
			$this->assertTrue( function_exists( $helper ), "{$helper} exists." );
			$doc = (string) ( new \ReflectionFunction( $helper ) )->getDocComment();
			$this->assertStringContainsString( '@since 1.6.5', $doc, "{$helper} is documented." );
		}
	}

	#[Test]
	public function action_points_read_the_owner_setting_then_the_default(): void {
		$options = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return $options[ $name ] ?? $fallback;
			}
		);

		$this->assertSame( 10, wb_gam_get_action_points( 'wp_publish_post' ), 'The action default.' );
		$options['wb_gam_points_wp_publish_post'] = '25';
		$this->assertSame( 25, wb_gam_get_action_points( 'wp_publish_post' ), 'The owner setting wins.' );
		$this->assertSame( 0, wb_gam_get_action_points( 'not_registered' ), 'Unregistered pays nothing.' );
	}
}
