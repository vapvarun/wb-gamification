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
			'label'             => 'Complete a learning path',
			'description'       => 'Awarded when a member completes a full learning path.',
			// Pro fires: do_action( 'learnomy_pro_learning_path_completed', int $certificate_id, int $user_id, int $path_id ).
			'hook'              => 'learnomy_pro_learning_path_completed',
			'user_callback'     => function ( int $certificate_id, int $user_id, int $path_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $certificate_id, int $user_id, int $path_id ): array {
				return array( 'path_id' => $path_id );
			},
			'default_points'    => 150,
			'category'          => 'learning',
			'icon'              => 'icon-route',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'                => 'learnomy_pro_path_enrolled',
			'label'             => 'Enrol in a learning path',
			'description'       => 'Awarded when a member enrols in a learning path. Daily cap prevents farming.',
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
			'default_points'    => 5,
			'category'          => 'learning',
			'icon'              => 'icon-log-in',
			'repeatable'        => true,
			'async'             => false,
			'daily_cap'         => 5,
		),

		array(
			'id'                => 'learnomy_pro_assignment_submitted',
			'label'             => 'Submit an assignment',
			'description'       => 'Awarded when a member submits an assignment for grading.',
			// Pro fires: do_action( 'learnomy_pro_assignment_submitted', int $id, int $assignment_id, int $user_id ).
			'hook'              => 'learnomy_pro_assignment_submitted',
			'user_callback'     => function ( int $id, int $assignment_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $id, int $assignment_id, int $user_id ): array {
				return array( 'assignment_id' => $assignment_id );
			},
			'default_points'    => 15,
			'category'          => 'learning',
			'icon'              => 'icon-clipboard-check',
			'repeatable'        => true,
			'async'             => false,
			'cooldown'          => 60,
		),

		array(
			'id'                => 'learnomy_pro_cohort_joined',
			'label'             => 'Join a cohort',
			'description'       => 'Awarded when a member is added to a learning cohort.',
			// Pro fires: do_action( 'learnomy_pro_cohort_member_added', int $cohort_id, int $user_id ).
			'hook'              => 'learnomy_pro_cohort_member_added',
			'user_callback'     => function ( int $cohort_id, int $user_id ): int {
				return $user_id;
			},
			'metadata_callback' => function ( int $cohort_id, int $user_id ): array {
				return array( 'cohort_id' => $cohort_id );
			},
			'default_points'    => 5,
			'category'          => 'learning',
			'icon'              => 'icon-users',
			'repeatable'        => true,
			'async'             => false,
		),

		array(
			'id'             => 'learnomy_pro_gift_redeemed',
			'label'          => 'Redeem a course gift',
			'description'    => 'Awarded when a member redeems a gifted course or membership code.',
			// Pro fires: do_action( 'learnomy_pro_gift_redeemed', string $code, int $user_id, int $sub_id ).
			'hook'           => 'learnomy_pro_gift_redeemed',
			'user_callback'  => function ( string $code, int $user_id, int $sub_id ): int {
				return $user_id;
			},
			'default_points' => 5,
			'category'       => 'learning',
			'icon'           => 'icon-gift',
			'repeatable'     => true,
			'async'          => false,
		),

	),
);
