<?php
/**
 * A point type mapped to an action is the one the award lands in (1.6.8, card 10531050167).
 *
 * v1.6.8 rendered the Currency field with the raw action id but looked it up with sanitize_key(),
 * so for an id like "MyPlugin/Do.Thing" the save never found the field, wrote nothing, and every
 * award fell back to the default type. The same mismatch hit the points and enabled fields.
 *
 * This drives the real save handler (SettingsPage::save_points_settings) and reads the result back
 * the way the engine does (Registry::resolve_action_point_type, then PointTypeService::resolve).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Admin\SettingsPage;
use WBGam\Engine\Registry;
use WBGam\Repository\PointTypeRepository;
use WBGam\Services\PointTypeService;

#[CoversNothing]
class ActionPointTypeMappingTest extends TestCase {

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/** @var array */
	private array $saved_actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->alias( fn( $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias( fn( $args, $defaults = array() ) => array_merge( $defaults, $args ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		// Registry actions and the point-type catalogue, both served from memory (no database).
		$actions             = ( new ReflectionClass( Registry::class ) )->getProperty( 'actions' );
		$this->saved_actions = $actions->getValue();
		$actions->setValue( null, array() );

		$rows = array(
			array( 'slug' => 'points', 'label' => 'Points', 'label_singular' => 'Point', 'icon' => 'star', 'is_default' => 1, 'position' => 0 ),
			array( 'slug' => 'coins', 'label' => 'Coins', 'label_singular' => 'Coin', 'icon' => 'coins', 'is_default' => 0, 'position' => 1 ),
		);
		$all  = ( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_all' );
		$all->setValue( null, $rows );
		$def  = ( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_default' );
		$def->setValue( null, 'points' );
	}

	protected function tearDown(): void {
		( new ReflectionClass( Registry::class ) )->getProperty( 'actions' )->setValue( null, $this->saved_actions );
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_all' )->setValue( null, null );
		( new ReflectionClass( PointTypeRepository::class ) )->getProperty( 'request_cache_default' )->setValue( null, null );
		unset( $_POST );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Action ids that sanitize_key() or PHP's POST key rewriting change.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unsafe_ids(): array {
		return array(
			'slash'     => array( 'myplugin/action_name' ),
			'uppercase' => array( 'MyPlugin_Action' ),
			'dot'       => array( 'myplugin.action' ),
			'mixed'     => array( 'MyPlugin/Do.Thing' ),
			'plain'     => array( 'wp_publish_post' ),
		);
	}

	/**
	 * Register an action, post the Points tab as a browser would, and run the real save handler.
	 *
	 * @param string $id Action id.
	 */
	private function save_with_currency( string $id, string $currency ): void {
		Registry::register_action(
			array(
				'id'             => $id,
				'label'          => 'Test',
				'hook'           => 'test_hook',
				'user_callback'  => static fn( $uid ) => (int) $uid,
				'default_points' => 5,
			)
		);

		// The names the rendered form posts. PHP rewrites "." and " " in a top-level POST key to "_".
		$_POST = array(
			$this->posted_name( 'wb_gam_points_', $id )     => '12',
			$this->posted_name( 'wb_gam_enabled_', $id )    => '1',
			$this->posted_name( 'wb_gam_point_type_', $id ) => $currency,
		);

		$save = ( new ReflectionClass( SettingsPage::class ) )->getMethod( 'save_points_settings' );
		$save->invoke( null );
	}

	/**
	 * The POST key the browser sends for a rendered field. Before 1.6.8 the form used the raw action id.
	 *
	 * @param string $prefix Field prefix.
	 * @param string $id     Action id.
	 */
	private function posted_name( string $prefix, string $id ): string {
		$name = method_exists( SettingsPage::class, 'field_name' ) ? SettingsPage::field_name( $prefix, $id ) : $prefix . $id;
		return strtr( $name, '. ', '__' );
	}

	#[Test]
	#[DataProvider( 'unsafe_ids' )]
	public function a_mapped_currency_is_the_one_the_engine_resolves( string $id ): void {
		$this->save_with_currency( $id, 'coins' );

		$this->assertSame( 'coins', $this->options[ 'wb_gam_point_type_' . $id ] ?? null, 'The mapping must be saved under the raw action id the engine reads.' );
		$this->assertSame( 12, (int) ( $this->options[ 'wb_gam_points_' . $id ] ?? 0 ), 'Points amount shares the same key bug.' );
		$this->assertSame( 1, (int) ( $this->options[ 'wb_gam_enabled_' . $id ] ?? 0 ), 'An enabled action must stay enabled after a save.' );

		$action = Registry::get_action( $id );
		$this->assertSame( 'coins', ( new PointTypeService() )->resolve( Registry::resolve_action_point_type( $action ) ?: null ) );
	}

	#[Test]
	public function choosing_the_default_type_again_clears_nothing_it_should_keep(): void {
		$this->save_with_currency( 'MyPlugin/Do.Thing', 'points' );
		$this->assertSame( 'points', ( new PointTypeService() )->resolve( Registry::resolve_action_point_type( Registry::get_action( 'MyPlugin/Do.Thing' ) ) ?: null ) );
	}

	#[Test]
	public function deleting_a_point_type_clears_the_mappings_that_pointed_at_it(): void {
		$this->save_with_currency( 'MyPlugin/Do.Thing', 'coins' );
		$this->options['wb_gam_point_type_other'] = 'points';

		Registry::clear_point_type_overrides( 'coins' );

		$this->assertArrayNotHasKey( 'wb_gam_point_type_MyPlugin/Do.Thing', $this->options );
		$this->assertSame( 'points', $this->options['wb_gam_point_type_other'], 'Mappings to other types stay.' );
	}
}
