<?php
/**
 * REST API: Rules Controller
 *
 * CRUD management for gamification rules stored in wb_gam_rules.
 *
 * GET    /wb-gamification/v1/rules           List rules (filterable by type)
 * POST   /wb-gamification/v1/rules           Create a new rule
 * GET    /wb-gamification/v1/rules/{id}      Get single rule
 * PUT    /wb-gamification/v1/rules/{id}      Update a rule
 * DELETE /wb-gamification/v1/rules/{id}      Delete a rule
 *
 * All endpoints require manage_options.
 *
 * Rule types:
 *   badge_condition  — conditions evaluated by BadgeEngine after point awards
 *     Config examples:
 *       { "condition_type": "point_milestone", "points": 500 }
 *       { "condition_type": "action_count", "action_id": "wp_publish_post", "count": 10 }
 *       { "condition_type": "admin_awarded" }
 *
 *   point_multiplier — temporary or permanent per-action point multipliers
 *     Config examples:
 *       { "action_id": "bp_activity_update", "multiplier": 2.0 }
 *       { "action_id": "*", "multiplier": 1.5, "starts_at": "2024-01-01", "ends_at": "2024-01-07" }
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RulesController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'rules';

	/** @var string[] */
	private const VALID_RULE_TYPES = array( 'badge_condition', 'point_multiplier' );

	/** @var string[] */
	private const VALID_CONDITION_TYPES = array( 'point_milestone', 'action_count', 'admin_awarded' );

	public function register_routes(): void {
		// Collection.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'rule_type' => array(
							'type' => 'string',
							'enum' => self::VALID_RULE_TYPES,
						),
						'target_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'is_active' => array(
							'type' => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->rule_args( required: true ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// Single item.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->rule_args( required: false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $request['rule_type'] ) ) {
			$where[]  = 'rule_type = %s';
			$params[] = $request['rule_type'];
		}

		if ( ! empty( $request['target_id'] ) ) {
			$where[]  = 'target_id = %s';
			$params[] = $request['target_id'];
		}

		if ( null !== $request['is_active'] ) {
			$where[]  = 'is_active = %d';
			$params[] = $request['is_active'] ? 1 : 0;
		}

		$sql = "SELECT id, rule_type, target_id, rule_config, is_active, created_at
		          FROM {$wpdb->prefix}wb_gam_rules
		         WHERE " . implode( ' AND ', $where ) . '
		         ORDER BY rule_type, id ASC';

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ?: array() ) );
	}

	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->fetch_row( (int) $request['id'] );
		return $row
			? rest_ensure_response( $this->prepare_item( $row ) )
			: new WP_Error( 'rest_not_found', __( 'Rule not found.', 'wb-gamification' ), array( 'status' => 404 ) );
	}

	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$rule_config = $request['rule_config'];

		// For badge_condition rules, validate condition structure.
		if ( 'badge_condition' === $request['rule_type'] ) {
			$validation = $this->validate_badge_condition_config( $rule_config );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_rules',
			array(
				'rule_type'   => $request['rule_type'],
				'target_id'   => $request['target_id'] ?? null,
				'rule_config' => wp_json_encode( $rule_config ),
				'is_active'   => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'rest_insert_failed', __( 'Could not create rule.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		$this->flush_rules_cache();

		return new WP_REST_Response( $this->prepare_item( $this->fetch_row( $wpdb->insert_id ) ), 201 );
	}

	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Rule not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$data = array();

		if ( isset( $request['rule_type'] ) ) {
			$data['rule_type'] = $request['rule_type'];
		}

		if ( isset( $request['target_id'] ) ) {
			$data['target_id'] = $request['target_id'];
		}

		if ( isset( $request['rule_config'] ) ) {
			$config    = $request['rule_config'];
			$rule_type = $data['rule_type'] ?? $row['rule_type'];
			if ( 'badge_condition' === $rule_type ) {
				$validation = $this->validate_badge_condition_config( $config );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
			$data['rule_config'] = wp_json_encode( $config );
		}

		if ( isset( $request['is_active'] ) ) {
			$data['is_active'] = (int) $request['is_active'];
		}

		if ( empty( $data ) ) {
			return rest_ensure_response( $this->prepare_item( $row ) );
		}

		$wpdb->update( $wpdb->prefix . 'wb_gam_rules', $data, array( 'id' => $id ) );
		$this->flush_rules_cache();

		return rest_ensure_response( $this->prepare_item( $this->fetch_row( $id ) ) );
	}

	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Rule not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( $wpdb->prefix . 'wb_gam_rules', array( 'id' => $id ), array( '%d' ) );
		$this->flush_rules_cache();

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private function fetch_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, rule_type, target_id, rule_config, is_active, created_at
				   FROM {$wpdb->prefix}wb_gam_rules WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	private function prepare_item( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'rule_type'   => $row['rule_type'],
			'target_id'   => $row['target_id'],
			'rule_config' => json_decode( $row['rule_config'] ?? '{}', true ) ?: array(),
			'is_active'   => (bool) $row['is_active'],
			'created_at'  => $row['created_at'],
		);
	}

	/**
	 * Validate the rule_config object for badge_condition rules.
	 */
	private function validate_badge_condition_config( array $config ): bool|WP_Error {
		$type = $config['condition_type'] ?? '';

		if ( ! in_array( $type, self::VALID_CONDITION_TYPES, true ) ) {
			return new WP_Error(
				'invalid_condition_type',
				sprintf(
					/* translators: %s = invalid condition_type value */
					__( 'Invalid condition_type: %s. Must be one of: point_milestone, action_count, admin_awarded.', 'wb-gamification' ),
					esc_html( $type )
				),
				array( 'status' => 400 )
			);
		}

		if ( 'point_milestone' === $type && empty( $config['points'] ) ) {
			return new WP_Error(
				'missing_points',
				__( 'point_milestone condition requires a "points" value.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		if ( 'action_count' === $type && ( empty( $config['action_id'] ) || empty( $config['count'] ) ) ) {
			return new WP_Error(
				'missing_action_count_fields',
				__( 'action_count condition requires "action_id" and "count" values.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Bust the BadgeEngine rules cache so new/updated rules take effect immediately.
	 */
	private function flush_rules_cache(): void {
		wp_cache_delete( 'wb_gam_badge_rules', 'wb_gamification' );
	}

	private function rule_args( bool $required = true ): array {
		return array(
			'rule_type'   => array(
				'required'          => $required,
				'type'              => 'string',
				'enum'              => self::VALID_RULE_TYPES,
				'sanitize_callback' => 'sanitize_key',
			),
			'target_id'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'description'       => 'Badge ID (for badge_condition) or action ID (for point_multiplier).',
			),
			'rule_config' => array(
				'required'    => $required,
				'type'        => 'object',
				'description' => 'JSON condition/multiplier config. Structure depends on rule_type.',
			),
			'is_active'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}

	public function admin_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'Admin only.', 'wb-gamification' ), array( 'status' => 403 ) );
	}

	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-rule',
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'rule_type'   => array(
					'type' => 'string',
					'enum' => self::VALID_RULE_TYPES,
				),
				'target_id'   => array( 'type' => array( 'string', 'null' ) ),
				'rule_config' => array( 'type' => 'object' ),
				'is_active'   => array( 'type' => 'boolean' ),
				'created_at'  => array( 'type' => 'string' ),
			),
		);
	}
}
