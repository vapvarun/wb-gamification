<?php
/**
 * Points come back off when the content that earned them is removed (1.6.8).
 *
 * Runs PointsEngine against an in-memory ledger: every case asserts the rows written and the
 * balance that results, the two things a member and a site owner see.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Registry;
use WBGam\Engine\Transaction;
use WBGam\Repository\PointTypeRepository;

#[CoversNothing]
class ContentReversalTest extends TestCase {

	/**
	 * The fake database.
	 *
	 * @var object
	 */
	private object $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->alias(
			static fn( $name, $default_value = false ) => in_array( $name, array( 'wb_gam_excluded_users', 'wb_gam_excluded_roles' ), true ) ? array() : $default_value
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key ) => match ( $key ) {
				'point_types_default' => 'points',
				'wb_gam_levels_all_v2', 'wb_gam_multiplier_rules' => array(),
				default => false,
			}
		);
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_set_last_changed' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-11-17 10:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );

		// Two point types exist: points (primary) and xp.
		$repo = new ReflectionClass( PointTypeRepository::class );
		$repo->getProperty( 'request_cache_all' )->setValue(
			null,
			array(
				array(
					'slug'       => 'points',
					'is_default' => 1,
				),
				array(
					'slug'       => 'xp',
					'is_default' => 0,
				),
			)
		);
		$repo->getProperty( 'request_cache_default' )->setValue( null, 'points' );

		$this->db        = $this->ledger();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		$repo = new ReflectionClass( PointTypeRepository::class );
		$repo->getProperty( 'request_cache_all' )->setValue( null, null );
		$repo->getProperty( 'request_cache_default' )->setValue( null, null );
		( new ReflectionClass( PointsEngine::class ) )->getProperty( 'deleting_users' )->setValue( null, array() );
		Transaction::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function deleting_a_post_takes_its_award_back(): void {
		$this->earn( 7, 'wp_publish_post', 25, 100 );

		$this->assertSame( 1, PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) ) );

		$this->assertSame( 0, $this->db->totals[7]['points'] );
		$last = end( $this->db->points );
		$this->assertSame( 'wp_publish_post_removed', $last['action_id'] );
		$this->assertSame( -25, $last['points'] );
		$this->assertSame( 100, $last['object_id'] );
	}

	#[Test]
	public function trash_then_permanent_delete_takes_the_points_once(): void {
		$this->earn( 7, 'wp_publish_post', 25, 100 );

		PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) ); // wp_trash_post.
		$this->assertSame( 0, PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) ) ); // before_delete_post.

		$this->assertSame( 0, $this->db->totals[7]['points'] );
		$this->assertCount( 2, $this->db->points );
	}

	#[Test]
	public function a_short_balance_goes_below_zero(): void {
		$this->earn( 7, 'wp_publish_post', 25, 100 );
		$this->earn( 7, 'redemption', -20, 0 ); // Spent most of it.

		PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) );

		$this->assertSame( -20, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function each_point_type_is_taken_back_in_its_own_currency(): void {
		$this->earn( 7, 'wp_publish_post', 25, 100, 'points' );
		$this->earn( 7, 'wp_publish_post', 10, 100, 'xp' );

		$this->assertSame( 2, PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) ) );

		$this->assertSame( 0, $this->db->totals[7]['points'] );
		$this->assertSame( 0, $this->db->totals[7]['xp'] );
	}

	#[Test]
	public function only_the_removed_object_and_action_move(): void {
		$this->earn( 7, 'wp_publish_post', 25, 100 );
		$this->earn( 7, 'wp_publish_post', 25, 101 );
		$this->earn( 8, 'wp_leave_comment', 5, 100 ); // A comment whose id happens to be 100.

		PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) );

		$this->assertSame( 25, $this->db->totals[7]['points'] );
		$this->assertSame( 5, $this->db->totals[8]['points'] );
	}

	#[Test]
	public function restoring_gives_the_award_back_once_and_it_can_be_removed_again(): void {
		$this->earn( 7, 'wp_leave_comment', 5, 300 );
		PointsEngine::reverse_object_awards( array( 'wp_leave_comment' => array( 300 ) ) );

		$this->assertSame( 1, PointsEngine::restore_object_awards( array( 'wp_leave_comment' => array( 300 ) ) ) );
		$this->assertSame( 0, PointsEngine::restore_object_awards( array( 'wp_leave_comment' => array( 300 ) ) ) );
		$this->assertSame( 5, $this->db->totals[7]['points'] );
		$this->assertSame( 'wp_leave_comment_restored', end( $this->db->points )['action_id'] );

		PointsEngine::reverse_object_awards( array( 'wp_leave_comment' => array( 300 ) ) );
		$this->assertSame( 0, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function trash_restore_trash_restore_ends_holding_exactly_one_award(): void {
		$this->earn( 7, 'wp_leave_comment', 5, 300 );
		$t = array( 'wp_leave_comment' => array( 300 ) );

		PointsEngine::reverse_object_awards( $t );
		PointsEngine::restore_object_awards( $t );
		PointsEngine::reverse_object_awards( $t );
		PointsEngine::restore_object_awards( $t );

		$this->assertSame( 5, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function restoring_twice_in_a_row_credits_once(): void {
		$this->earn( 7, 'wp_leave_comment', 5, 300 );
		$t = array( 'wp_leave_comment' => array( 300 ) );
		PointsEngine::reverse_object_awards( $t );

		PointsEngine::restore_object_awards( $t );
		PointsEngine::restore_object_awards( $t );

		$this->assertSame( 5, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function a_restore_after_publishing_again_gives_back_one_award_not_two(): void {
		$t = array( 'bbp_new_topic' => array( 400 ) );
		$this->earn( 7, 'bbp_new_topic', 10, 400 );
		PointsEngine::reverse_object_awards( $t );
		$this->earn( 7, 'bbp_new_topic', 10, 400 ); // Earned again while taken back.
		PointsEngine::reverse_object_awards( $t );

		PointsEngine::restore_object_awards( $t );

		$this->assertSame( 10, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function a_restore_after_a_below_zero_reversal_returns_exactly_what_was_taken(): void {
		$t = array( 'wp_leave_comment' => array( 300 ) );
		$this->earn( 7, 'wp_leave_comment', 5, 300 );
		$this->earn( 7, 'redemption', -5, 0 );
		PointsEngine::reverse_object_awards( $t );
		$this->assertSame( -5, $this->db->totals[7]['points'] );

		PointsEngine::restore_object_awards( $t );

		$this->assertSame( 0, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function restore_does_nothing_for_content_that_still_holds_its_award_or_never_earned(): void {
		$this->earn( 7, 'wp_leave_comment', 5, 300 );

		$this->assertSame( 0, PointsEngine::restore_object_awards( array( 'wp_leave_comment' => array( 300, 301 ) ) ) );
		$this->assertSame( 5, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function awards_from_before_1_6_8_are_left_alone(): void {
		$this->earn( 7, 'wp_publish_post', 25, null ); // No object id.

		$this->assertSame( 0, PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) ) );
		$this->assertSame( 25, $this->db->totals[7]['points'] );
	}

	#[Test]
	public function a_member_being_deleted_gets_no_reversal_rows(): void {
		$this->earn( 7, 'wp_publish_post', 25, 100 );
		PointsEngine::skip_reversals_for( 7 );

		$this->assertSame( 0, PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) ) );
		$this->assertCount( 1, $this->db->points );
	}

	#[Test]
	public function a_republished_post_earns_again_only_after_its_award_was_taken_back(): void {
		$action = array(
			'id'              => 'wp_publish_post',
			'once_per_object' => 'intval',
			'point_type'      => '',
		);

		// Published before 1.6.8: the row has no object id, so publishing again earns once, by object.
		$this->earn( 7, 'wp_publish_post', 25, null );
		$this->assertTrue( PointsEngine::passes_rate_limits( 7, 'wp_publish_post', $action, 100 ) );

		$this->earn( 7, 'wp_publish_post', 25, 100 );
		$this->assertFalse( PointsEngine::passes_rate_limits( 7, 'wp_publish_post', $action, 100 ), 'Live post: no second award.' );

		PointsEngine::reverse_object_awards( array( 'wp_publish_post' => array( 100 ) ) );
		$this->assertTrue( PointsEngine::passes_rate_limits( 7, 'wp_publish_post', $action, 100 ), 'Trashed then published again: earns again.' );
	}

	#[Test]
	public function a_post_trashed_and_republished_three_times_counts_once_toward_badges(): void {
		$actions = ( new ReflectionClass( Registry::class ) )->getProperty( 'actions' );
		$saved   = $actions->getValue();
		$actions->setValue(
			null,
			array(
				'wp_publish_post' => array(
					'id'              => 'wp_publish_post',
					'once_per_object' => 'intval',
				),
			)
		);
		try {
			$t = array( 'wp_publish_post' => array( 100 ) );
			$this->earn( 7, 'wp_publish_post', 25, null ); // A post from before 1.6.8 still counts.
			$this->earn( 7, 'wp_publish_post', 25, 100 );
			for ( $i = 0; $i < 3; $i++ ) {
				PointsEngine::reverse_object_awards( $t );
				$this->earn( 7, 'wp_publish_post', 25, 100 ); // Published again.
			}

			$this->assertSame( 2, PointsEngine::get_action_count( 7, 'wp_publish_post' ) );
			$this->assertSame( 50, $this->db->totals[7]['points'] );
		} finally {
			$actions->setValue( null, $saved );
		}
	}

	#[Test]
	public function history_names_the_reversal_after_the_action(): void {
		$this->assertSame( 'Wp Publish Post (content removed)', Registry::label_for( 'wp_publish_post_removed' ) );
		$this->assertSame( 'Wp Publish Post (content restored)', Registry::label_for( 'wp_publish_post_restored' ) );
	}

	/**
	 * Put an award straight into the ledger.
	 *
	 * @param int      $user_id   Member.
	 * @param string   $action_id Action.
	 * @param int      $points    Points (negative for a spend).
	 * @param int|null $object_id Object, null for a pre-1.6.8 row.
	 * @param string   $type      Point type.
	 */
	private function earn( int $user_id, string $action_id, int $points, ?int $object_id, string $type = 'points' ): void {
		$this->db->points[]                  = array(
			'user_id'    => $user_id,
			'action_id'  => $action_id,
			'points'     => $points,
			'point_type' => $type,
			'object_id'  => $object_id,
		);
		$this->db->totals[ $user_id ][ $type ] = ( $this->db->totals[ $user_id ][ $type ] ?? 0 ) + $points;
	}

	/**
	 * An in-memory wb_gam_points ledger that answers the queries the reversal path runs.
	 *
	 * @return object
	 */
	private function ledger(): object {
		return new class() {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public array $points      = array();
			public array $totals      = array();

			public function prepare( string $sql, ...$args ): array {
				return array( $sql, 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args );
			}

			public function get_results( $q, $output = null ): array {
				if ( ! is_array( $q ) || ! str_contains( $q[0], 'ORDER BY id' ) ) {
					return array();
				}
				$acts = array_filter( $q[1], 'is_string' );
				$ids  = array_filter( $q[1], 'is_int' );
				return array_values(
					array_filter(
						$this->points,
						static fn( $r ) => in_array( $r['action_id'], $acts, true ) && in_array( $r['object_id'], $ids, true )
					)
				);
			}

			public function get_var( $q ) {
				if ( is_array( $q ) && str_contains( $q[0], 'COUNT(DISTINCT object_id)' ) ) {
					list( $user, $action ) = $q[1];
					$objects               = array();
					$legacy                = 0;
					foreach ( $this->points as $r ) {
						if ( $r['user_id'] === $user && $r['action_id'] === $action ) {
							null === $r['object_id'] ? ++$legacy : $objects[ $r['object_id'] ] = true;
						}
					}
					return count( $objects ) + $legacy;
				}
				if ( is_array( $q ) && str_contains( $q[0], 'action_id IN (%s, %s, %s) AND object_id' ) ) {
					list( $user, $a, $b, $c, $object ) = $q[1];
					$sum                               = 0;
					foreach ( $this->points as $r ) {
						if ( $r['user_id'] === $user && in_array( $r['action_id'], array( $a, $b, $c ), true ) && $r['object_id'] === $object ) {
							$sum += $r['points'];
						}
					}
					return $sum;
				}
				return null;
			}

			public function insert( string $table, array $data ) {
				if ( str_ends_with( $table, 'wb_gam_points' ) ) {
					$this->points[] = array(
						'user_id'    => (int) $data['user_id'],
						'action_id'  => (string) $data['action_id'],
						'points'     => (int) $data['points'],
						'point_type' => (string) $data['point_type'],
						'object_id'  => $data['object_id'] ? (int) $data['object_id'] : null,
					);
				}
				return 1;
			}

			public function query( $q ) {
				if ( is_array( $q ) && str_contains( $q[0], 'wb_gam_user_totals' ) ) {
					list( $user, $type, $delta )    = $q[1];
					$this->totals[ $user ][ $type ] = ( $this->totals[ $user ][ $type ] ?? 0 ) + $delta;
				}
				return 1;
			}
		};
	}
}
