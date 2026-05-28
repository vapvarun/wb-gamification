<?php
/**
 * Unit tests for Registry::suggest_similar().
 *
 * Pins the "did you mean…" matcher used by Engine::process() to turn
 * silent action_id misses into actionable hints. The matcher should
 * pick up substring-extension typos (singular/plural, missing tense)
 * and single-character transpositions; everything else should return
 * empty rather than emit noise.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\Registry;

class RegistrySuggestSimilarTest extends TestCase {

	private array $original_actions;

	protected function setUp(): void {
		parent::setUp();
		$ref                    = new ReflectionClass( Registry::class );
		$prop                   = $ref->getProperty( 'actions' );
		$prop->setAccessible( true );
		$this->original_actions = $prop->getValue();

		$prop->setValue( null, array(
			'wc_order_completed'   => array( 'id' => 'wc_order_completed' ),
			'wc_product_reviewed'  => array( 'id' => 'wc_product_reviewed' ),
			'wc_add_to_cart'       => array( 'id' => 'wc_add_to_cart' ),
			'bp_activity_update'   => array( 'id' => 'bp_activity_update' ),
			'bp_groups_join'       => array( 'id' => 'bp_groups_join' ),
			'mvs_battle_win'       => array( 'id' => 'mvs_battle_win' ),
			'mvs_upload_photo'     => array( 'id' => 'mvs_upload_photo' ),
		) );
	}

	protected function tearDown(): void {
		$ref  = new ReflectionClass( Registry::class );
		$prop = $ref->getProperty( 'actions' );
		$prop->setAccessible( true );
		$prop->setValue( null, $this->original_actions );
		parent::tearDown();
	}

	public function test_substring_extension_match(): void {
		// Caller used singular; registry has past-tense.
		$out = Registry::suggest_similar( 'wc_product_review' );

		$this->assertContains( 'wc_product_reviewed', $out );
		$this->assertSame( 'wc_product_reviewed', $out[0], 'closest match is first' );
	}

	public function test_single_char_typo_match(): void {
		$out = Registry::suggest_similar( 'mvs_uplaod_photo' );

		$this->assertContains( 'mvs_upload_photo', $out );
	}

	public function test_tense_mismatch_match(): void {
		// "won" vs registered "win" — distance 1.
		$out = Registry::suggest_similar( 'mvs_battle_won' );

		$this->assertContains( 'mvs_battle_win', $out );
	}

	public function test_no_match_returns_empty(): void {
		$out = Registry::suggest_similar( 'totally_unrelated_xyz' );

		$this->assertSame( array(), $out );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( array(), Registry::suggest_similar( '' ) );
	}

	public function test_zero_limit_returns_empty(): void {
		$this->assertSame( array(), Registry::suggest_similar( 'wc_order_completed', 0 ) );
	}

	public function test_limit_caps_result_count(): void {
		// Many candidates would match short typo'd "wc_" if we accepted
		// loose matches; the implementation caps at $limit either way.
		$out = Registry::suggest_similar( 'wc_order_completd', 2 );

		$this->assertLessThanOrEqual( 2, count( $out ) );
	}

	public function test_inactive_plugin_suggestions_unavailable(): void {
		// When the owning plugin is inactive, its IDs aren't in the
		// registry, so they can't be suggested. This is a property,
		// not a bug — better empty than wrong.
		$ref  = new ReflectionClass( Registry::class );
		$prop = $ref->getProperty( 'actions' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$out = Registry::suggest_similar( 'wc_product_review' );

		$this->assertSame( array(), $out );
	}
}
