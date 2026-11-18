<?php
/**
 * WB Gamification - Learnomy Pro Integration Manifest
 *
 * Auto-loaded by ManifestLoader when Learnomy Pro is active. Covers the Pro-only
 * achievements (learning paths, assignments, cohorts/spaces, gifts) that the
 * free manifest cannot. Hook signatures verified against Learnomy Pro 1.1.1.
 *
 * @package WB_Gamification
 * @see     https://wbcomdesigns.com/downloads/learnomy-pro/
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LEARNOMY_PRO_VERSION' ) ) {
	return array();
}

return array(
	'plugin'   => 'Learnomy Pro',
	'version'  => '1.0.0',
	'triggers' => array(

		array(
			'id'                => 'learnomy_pro_path_completed',
			'label'             => static fn(): string => __( 'Complete a learning path', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member completes a full learning path.', 'wb-gamification' ),
			// Pro fires: do_action( 'learnomy_pro_learning_path_completed', int $certificate_id, int $user_id, int $path_id ).
			'hook'              => 'learnomy_pro_learning_path_completed',
			'user_callback'     => function ( int $certificate_id, int $user_id, int $path_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $certificate_id, int $user_id, int $path_id ): array {
				return array( 'path_id' => $path_id );
			},
			'permanent'         => 'A completion.',
			'default_points'    => 150,
			'category'          => 'learning',
			'icon'              => 'icon-route',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'learnomy_pro_path_enrolled',
			'label'             => static fn(): string => __( 'Enrol in a learning path', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member enrols in a learning path. Daily cap prevents farming.', 'wb-gamification' ),
			// Pro fires: do_action( 'learnomy_pro_learning_path_enrolled', int $enrollment_id, int $user_id, int $path_id, string $source ).
			'hook'              => 'learnomy_pro_learning_path_enrolled',
			'user_callback'     => function ( int $enrollment_id, int $user_id, int $path_id, string $source = '' ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $enrollment_id, int $user_id, int $path_id, string $source = '' ): array {
				return array(
					'path_id' => $path_id,
					'source'  => $source,
				);
			},
			'permanent'         => 'No path-specific unenrol action in Learnomy Pro 2.3: a path unenrol fires learnomy_student_unenrolled with course 0 (card on the Learnomy board).',
			'default_points'    => 5,
			'category'          => 'learning',
			'icon'              => 'icon-log-in',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'learnomy_pro_assignment_submitted',
			'label'             => static fn(): string => __( 'Submit an assignment', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member submits an assignment for grading.', 'wb-gamification' ),
			// Pro fires: do_action( 'learnomy_pro_assignment_submitted', int $id, int $assignment_id, int $user_id ).
			'hook'              => 'learnomy_pro_assignment_submitted',
			'user_callback'     => function ( int $id, int $assignment_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $id, int $assignment_id, int $user_id ): array {
				return array( 'assignment_id' => $assignment_id );
			},
			'permanent'         => 'A submission. Learnomy Pro fires nothing when a submission is removed.',
			'default_points'    => 15,
			'category'          => 'learning',
			'icon'              => 'icon-clipboard-check',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'learnomy_pro_cohort_joined',
			'label'             => static fn(): string => __( 'Join a cohort', 'wb-gamification' ),
			'description'       => static fn(): string => __( 'Awarded when a member is added to a learning cohort.', 'wb-gamification' ),
			// Pro fires: do_action( 'learnomy_pro_cohort_member_added', int $cohort_id, int $user_id ).
			'hook'              => 'learnomy_pro_cohort_member_added',
			'user_callback'     => function ( int $cohort_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $cohort_id, int $user_id ): array {
				return array( 'cohort_id' => $cohort_id );
			},
			'once_per_object'   => static fn( int $cohort_id, int $user_id ): int => \WBGam\Engine\Registry::object_key( $user_id, 'cohort', $cohort_id ),
			'reverse_on'        => array(
				'learnomy_pro_cohort_member_removed' => static fn( $cohort_id, $user_id ): int => \WBGam\Engine\Registry::object_key( (int) $user_id, 'cohort', (int) $cohort_id ),
			),
			'default_points'    => 5,
			'category'          => 'learning',
			'icon'              => 'icon-users',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'             => 'learnomy_pro_gift_redeemed',
			'label'          => static fn(): string => __( 'Redeem a course gift', 'wb-gamification' ),
			'description'    => static fn(): string => __( 'Awarded when a member redeems a gifted course or membership code.', 'wb-gamification' ),
			// Pro fires: do_action( 'learnomy_pro_gift_redeemed', string $code, int $user_id, int $sub_id ).
			'hook'           => 'learnomy_pro_gift_redeemed',
			'user_callback'  => function ( string $code, int $user_id, int $sub_id ): int {
				return $user_id;
			},
			'permanent'      => 'A redeemed gift code cannot be un-redeemed.',
			'default_points' => 5,
			'category'       => 'learning',
			'icon'           => 'icon-gift',
			'repeatable'     => true,
			'async'          => false,
		),

	),
);
