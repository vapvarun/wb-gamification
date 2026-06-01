<?php
/**
 * WPGraphQL integration — exposes the gamification engine as GraphQL
 * types and queries.
 *
 * Activates only when WPGraphQL is loaded (function_exists check).
 * No-op otherwise — installing this plugin without WPGraphQL doesn't
 * fire any errors or warnings.
 *
 * Types registered:
 *   WBGamMember       Member's points, level, badges, streak
 *   WBGamBadge        Badge definition + earned state
 *   WBGamLeaderboardEntry
 *   WBGamIntelligence (v2.5/AI v1 projection)
 *
 * Root query fields (under RootQuery):
 *   wbGamMember(userId: Int!)        -> WBGamMember
 *   wbGamLeaderboard(period, limit)  -> [WBGamLeaderboardEntry!]!
 *   wbGamBadges                      -> [WBGamBadge!]!
 *   wbGamIntelligence(userId: Int!)  -> WBGamIntelligence
 *
 * Resolvers delegate to existing engine methods — no business logic
 * duplication. The same code that powers the REST API powers GraphQL.
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\Integrations;

use WBGam\Engine\IntelligenceProjector;
use WBGam\Engine\LeaderboardEngine;
use WBGam\Engine\PointsEngine;

defined( 'ABSPATH' ) || exit;

final class GraphQL {

	public static function boot(): void {
		// WPGraphQL exposes its registration helpers via add_action(
		// 'graphql_register_types', ...). When the plugin isn't loaded
		// that hook never fires, so this method effectively no-ops.
		add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
	}

	/**
	 * Registers the gamification type system + root query fields.
	 *
	 * Called by WPGraphQL on its graphql_register_types action.
	 */
	public static function register_types(): void {
		if ( ! function_exists( 'register_graphql_object_type' ) ) {
			return; // belt + suspenders — should be loaded by the hook firing.
		}

		self::register_member_type();
		self::register_badge_type();
		self::register_leaderboard_type();
		self::register_intelligence_type();
		self::register_root_queries();
	}

	private static function register_member_type(): void {
		register_graphql_object_type(
			'WBGamMember',
			array(
				'description' => 'Gamification profile for a WordPress user.',
				'fields'      => array(
					'userId'      => array(
						'type'        => 'Int',
						'description' => 'WordPress user id.',
					),
					'totalPoints' => array(
						'type'        => 'Int',
						'description' => 'Total points for the default point type.',
						'resolve'     => static function ( $member ) {
							return PointsEngine::get_total( (int) $member['user_id'] );
						},
					),
					'displayName' => array(
						'type'        => 'String',
						'description' => 'WP display_name for the user.',
						'resolve'     => static function ( $member ) {
							$wp_user = get_user_by( 'id', (int) $member['user_id'] );
							return $wp_user ? $wp_user->display_name : '';
						},
					),
				),
			)
		);
	}

	private static function register_badge_type(): void {
		register_graphql_object_type(
			'WBGamBadge',
			array(
				'description' => 'Badge definition + per-user earned state.',
				'fields'      => array(
					'id'          => array(
						'type'        => 'ID',
						'description' => 'Stable badge slug.',
					),
					'name'        => array( 'type' => 'String' ),
					'description' => array( 'type' => 'String' ),
					'imageUrl'    => array( 'type' => 'String' ),
					'earnedAt'    => array(
						'type'        => 'String',
						'description' => 'ISO8601 timestamp when current user earned this badge. Null if not yet earned.',
					),
				),
			)
		);
	}

	private static function register_leaderboard_type(): void {
		register_graphql_object_type(
			'WBGamLeaderboardEntry',
			array(
				'description' => 'A single ranked row on the leaderboard.',
				'fields'      => array(
					'rank'        => array( 'type' => 'Int' ),
					'userId'      => array( 'type' => 'Int' ),
					'displayName' => array( 'type' => 'String' ),
					'points'      => array( 'type' => 'Int' ),
					'avatarUrl'   => array( 'type' => 'String' ),
				),
			)
		);
	}

	private static function register_intelligence_type(): void {
		register_graphql_object_type(
			'WBGamIntelligence',
			array(
				'description' => 'Behavioural intelligence signals - computed daily by IntelligenceProjector.',
				'fields'      => array(
					'userId'          => array( 'type' => 'Int' ),
					'engagementScore' => array(
						'type'        => 'Float',
						'description' => 'Composite engagement signal (0..~2.7).',
					),
					'actionDiversity' => array(
						'type'        => 'Int',
						'description' => 'Distinct action_ids in the last 30 days.',
					),
					'recencyDays'     => array(
						'type'        => 'Int',
						'description' => 'Days since last event. 999 = inactive in window.',
					),
					'events30d'       => array( 'type' => 'Int' ),
					'churnRisk'       => array(
						'type'        => 'Float',
						'description' => '0..1, higher = greater churn likelihood.',
					),
					'anomalyFlag'     => array( 'type' => 'Boolean' ),
					'computedAt'      => array( 'type' => 'String' ),
				),
			)
		);
	}

	private static function register_root_queries(): void {
		register_graphql_field(
			'RootQuery',
			'wbGamMember',
			array(
				'type'        => 'WBGamMember',
				'description' => 'Get a gamification profile by user id.',
				'args'        => array(
					'userId' => array( 'type' => array( 'non_null' => 'Int' ) ),
				),
				'resolve'     => static function ( $root, array $args ) {
					$user_id = (int) ( $args['userId'] ?? 0 );
					if ( $user_id <= 0 ) {
						return null;
					}
					return array( 'user_id' => $user_id );
				},
			)
		);

		register_graphql_field(
			'RootQuery',
			'wbGamLeaderboard',
			array(
				'type'        => array( 'list_of' => 'WBGamLeaderboardEntry' ),
				'description' => 'Ranked leaderboard for the given period.',
				'args'        => array(
					'period' => array(
						'type'        => 'String',
						'description' => 'One of: all, week, month, day. Default "all".',
					),
					'limit'  => array(
						'type'        => 'Int',
						'description' => 'Max rows. Default 10.',
					),
				),
				'resolve'     => static function ( $root, array $args ) {
					$period = (string) ( $args['period'] ?? 'all' );
					$limit  = (int) ( $args['limit'] ?? 10 );
					$entries = LeaderboardEngine::get_leaderboard( $period, $limit, '', 0, 'points' );
					// Normalise field names to camelCase for GraphQL idiom.
					$out = array();
					foreach ( (array) $entries as $row ) {
						$out[] = array(
							'rank'        => (int) ( $row['rank'] ?? 0 ),
							'userId'      => (int) ( $row['user_id'] ?? 0 ),
							'displayName' => (string) ( $row['display_name'] ?? '' ),
							'points'      => (int) ( $row['points'] ?? 0 ),
							'avatarUrl'   => (string) ( $row['avatar_url'] ?? '' ),
						);
					}
					return $out;
				},
			)
		);

		register_graphql_field(
			'RootQuery',
			'wbGamIntelligence',
			array(
				'type'        => 'WBGamIntelligence',
				'description' => 'Behavioural intelligence for a given user. Admins see anyone; users see their own only.',
				'args'        => array(
					'userId' => array( 'type' => array( 'non_null' => 'Int' ) ),
				),
				'resolve'     => static function ( $root, array $args ) {
					$user_id = (int) ( $args['userId'] ?? 0 );
					$me      = get_current_user_id();

					// Same authz rule as IntelligenceController::get_item_permissions_check.
					$allowed = ( $me > 0 && $me === $user_id )
						|| current_user_can( 'wb_gam_view_analytics' )
						|| current_user_can( 'manage_options' );
					if ( ! $allowed ) {
						return null;
					}

					$row = IntelligenceProjector::get_for_user( $user_id );
					if ( null === $row ) {
						IntelligenceProjector::compute_for_user( $user_id );
						$row = IntelligenceProjector::get_for_user( $user_id );
					}
					if ( null === $row ) {
						return null;
					}

					return array(
						'userId'          => (int) $row['user_id'],
						'engagementScore' => (float) $row['engagement_score'],
						'actionDiversity' => (int) $row['action_diversity'],
						'recencyDays'     => (int) $row['recency_days'],
						'events30d'       => (int) $row['events_30d'],
						'churnRisk'       => (float) $row['churn_risk'],
						'anomalyFlag'     => (bool) $row['anomaly_flag'],
						'computedAt'      => (string) $row['computed_at'],
					);
				},
			)
		);
	}
}
