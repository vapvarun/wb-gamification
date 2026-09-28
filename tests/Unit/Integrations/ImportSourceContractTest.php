<?php
/**
 * Every import source keeps the same promises, because the runner and undo trust them.
 *
 * Imported data is recognised ONLY by its key prefixes, and undo deletes by them. So a prefix that is
 * empty, shared, or a prefix of another source's would let one source's undo remove another's data.
 * These are the invariants, asserted for every registered source at once so a fourth importer cannot
 * ship without them.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Integrations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ImportRunner;
use WBGam\Integrations\Importers\ImportSource;

#[CoversClass( ImportRunner::class )]
class ImportSourceContractTest extends TestCase {

	/**
	 * Every registered source, as [slug, class].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function sources(): array {
		$out = array();
		foreach ( ImportRunner::sources() as $slug => $meta ) {
			$out[ $slug ] = array( $slug, $meta['class'] );
		}
		return $out;
	}

	/**
	 * @param string $slug  Source slug.
	 * @param string $class Importer class.
	 * @return void
	 */
	#[DataProvider( 'sources' )]
	public function test_a_source_implements_the_contract_and_defines_both_prefixes( string $slug, string $class ): void {
		$this->assertTrue( is_subclass_of( $class, ImportSource::class ), "{$slug} must implement ImportSource." );
		$this->assertNotSame( '', $class::KEY_PREFIX, "{$slug} must define KEY_PREFIX: an empty prefix matches every event." );
		$this->assertNotSame( '', $class::BADGE_PREFIX, "{$slug} must define BADGE_PREFIX." );
		$this->assertStringEndsWith( ':', $class::KEY_PREFIX, 'Event keys end with a colon so an id cannot run into the prefix.' );
		$this->assertStringEndsWith( '-', $class::BADGE_PREFIX, 'Badge ids end with a hyphen for the same reason.' );
		$this->assertStringStartsWith( $slug, $class::KEY_PREFIX, 'A prefix names its source.' );
	}

	/**
	 * Undo deletes with LIKE 'prefix%'. If one prefix starts with another, one source's undo would also
	 * delete the other's data.
	 *
	 * @return void
	 */
	public function test_no_prefix_is_shared_or_is_a_prefix_of_another(): void {
		$prefixes = array();
		foreach ( ImportRunner::sources() as $slug => $meta ) {
			$prefixes[ "{$slug} events" ] = $meta['class']::KEY_PREFIX;
			$prefixes[ "{$slug} badges" ] = $meta['class']::BADGE_PREFIX;
		}

		foreach ( $prefixes as $a_label => $a ) {
			foreach ( $prefixes as $b_label => $b ) {
				if ( $a_label === $b_label ) {
					continue;
				}
				$this->assertFalse( str_starts_with( $a, $b ), "{$a_label} ({$a}) starts with {$b_label} ({$b}): an undo would cross sources." );
			}
		}
		$this->assertCount( count( $prefixes ), array_unique( $prefixes ), 'Two sources share a prefix.' );
	}

	/**
	 * Paging is by keyset. OFFSET re-reads every row it skips, so a reader that uses it gets slower with
	 * every page and cannot finish a large source.
	 *
	 * @param string $slug  Source slug.
	 * @param string $class Importer class.
	 * @return void
	 */
	#[DataProvider( 'sources' )]
	public function test_no_reader_pages_with_offset( string $slug, string $class ): void {
		$file = ( new \ReflectionClass( $class ) )->getFileName();
		$src  = (string) file_get_contents( (string) $file );

		$this->assertStringNotContainsStringIgnoringCase( ' OFFSET ', $src, "{$slug} reader uses OFFSET; page by keyset." );
		$this->assertDoesNotMatchRegularExpression( '/function\s+run\s*\(/', $src, "{$slug} still exposes the old run(); the runner owns the write path." );
	}

	/**
	 * The runner registry is the ONE list of sources: REST, WP-CLI and the admin screen read it.
	 *
	 * @return void
	 */
	public function test_rest_and_cli_do_not_keep_their_own_source_lists(): void {
		$root = dirname( __DIR__, 3 );
		foreach ( array( '/src/API/ImportController.php', '/src/CLI/ImportCommand.php' ) as $rel ) {
			$src = (string) file_get_contents( $root . $rel );
			$this->assertStringNotContainsString( 'GamiPressImporter::class', $src, "{$rel} keeps its own source list; read ImportRunner::sources()." );
			$this->assertStringNotContainsString( 'MyCredImporter::class', $src );
			$this->assertStringNotContainsString( 'BadgeOSImporter::class', $src );
		}
	}
}
