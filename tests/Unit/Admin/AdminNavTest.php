<?php
/**
 * AdminNav: one menu in six groups, built from what WordPress registered for this user.
 *
 * Replaces MenuOrderTest (1.6.5 MENU_ORDER sort), which this class supersedes.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\AdminNav;

#[CoversClass( AdminNav::class )]
class AdminNavTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Every page the plugin registers today, in a shuffled load order. */
	private function registered(): array {
		$slugs = array( 'wb-gam-api-keys', 'wb-gamification-analytics', 'wb-gam-multipliers', 'wb-gamification', 'wb-gamification-import', 'wb-gamification-badges', 'wb-gam-levels', 'wb-gam-challenges', 'wb-gam-redemption', 'wb-gam-point-types', 'wb-gam-conversions', 'wb-gamification-members', 'wb-gamification-award', 'wb-gamification-streaks', 'wb-gamification-kudos-moderation', 'wb-gam-submissions', 'wb-gam-webhooks' );
		return array_fill_keys( $slugs, 'manage_options' );
	}

	private function visible( array $registered, ?callable $can = null, ?callable $module_on = null ): array {
		return AdminNav::visible( AdminNav::groups(), $registered, $can ?? static fn() => true, $module_on ?? static fn() => true );
	}

	public function test_the_wordpress_menu_is_six_groups_whatever_order_pages_registered_in(): void {
		$rows = array();
		foreach ( $this->registered() as $slug => $cap ) {
			$rows[] = array( $slug . ' title', $cap, $slug );
		}
		$menu = AdminNav::build_submenu( $rows, $this->visible( $this->registered() ) );

		$this->assertSame( array( 'Overview', 'Earning', 'Rewards', 'Members', 'Experience', 'Tools' ), array_column( $menu, 0 ) );
		$this->assertSame( 'wb-gamification', $menu[0][2] );
		$this->assertSame( 'admin.php?page=wb-gamification#points', $menu[1][2], 'Earning opens Actions and points' );
		$this->assertSame( 'wb-gam-levels', $menu[2][2] );
	}

	public function test_every_screen_is_listed_exactly_once(): void {
		$targets = array();
		foreach ( AdminNav::groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				$targets[] = AdminNav::target( $item );
			}
		}
		$this->assertSame( array_unique( $targets ), $targets );
		foreach ( array_keys( $this->registered() ) as $slug ) {
			$this->assertNotNull( AdminNav::locate( $slug ), "$slug has a place in the menu" );
		}
	}

	public function test_a_page_another_plugin_adds_under_ours_keeps_its_place_after_the_groups(): void {
		$rows = array( array( 'Partner', 'manage_options', 'partner-page' ), array( 'Dashboard', 'manage_options', 'wb-gamification' ) );
		$menu = AdminNav::build_submenu( $rows, $this->visible( array( 'wb-gamification' => 'manage_options' ) ) );

		$this->assertSame( 'partner-page', end( $menu )[2] );
	}

	public function test_a_module_switched_off_hides_its_items_and_an_empty_group_disappears(): void {
		$registered = array( 'wb-gamification' => 'manage_options' );
		$groups     = $this->visible( $registered, null, static fn( string $m ) => 'kudos' !== $m );

		$keys = array_column( $groups['members']['items'] ?? array(), 'key' );
		$this->assertNotContains( 'kudos', $keys, 'kudos section hidden when the module is off' );
		$this->assertContains( 'access', $keys );
		$this->assertArrayNotHasKey( 'members', $this->visible( array( 'wb-gamification' => 'manage_options' ), static fn( string $cap ) => 'manage_options' !== $cap ) );
	}

	public function test_a_delegated_staff_user_sees_only_what_their_capability_opens(): void {
		$registered                           = $this->registered();
		$registered['wb-gamification-award']  = 'wb_gam_award_manual';
		$groups                               = $this->visible( $registered, static fn( string $cap ) => 'wb_gam_award_manual' === $cap );

		$this->assertSame( array( 'members' ), array_keys( $groups ) );
		$this->assertSame( array( 'award' ), array_column( $groups['members']['items'], 'key' ) );
	}

	public function test_hidden_screens_open_inside_their_item(): void {
		$this->assertSame( array( 'group' => 'rewards', 'item' => 'challenges' ), AdminNav::locate( 'wb-gam-community-challenges' ) );
		$this->assertSame( array( 'group' => 'overview', 'item' => 'dashboard' ), AdminNav::locate( 'wb-gamification' ) );
		$this->assertSame( array( 'group' => 'earning', 'item' => 'points' ), AdminNav::locate( 'wb-gamification', 'points' ) );
		$this->assertNull( AdminNav::locate( 'someone-elses-page' ) );
	}
}
