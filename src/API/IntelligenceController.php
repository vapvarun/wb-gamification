<?php
/**
 * REST API controller: per-user behavioural intelligence.
 *
 * Exposes the wb_gam_user_intelligence projection table (v2.5 + AI v1
 * — populated by IntelligenceProjector). Read-only; the projection is
 * recomputed by the daily cron, not by API consumers.
 *
 * Routes:
 *   GET /wb-gamification/v1/members/{id}/intelligence
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\API;

use WBGam\Engine\IntelligenceProjector;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class IntelligenceController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/members/(?P<id>\d+)/intelligence',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Admins can read any user's intelligence; non-admin can read their
	 * own. Returning intelligence for someone else would leak behavioural
	 * signals.
	 */
	public function get_item_permissions_check( $request ): bool {
		$target = (int) $request['id'];
		$me     = get_current_user_id();

		if ( $me > 0 && $me === $target ) {
			return true;
		}
		return current_user_can( 'wb_gam_view_analytics' ) || current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$target = (int) $request['id'];
		$row    = IntelligenceProjector::get_for_user( $target );

		if ( null === $row ) {
			// Either no events yet or the projection cron hasn't reached
			// them. Compute on demand so the first read for a given user
			// returns useful data instead of a 404.
			IntelligenceProjector::compute_for_user( $target );
			$row = IntelligenceProjector::get_for_user( $target );
		}

		if ( null === $row ) {
			return new WP_REST_Response(
				array(
					'code'    => 'no_data',
					'message' => 'No intelligence signals available for this user yet.',
				),
				404
			);
		}

		return new WP_REST_Response( $row, 200 );
	}

	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-user-intelligence',
			'type'       => 'object',
			'properties' => array(
				'user_id'          => array(
					'type'        => 'integer',
					'description' => 'WordPress user id this row describes.',
				),
				'engagement_score' => array(
					'type'        => 'number',
					'description' => 'Computed engagement score (0..~2.7). Higher = more engaged. Combines event volume, action diversity, and recency.',
				),
				'action_diversity' => array(
					'type'        => 'integer',
					'description' => 'Number of distinct action_ids the user has fired in the last 30 days.',
				),
				'recency_days'    => array(
					'type'        => 'integer',
					'description' => 'Days since the last recorded event. 999 = never active in window.',
				),
				'events_30d'       => array(
					'type'        => 'integer',
					'description' => 'Total events in the last 30 days.',
				),
				'churn_risk'       => array(
					'type'        => 'number',
					'description' => 'Inverse of normalised engagement, 0..1. Above 0.7 = high churn risk.',
				),
				'anomaly_flag'     => array(
					'type'        => 'boolean',
					'description' => 'True when behaviour matches the bot/grinder pattern (high volume + low diversity).',
				),
				'computed_at'      => array(
					'type'        => 'string',
					'description' => 'Timestamp of last projection compute. May lag up to 24 hours behind ground truth on quiet installs.',
				),
			),
		);
	}
}
