<?php
/**
 * Family trigger decisions that stop one member action paying twice (1.6.5).
 *
 * Each case calls the real manifest callback with the arguments the partner plugin sends and
 * asserts who is paid (0 = nobody).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class TriggerDecisionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( 'JETONOMY_VERSION', 'BUDDYNEXT_VERSION', 'LEARNOMY_VERSION', 'WCB_VERSION', 'WB_LISTORA_VERSION', 'EVNM_VERSION', 'EVENTONOMY_VERSION' ) as $c ) {
			if ( ! defined( $c ) ) {
				define( $c, '1.0.0-test' );
			}
		}
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( 16 );
		Functions\when( 'get_post_field' )->justReturn( 16 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The user_callback of one trigger in an integration manifest.
	 *
	 * @param string $file Manifest file.
	 * @param string $id   Action id.
	 * @return callable
	 */
	private function trigger( string $file, string $id ): callable {
		$manifest = include dirname( __DIR__, 3 ) . '/integrations/' . $file;
		foreach ( $manifest['triggers'] as $t ) {
			if ( $t['id'] === $id ) {
				return $t['user_callback'];
			}
		}
		$this->fail( "$id not in $file" );
	}

	#[Test]
	public function buddynext_share_is_paid_by_the_share_award_only(): void {
		$cb = $this->trigger( 'buddynext.php', 'bn_post_created' );
		$this->assertSame( 16, $cb( 1, 16, 'text' ) );
		$this->assertSame( 0, $cb( 2, 16, 'share' ) );
	}

	#[Test]
	public function buddynext_comment_mirrored_from_a_blog_comment_earns_nothing(): void {
		$cb = $this->trigger( 'buddynext.php', 'bn_comment_created' );
		$this->assertSame( 17, $cb( 1, 'post', 5, 17 ) );

		Functions\when( 'doing_action' )->alias( fn( $h ) => 'wp_insert_comment' === $h );
		$this->assertSame( 0, $cb( 2, 'post', 5, 17 ) );
	}

	#[Test]
	public function learnomy_path_certificate_is_paid_by_path_completion(): void {
		$cb = $this->trigger( 'learnomy.php', 'learnomy_certificate_issued' );
		$this->assertSame( 16, $cb( 3, 16, 42 ) );
		$this->assertSame( 0, $cb( 4, 16, 0 ) );
	}

	#[Test]
	public function learnomy_quiz_accepts_a_percentage_score(): void {
		$cb = $this->trigger( 'learnomy.php', 'learnomy_quiz_passed' );
		$this->assertSame( 16, $cb( 11, 16, 5, 87.5 ) );
	}

	#[Test]
	public function career_board_pays_only_the_move_into_hired(): void {
		$cb = $this->trigger( 'wp-career-board.php', 'wcb_candidate_hired' );
		$this->assertSame( 16, $cb( 9, 'applied', 'hired' ) );
		$this->assertSame( 0, $cb( 9, 'hired', 'hired' ) );
	}

	#[Test]
	public function eventonomy_rsvp_from_a_paid_order_is_paid_by_the_ticket(): void {
		$cb = $this->trigger( 'eventonomy.php', 'evnm_rsvp_going' );
		$this->assertSame( 16, $cb( array( 'status' => 'going', 'user_id' => 16 ), array() ) );
		$this->assertSame( 0, $cb( array( 'status' => 'going', 'user_id' => 16, 'order_id' => 9 ), array() ) );
	}

	#[Test]
	public function listora_import_is_not_a_submission(): void {
		$cb = $this->trigger( 'wb-listora.php', 'listora_listing_submitted' );
		$this->assertSame( 16, $cb( 7, 'pending', null, array( 'source' => 'frontend' ) ) );
		$this->assertSame( 0, $cb( 7, 'publish', null, array( 'source' => 'migration' ) ) );
	}

	#[Test]
	public function membership_points_pay_once_per_plan(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// The ledger: plans this member already earned on (level ids).
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public array $earned  = array( 'bnpro:283' );
			public string $needle = '';
			public function esc_like( string $t ): string {
				return addcslashes( $t, '_%\\' );
			}
			public function prepare( string $sql, ...$args ): string {
				$this->needle = (string) end( $args );
				return $sql;
			}
			public function get_var( string $sql ): int {
				foreach ( $this->earned as $level ) {
					if ( str_contains( stripslashes( $this->needle ), '"level_id":"' . $level . '"' ) ) {
						return 1;
					}
				}
				return 0;
			}
		};

		$cb = $this->trigger( 'jetonomy.php', 'jetonomy_membership_activated' );
		$this->assertSame( 0, $cb( 21, 'bnpro:283', 'buddynext-pro' ), 'Renewal, date change or cadence switch of a plan already paid: nothing.' );
		$this->assertSame( 21, $cb( 21, 'bnpro:2', 'buddynext-pro' ), 'A different plan earns, once.' );
		$this->assertSame( 21, $cb( 21, 'bnpro:28', 'buddynext-pro' ), 'Plan 28 is not plan 283.' );
		$this->assertStringEndsWith( ':"bnpro:28"%', $GLOBALS['wpdb']->needle, 'The LIKE closes the quote, so a prefix id never matches.' );

		Functions\when( 'apply_filters' )->justReturn( false );
		$this->assertSame( 0, $cb( 21, 'bnpro:9', 'buddynext-pro' ), 'A free plan never earns.' );
		unset( $GLOBALS['wpdb'] );
	}
}
