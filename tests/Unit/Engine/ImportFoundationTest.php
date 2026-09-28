<?php
/**
 * The shared foundation under every import: pruning, prefix matching, the ingest flag.
 *
 * No database: a small fake $wpdb records the SQL the code under test would have run.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ImportLedger;
use WBGam\Engine\ImportService;
use WBGam\Engine\LogPruner;

#[CoversClass( LogPruner::class )]
#[CoversClass( ImportLedger::class )]
#[CoversClass( ImportService::class )]
class ImportFoundationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * @var object The fake $wpdb.
	 */
	private object $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$this->db = new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var string[] */
			public array $queries = array();

			public function prepare( string $sql, ...$args ): string {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args );
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 0; // Fewer rows than the batch: the loop ends after one statement.
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}
		};
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Imported history is the idempotency anchor and the undo handle: the events prune must skip any
	 * event that carries a source_key, and must still prune organic ones.
	 *
	 * @return void
	 */
	public function test_the_events_prune_keeps_imported_events_and_still_prunes_organic_ones(): void {
		LogPruner::prune();

		$events = array_values( array_filter( $this->db->queries, static fn( $q ) => str_contains( $q, 'wb_gam_events' ) ) );
		$points = array_values( array_filter( $this->db->queries, static fn( $q ) => str_contains( $q, 'wb_gam_points' ) ) );

		$this->assertCount( 1, $events, 'One events DELETE per tick.' );
		$this->assertStringContainsString( 'source_key IS NULL', $events[0], 'Imported events (keyed) must survive the prune.' );
		$this->assertStringContainsString( 'created_at <', $events[0], 'Organic events are still pruned by age.' );

		$this->assertCount( 1, $points );
		$this->assertStringNotContainsString( 'source_key', $points[0], 'The points ledger prune is unchanged (totals are lifetime).' );
	}

	/**
	 * A key prefix is matched as an escaped LIKE 'prefix%' so it is an index range, and a prefix that
	 * contains LIKE wildcards cannot match another source's keys.
	 *
	 * @return void
	 */
	public function test_a_prefix_is_escaped_and_ends_with_a_trailing_wildcard_only(): void {
		$this->assertSame( 'mycred:log:%', ImportLedger::like( 'mycred:log:' ) );
		$this->assertSame( 'my\\_cred\\%:%', ImportLedger::like( 'my_cred%:' ), 'Wildcards inside a prefix are escaped.' );
		$this->assertStringStartsNotWith( '%', ImportLedger::like( 'x:' ), 'A leading wildcard would defeat the index.' );
	}

	/**
	 * A one-shot ingest recomputes the members it touched; a paged import turns that off and recomputes
	 * once at the end.
	 *
	 * @return void
	 */
	public function test_ingest_recomputes_by_default_and_can_be_told_not_to(): void {
		$param = ( new \ReflectionMethod( ImportService::class, 'ingest' ) )->getParameters()[1];
		$this->assertSame( 'recompute', $param->getName() );
		$this->assertTrue( $param->getDefaultValue(), '/events/import behaviour must not change.' );

		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Engine/ImportService.php' );
		$this->assertStringContainsString( '$recompute && ! empty( $users )', $src );
	}
}
