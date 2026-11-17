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
	public function a_member_copied_into_a_buddynext_space_is_not_paid_the_join_again(): void {
		require_once __DIR__ . '/Stubs/IntegrationActivityStub.php';
		$cb = $this->trigger( 'buddynext.php', 'bn_space_joined' );
		$this->assertSame( 16, $cb( 3, 16, 'member' ) );

		\BuddyNext\Feed\IntegrationActivity::$mirror = true;
		try {
			$this->assertSame( 0, $cb( 3, 16, 'member' ) );
		} finally {
			\BuddyNext\Feed\IntegrationActivity::$mirror = false;
		}
	}

	#[Test]
	public function an_activity_comment_mediaverse_copied_from_a_photo_comment_earns_nothing(): void {
		$cb       = $this->trigger( 'buddypress.php', 'bp_activity_comment' );
		$activity = (object) array( 'id' => 5 );
		$this->assertSame( 16, $cb( 9, array( 'user_id' => 16 ), $activity ), 'A genuine activity comment still pays.' );

		Functions\when( 'doing_action' )->alias( fn( $h ) => 'mvs_comment_created' === $h );
		$this->assertSame( 0, $cb( 9, array( 'user_id' => 16 ), $activity ) );
	}

	#[Test]
	public function a_photo_comment_synced_from_an_activity_comment_earns_nothing(): void {
		defined( 'MVS_VERSION' ) || define( 'MVS_VERSION', '1.0.0-test' );
		$cb   = $this->trigger( 'wpmediaverse.php', 'mvs_give_comment' );
		$text = 'A comment long enough to count.';
		$this->assertSame( 16, $cb( 4, 16, 77, $text, '' ) );
		$this->assertSame( 16, $cb( 4, 16, 77, $text ), 'MediaVerse before the source argument.' );
		$this->assertSame( 0, $cb( 4, 16, 77, $text, 'bp_activity' ) );

		$this->assertSame( 0, $this->trigger( 'wpmediaverse.php', 'mvs_receive_comment' )( 4, 16, 77, $text, 'bp_activity' ) );
	}

	#[Test]
	public function a_free_order_does_not_use_up_the_first_purchase(): void {
		require_once __DIR__ . '/Stubs/WooCommerceStub.php';
		$order = static fn( float $total ) => new class( $total ) {
			public function __construct( private float $total ) {}
			public function get_customer_id(): int {
				return 16;
			}
			public function get_total(): float {
				return $this->total;
			}
		};
		Functions\when( 'wc_get_order' )->justReturn( $order( 10 ) );
		$cb = $this->trigger( 'woocommerce.php', 'wc_first_purchase' );

		// Only a free order (and nothing unpaid: the query asks for date_paid) was paid before.
		Functions\when( 'wc_get_orders' )->justReturn( array( $order( 0 ) ) );
		$this->assertSame( 16, $cb( 9 ) );

		Functions\when( 'wc_get_orders' )->justReturn( array( $order( 0 ), $order( 12.5 ) ) );
		$this->assertSame( 0, $cb( 9 ), 'An earlier paid order means this is not the first.' );
	}

	#[Test]
	public function a_listora_review_pays_when_published_and_follows_moderation(): void {
		defined( 'WB_LISTORA_VERSION' ) || define( 'WB_LISTORA_VERSION', '1.0.0-test' );
		$manifest = include dirname( __DIR__, 3 ) . '/integrations/wb-listora.php';
		$review   = array_values( array_filter( $manifest['triggers'], static fn( $t ) => 'listora_review_written' === $t['id'] ) )[0];

		$this->assertSame( 'wb_gam_listora_review_published', $review['hook'], 'Submitting a pending review must not pay.' );
		$this->assertSame( 4, $review['reverse_on']['wb_listora_review_status_changed']( 4, 'rejected' ) );
		$this->assertSame( 4, $review['reverse_on']['wb_listora_review_status_changed']( 4, 'pending' ) );
		$this->assertSame( 0, $review['reverse_on']['wb_listora_review_status_changed']( 4, 'approved' ) );
		$this->assertSame( 4, $review['reverse_on']['wb_listora_after_delete_review']( 4, 9, null ) );
		$this->assertSame( 4, $review['restore_on']['wb_listora_review_status_changed']( 4, 'approved' ) );
	}

	#[Test]
	public function buddypress_lists_no_trigger_that_can_never_register(): void {
		$ids = array_column( ( include dirname( __DIR__, 3 ) . '/integrations/buddypress.php' )['triggers'], 'id' );

		// wordpress.php always loads and its wp_publish_post supersedes bp_publish_post.
		$this->assertNotContains( 'bp_publish_post', $ids );
		$this->assertCount( 12, $ids );
	}

	#[Test]
	public function a_listora_listing_follows_moderation(): void {
		defined( 'WB_LISTORA_VERSION' ) || define( 'WB_LISTORA_VERSION', '1.0.0-test' );
		Functions\when( 'get_post_type' )->justReturn( 'listora_listing' );
		$manifest = include dirname( __DIR__, 3 ) . '/integrations/wb-listora.php';
		$by_id    = array_column( $manifest['triggers'], null, 'id' );

		foreach ( array( 'listora_listing_submitted', 'listora_listing_published' ) as $id ) {
			$out  = $by_id[ $id ]['reverse_on']['wb_listora_listing_status_changed'];
			$back = $by_id[ $id ]['restore_on']['wb_listora_listing_status_changed'];
			$this->assertSame( 7, $out( 7, 'listora_rejected', 'pending' ), "$id: rejected" );
			$this->assertSame( 7, $out( 7, 'trash', 'publish' ), "$id: trashed" );
			$this->assertSame( 0, $out( 7, 'listora_expired', 'publish' ), "$id: expiry is not a removal" );
			$this->assertSame( 7, $by_id[ $id ]['reverse_on']['before_delete_post']( 7 ), "$id: deleted" );
			$this->assertSame( 7, $back( 7, 'publish', 'listora_rejected' ), "$id: published again" );
			$this->assertSame( 0, $back( 7, 'pending', 'draft' ) );
		}
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
