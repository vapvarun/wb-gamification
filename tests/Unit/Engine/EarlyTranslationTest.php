<?php
/**
 * Nothing that runs on plugins_loaded may ask for a translation.
 *
 * WordPress 6.7+ logs "_load_textdomain_just_in_time was called incorrectly" when __() runs before
 * init. DbUpgrader::init and Installer::maybe_install both run at plugins_loaded, and both seed rows
 * that carry translated text. The smoke test caught it on a fresh install; these tests keep it out.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guards (the seeds need a database, so the contract is asserted on the code).
 */
final class EarlyTranslationTest extends TestCase {

	private function source( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/' . $file );
	}

	/**
	 * The default point type is seeded on plugins_loaded: every translated string there must sit
	 * behind the init check, with a plain English fallback.
	 */
	public function test_the_plugins_loaded_point_type_seed_only_translates_after_init(): void {
		$src = $this->source( 'DbUpgrader.php' );

		$this->assertMatchesRegularExpression( '/\$after_init\s*=\s*\(bool\)\s*did_action\(\s*\'init\'\s*\)/', $src );

		foreach ( preg_split( '/\R/', $src ) as $i => $line ) {
			if ( preg_match( '/\b(__|_x|_n|esc_html__|esc_attr__)\(/', $line ) && ! preg_match( '/^\s*(\/\/|\*)/', $line ) ) {
				$this->assertStringContainsString(
					'$after_init',
					$line . ( preg_split( '/\R/', $src )[ $i - 1 ] ?? '' ),
					'DbUpgrader line ' . ( $i + 1 ) . ' translates without the init guard: ' . trim( $line )
				);
			}
		}
	}

	/**
	 * Installer::install_tables_and_seeds() runs from maybe_install() at plugins_loaded@0. It must
	 * not translate anything itself: the translated seeds are deferred to init when init has not fired.
	 */
	public function test_the_installer_defers_translated_seeds_until_init(): void {
		$src = $this->source( 'Installer.php' );

		$this->assertSame( 1, preg_match( '/function install_tables_and_seeds\(\): bool \{(.*?)\n\t\}\n/s', $src, $m ) );
		$body = (string) preg_replace( '/^\s*\/\/.*$/m', '', $m[1] ); // Comments may name __().

		$this->assertDoesNotMatchRegularExpression( '/\b(__|_x|_n)\(/', $body, 'the install body must not translate' );
		$this->assertDoesNotMatchRegularExpression( '/seed_default_(levels|badges|point_types)\(/', $body, 'the seeds belong in seed_translated_defaults()' );
		$this->assertStringContainsString( "did_action( 'init' )", $body );
		$this->assertMatchesRegularExpression( "/add_action\(\s*'init',\s*array\(\s*__CLASS__,\s*'seed_translated_defaults'/", $body );
	}
}
