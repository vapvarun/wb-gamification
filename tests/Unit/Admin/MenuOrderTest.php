<?php
/**
 * The Gamification submenu follows the owner's workflow, not plugin load order.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Admin\SettingsPage;

#[CoversClass( SettingsPage::class )]
class MenuOrderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A submenu row.
	 *
	 * @param string $slug Page slug.
	 * @return array<int, string>
	 */
	private function row( string $slug ): array {
		return array( $slug . ' title', 'manage_options', $slug );
	}

	private function slugs( array $rows ): array {
		return array_column( $rows, 2 );
	}

	public function test_pages_come_out_in_workflow_order_whatever_order_they_registered_in(): void {
		$rows = array_map( array( $this, 'row' ), array( 'wb-gam-api-keys', 'wb-gamification-analytics', 'wb-gam-multipliers', 'wb-gamification', 'wb-gamification-import', 'wb-gamification-badges' ) );

		$this->assertSame(
			array( 'wb-gamification', 'wb-gamification-import', 'wb-gamification-badges', 'wb-gam-multipliers', 'wb-gamification-analytics', 'wb-gam-api-keys' ),
			$this->slugs( SettingsPage::sort_submenu( $rows ) )
		);
	}

	public function test_a_page_this_plugin_does_not_know_keeps_its_place_after_the_known_ones(): void {
		$rows = array_map( array( $this, 'row' ), array( 'x-first', 'wb-gam-api-keys', 'x-second', 'wb-gamification' ) );

		$this->assertSame(
			array( 'wb-gamification', 'wb-gam-api-keys', 'x-first', 'x-second' ),
			$this->slugs( SettingsPage::sort_submenu( $rows ) )
		);
	}

	public function test_the_first_entry_is_named_dashboard_and_nothing_else_is_renamed(): void {
		$sorted = SettingsPage::sort_submenu( array_map( array( $this, 'row' ), array( 'wb-gamification-badges', 'wb-gamification' ) ) );

		$this->assertSame( 'Dashboard', $sorted[0][0] );
		$this->assertSame( 'wb-gamification-badges title', $sorted[1][0] );
	}

	public function test_an_empty_or_partial_menu_is_left_alone(): void {
		$this->assertSame( array(), SettingsPage::sort_submenu( array() ) );
		$this->assertSame( array( $this->row( 'wb-gam-webhooks' ) ), SettingsPage::sort_submenu( array( $this->row( 'wb-gam-webhooks' ) ) ) );
	}
}
