<?php
/**
 * Shape + user-resolution tests for the family integration manifests
 * (BuddyNext, Learnomy, WP Career Board, Listora — free + pro).
 *
 * These manifests are auto-discovered by ManifestLoader; here we assert each
 * returns a well-formed trigger set and that the user_callbacks resolve the
 * rewarded member correctly (arg position, post_author derivation, gating).
 */

namespace WBGam\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class FamilyManifestTest extends TestCase {

	/** @var array<string,int> manifest file => expected trigger count */
	private const MANIFESTS = array(
		'buddynext.php'           => 16,
		'learnomy.php'            => 6,
		'learnomy-pro.php'        => 5,
		'wp-career-board.php'     => 6,
		'wp-career-board-pro.php' => 1,
		'wb-listora.php'          => 6,
		'wb-listora-pro.php'      => 3,
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Define every family guard constant so each manifest returns its triggers.
		foreach ( array( 'BUDDYNEXT_VERSION', 'LEARNOMY_VERSION', 'LEARNOMY_PRO_VERSION', 'WCB_VERSION', 'WCBP_VERSION', 'WB_LISTORA_VERSION', 'WB_LISTORA_PRO_VERSION' ) as $c ) {
			if ( ! defined( $c ) ) {
				define( $c, '1.0.0-test' );
			}
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function load( string $file ): array {
		return include dirname( __DIR__, 3 ) . '/integrations/' . $file;
	}

	/** @test */
	public function every_manifest_returns_well_formed_triggers(): void {
		$seen = array();
		foreach ( self::MANIFESTS as $file => $expected_count ) {
			$manifest = $this->load( $file );
			$this->assertIsArray( $manifest, "$file must return an array" );
			$this->assertArrayHasKey( 'plugin', $manifest, "$file missing plugin" );
			$this->assertArrayHasKey( 'triggers', $manifest, "$file missing triggers" );
			$this->assertCount( $expected_count, $manifest['triggers'], "$file trigger count drifted" );

			foreach ( $manifest['triggers'] as $t ) {
				$this->assertArrayHasKey( 'id', $t, "$file trigger missing id" );
				$this->assertArrayHasKey( 'hook', $t, "{$t['id']} missing hook" );
				$this->assertArrayHasKey( 'default_points', $t, "{$t['id']} missing default_points" );
				$this->assertIsInt( $t['default_points'], "{$t['id']} points must be int" );
				$this->assertTrue( is_callable( $t['user_callback'] ), "{$t['id']} user_callback must be callable" );
				$this->assertArrayNotHasKey( $t['id'], $seen, "duplicate action id across family manifests: {$t['id']}" );
				$seen[ $t['id'] ] = $file;
			}
		}
	}

	/** @test */
	public function user_callbacks_resolve_the_rewarded_member(): void {
		$triggers = array();
		foreach ( array_keys( self::MANIFESTS ) as $file ) {
			foreach ( $this->load( $file )['triggers'] as $t ) {
				$triggers[ $t['id'] ] = $t['user_callback'];
			}
		}

		// Direct user-id args (position varies per hook signature).
		$this->assertSame( 42, $triggers['learnomy_lesson_completed']( 42, 1, 2 ) );      // arg1
		$this->assertSame( 7, $triggers['learnomy_course_completed']( 5, 7, 9 ) );        // arg2
		$this->assertSame( 99, $triggers['wcb_application_submitted']( 1, 2, 99 ) );      // arg3 candidate
		$this->assertSame( 88, $triggers['listora_review_written']( 1, 2, 88 ) );         // arg3 reviewer
		$this->assertSame( 55, $triggers['bn_post_created']( 10, 55, 'text' ) );          // arg2 author

		// bn_profile_completed (strength hook): awards only at exactly 100%.
		$this->assertSame( 55, $triggers['bn_profile_completed']( 55, 100 ) );
		$this->assertSame( 0, $triggers['bn_profile_completed']( 55, 80 ) );
		// bn_profile_updated awards on every completion change: since the 100%
		// milestone moved to the strength hook, the old "< 100" exclusion is
		// gone — BuddyNext fires completion_changed only on real changes.
		$this->assertSame( 55, $triggers['bn_profile_updated']( 55, 100 ) );
		$this->assertSame( 55, $triggers['bn_profile_updated']( 55, 60 ) );
	}

	/** @test */
	public function post_author_derived_callbacks_resolve_via_post(): void {
		Functions\when( 'get_post_field' )->alias(
			static function ( $field, $id ) {
				return 'post_author' === $field ? 321 : 0;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( 777 );

		$triggers = array();
		foreach ( array_keys( self::MANIFESTS ) as $file ) {
			foreach ( $this->load( $file )['triggers'] as $t ) {
				$triggers[ $t['id'] ] = $t['user_callback'];
			}
		}

		// Listing/job hooks carry no user id → owner resolved from post_author.
		$this->assertSame( 321, $triggers['wcb_job_posted']( 500 ) );
		$this->assertSame( 321, $triggers['listora_listing_submitted']( 500, 'pending' ) );
		$this->assertSame( 321, $triggers['listora_listing_published']( 500 ) );

		// Career Board "hired": resolves candidate from meta, only on transition INTO hired.
		$this->assertSame( 777, $triggers['wcb_candidate_hired']( 9, 'shortlisted', 'hired' ) );
		$this->assertSame( 0, $triggers['wcb_candidate_hired']( 9, 'hired', 'rejected' ) );
	}
}
