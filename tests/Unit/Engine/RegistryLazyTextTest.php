<?php
/**
 * A manifest's words are resolved when read, not when the manifest loads.
 *
 * Manifests load before `init`. Translating there loads the text domain too early and fixes the
 * locale before a per-user language switch. So a manifest passes a closure and Registry resolves it
 * on read; a plain string from another plugin's registration is untouched.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Registry;

#[CoversClass( Registry::class )]
class RegistryLazyTextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function register( string $id, $label, $description = '' ): void {
		Registry::register_action(
			array(
				'id'             => $id,
				'label'          => $label,
				'description'    => $description,
				'hook'           => 'lazy_text_test_' . $id,
				'user_callback'  => static fn() => 1,
				'default_points' => 1,
			)
		);
	}

	public function test_a_closure_label_and_description_are_resolved_on_read(): void {
		$calls = 0;
		$this->register(
			'lazy_a',
			static function () use ( &$calls ) {
				++$calls;
				return 'Create a post';
			},
			static fn(): string => 'Awarded when a post is created.'
		);

		$this->assertSame( 0, $calls, 'Registering must not run the closure.' );
		$action = Registry::get_action( 'lazy_a' );
		$this->assertSame( 'Create a post', $action['label'] );
		$this->assertSame( 'Awarded when a post is created.', $action['description'] );
		$this->assertSame( 'Create a post', Registry::get_actions()['lazy_a']['label'], 'The list resolves it too.' );
		$this->assertSame( 'Create a post', Registry::label_for( 'lazy_a' ) );
	}

	public function test_a_plain_string_from_another_plugin_passes_through(): void {
		$this->register( 'lazy_b', 'Their label', 'Their words' );

		$this->assertSame( 'Their label', Registry::get_action( 'lazy_b' )['label'] );
		$this->assertSame( 'Their words', Registry::get_action( 'lazy_b' )['description'] );
	}

	public function test_the_translation_runs_at_read_time_so_it_follows_the_current_locale(): void {
		$locale = 'en';
		$this->register( 'lazy_c', static function () use ( &$locale ) { return 'label-' . $locale; } );

		$this->assertSame( 'label-en', Registry::get_action( 'lazy_c' )['label'] );
		$locale = 'fr';
		$this->assertSame( 'label-fr', Registry::get_action( 'lazy_c' )['label'] );
	}
}
