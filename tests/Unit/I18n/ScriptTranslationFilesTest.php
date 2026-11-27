<?php
/**
 * WordPress loads a script's translations from `<domain>-<locale>-md5(<plugin-relative script path>).json`.
 * The editor scripts ship as build/Blocks/<slug>/index.js, so that is the path the file must be keyed to
 * (a file keyed to the src/ module is never requested and the block editor stays English).
 *
 * Needs no build output: the shipped path is derived from each block.json `editorScript`.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class ScriptTranslationFilesTest extends TestCase {

	private const LOCALE = 'de_DE';

	/**
	 * @return array<string, array{string}> Shipped plugin-relative script paths.
	 */
	public static function shippedScripts(): array {
		$root    = dirname( __DIR__, 3 );
		$scripts = array(
			'assets/js/toast-core.js'  => array( 'assets/js/toast-core.js' ),
			'assets/js/hub-convert.js' => array( 'assets/js/hub-convert.js' ),
		);
		foreach ( glob( $root . '/src/Blocks/*/block.json' ) as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true );
			$ed   = $meta['editorScript'] ?? '';
			if ( is_string( $ed ) && str_starts_with( $ed, 'file:./' ) ) {
				$path             = 'build/Blocks/' . basename( dirname( $file ) ) . '/' . substr( $ed, 7 );
				$scripts[ $path ] = array( $path );
			}
		}
		return $scripts;
	}

	#[DataProvider( 'shippedScripts' )]
	public function test_shipped_script_has_a_translation_file( string $path ): void {
		$file = dirname( __DIR__, 3 ) . '/languages/wb-gamification-' . self::LOCALE . '-' . md5( $path ) . '.json';
		$this->assertFileExists( $file, "No translation JSON keyed to the shipped script {$path}. Run bin/build-i18n-json.sh." );
		$data = json_decode( (string) file_get_contents( $file ), true );
		$this->assertSame( $path, $data['source'] ?? null );
		$this->assertNotEmpty( $data['locale_data']['messages'] ?? array() );
	}

	public function test_block_editor_scripts_were_found(): void {
		$this->assertGreaterThanOrEqual( 19, count( array_filter( array_keys( self::shippedScripts() ), static fn( $p ) => str_starts_with( $p, 'build/' ) ) ) );
	}
}
